<?php
namespace App\Drivers;

/**
 * S3 兼容对象存储驱动
 * 兼容：Cloudflare R2、AWS S3、Backblaze B2、Wasabi、MinIO
 * 实现 AWS Signature V4（不依赖 composer/aws-sdk）
 */
class S3Storage implements StorageDriverInterface
{
    private string $endpoint;
    private string $region;
    private string $bucket;
    private string $accessKey;
    private string $secretKey;
    private string $pathStyle;
    private ?string $publicUrl;

    public function __construct(array $config)
    {
        foreach (['endpoint', 'region', 'bucket', 'access_key', 'secret_key'] as $k) {
            if (empty($config[$k])) {
                throw new \InvalidArgumentException("S3Storage 缺少配置: $k");
            }
        }
        $this->endpoint   = rtrim($config['endpoint'], '/');
        $this->region     = $config['region'];
        $this->bucket     = $config['bucket'];
        $this->accessKey  = $config['access_key'];
        $this->secretKey  = $config['secret_key'];
        $this->pathStyle  = $config['path_style'] ?? 'path';
        $this->publicUrl  = isset($config['public_url']) ? rtrim($config['public_url'], '/') : null;
    }

    public function driverName(): string
    {
        return 's3';
    }

    public function put(string $path, string $content): bool
    {
        $url = $this->objectUrl($path);
        $headers = $this->signedHeaders('PUT', $url, $content, [
            'Content-Type' => 'application/octet-stream',
        ]);
        $resp = $this->httpRequest('PUT', $url, $headers, $content);
        return $resp !== null && ($resp['code'] === 200 || $resp['code'] === 204);
    }

    public function get(string $path): ?string
    {
        $url = $this->objectUrl($path);
        $headers = $this->signedHeaders('GET', $url);
        $resp = $this->httpRequest('GET', $url, $headers);
        if ($resp === null || $resp['code'] !== 200) return null;
        return $resp['body'];
    }

    public function delete(string $path): bool
    {
        $url = $this->objectUrl($path);
        $headers = $this->signedHeaders('DELETE', $url);
        $resp = $this->httpRequest('DELETE', $url, $headers);
        return $resp !== null && ($resp['code'] === 200 || $resp['code'] === 204);
    }

    public function move(string $from, string $to): bool
    {
        $content = $this->get($from);
        if ($content === null) return false;
        if (!$this->put($to, $content)) return false;
        $this->delete($from);
        return true;
    }

    public function copy(string $from, string $to): bool
    {
        $content = $this->get($from);
        if ($content === null) return false;
        return $this->put($to, $content);
    }

    public function exists(string $path): bool
    {
        $url = $this->objectUrl($path);
        $headers = $this->signedHeaders('HEAD', $url);
        $resp = $this->httpRequest('HEAD', $url, $headers);
        return $resp !== null && $resp['code'] === 200;
    }

    public function stat(string $path): ?array
    {
        $url = $this->objectUrl($path);
        $headers = $this->signedHeaders('HEAD', $url);
        $resp = $this->httpRequest('HEAD', $url, $headers);
        if ($resp === null || $resp['code'] !== 200) return null;
        return [
            'size'  => (int)($resp['headers']['content-length'][0] ?? 0),
            'mtime' => strtotime($resp['headers']['last-modified'][0] ?? 'now'),
            'mime'  => $resp['headers']['content-type'][0] ?? 'application/octet-stream',
        ];
    }

    public function url(string $path): string
    {
        if ($this->publicUrl) {
            return $this->publicUrl . '/' . ltrim($path, '/');
        }
        $base = rtrim($this->endpoint, '/');
        if ($this->pathStyle === 'virtual_hosted') {
            return str_replace('{bucket}', $this->bucket, $base) . '/' . ltrim($path, '/');
        }
        return $base . '/' . $this->bucket . '/' . ltrim($path, '/');
    }

    public function testConnection(): bool
    {
        // ListObjectsV2（所有 S3 兼容服务都支持），max-keys=1 最小开销
        $url = $this->bucketUrl('?list-type=2&max-keys=1');
        $headers = $this->signedHeaders('GET', $url);
        $resp = $this->httpRequest('GET', $url, $headers);
        return $resp !== null && ($resp['code'] === 200 || $resp['code'] === 404);
    }

    public function usage(): int
    {
        $url = $this->bucketUrl('?list-type=2&max-keys=1000');
        $headers = $this->signedHeaders('GET', $url);
        $resp = $this->httpRequest('GET', $url, $headers);
        if ($resp === null || $resp['code'] !== 200) return 0;
        $total = 0;
        if (preg_match_all('/<Size>(\d+)<\/Size>/', $resp['body'], $m)) {
            foreach ($m[1] as $size) $total += (int)$size;
        }
        return $total;
    }

    private function objectUrl(string $path): string
    {
        return $this->bucketUrl(ltrim($path, '/'));
    }

    private function bucketUrl(string $path): string
    {
        if ($this->pathStyle === 'virtual_hosted') {
            $host = str_replace('{bucket}', $this->bucket, $this->endpoint);
        } else {
            $host = $this->endpoint;
        }
        $base = rtrim($host, '/');
        $bucketPart = ($this->pathStyle === 'path') ? '/' . $this->bucket : '';

        // 拆分 query string（query 不参与路径编码）
        $query = '';
        $keyPath = $path;
        if ($path !== '' && $path[0] === '?') {
            $query = $path;
            $keyPath = '';
        }
        // 请求路径每个 segment 做 RFC3986 编码（与 canonical URI 完全一致，
        // 避免 curl 把百分号 hex 转小写导致签名比对不一致）
        $encoded = '';
        if ($keyPath !== '') {
            $encoded = '/' . implode('/', array_map('rawurlencode', explode('/', trim($keyPath, '/'))));
        }
        // bucket 根操作（list）不加尾斜杠，与 canonical URI 保持一致
        return $base . $bucketPart . $encoded . $query;
    }

    /**
     * AWS Signature V4 签名
     * @param string $method HTTP 方法
     * @param string $url    完整请求 URL（与 httpRequest 实际发出的完全一致）
     */
    private function signedHeaders(string $method, string $url, string $body = '', array $extraHeaders = []): array
    {
        $now = gmdate('Ymd\THis\Z');
        $dateShort = substr($now, 0, 8);

        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);
        $rawPath = parse_url($url, PHP_URL_PATH) ?? '/';
        $rawQuery = parse_url($url, PHP_URL_QUERY) ?? '';

        // canonical URI = 实际请求路径（bucketUrl 已做 segment 编码，这里直接用）
        $canonicalUri = $rawPath !== '' ? $rawPath : '/';

        // canonical query：key/value 各编码一次后按 key 排序
        $canonicalQuery = '';
        if ($rawQuery !== '') {
            $pairs = [];
            foreach (explode('&', $rawQuery) as $kv) {
                if ($kv === '') continue;
                [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
                $pairs[rawurlencode($k)] = rawurlencode($v);
            }
            ksort($pairs);
            $q = [];
            foreach ($pairs as $k => $v) $q[] = $k . '=' . $v;
            $canonicalQuery = implode('&', $q);
        }

        $contentType = $extraHeaders['Content-Type'] ?? '';
        $contentSha256 = hash('sha256', $body);

        $headersArr = [
            'host'                  => $host . ($port ? ':' . $port : ''),
            'x-amz-content-sha256'  => $contentSha256,
            'x-amz-date'            => $now,
        ];
        if ($method === 'PUT' && $contentType) {
            $headersArr['content-type'] = $contentType;
        }

        ksort($headersArr);
        $canonicalHeaders = '';
        $signedHeadersList = '';
        foreach ($headersArr as $k => $v) {
            $canonicalHeaders .= $k . ':' . trim($v) . "\n";
            $signedHeadersList .= $k . ';';
        }
        $signedHeadersList = rtrim($signedHeadersList, ';');

        $canonicalRequest = implode("\n", [
            $method,
            $canonicalUri,
            $canonicalQuery,
            $canonicalHeaders,
            $signedHeadersList,
            $contentSha256,
        ]);

        $credentialScope = $dateShort . '/' . $this->region . '/s3/aws4_request';
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $now,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $kDate = hash_hmac('sha256', $dateShort, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorization = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $this->accessKey,
            $credentialScope,
            $signedHeadersList,
            $signature
        );

        $httpHeaders = [
            'Authorization: ' . $authorization,
            'x-amz-content-sha256: ' . $contentSha256,
            'x-amz-date: ' . $now,
            'Host: ' . $host . ($port ? ':' . $port : ''),
        ];
        if ($contentType) {
            $httpHeaders[] = 'Content-Type: ' . $contentType;
        }

        return $httpHeaders;
    }

    private function httpRequest(string $method, string $url, array $headers, string $body = ''): ?array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if ($method === 'PUT' || $method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        } elseif ($method === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        }

        $resp = curl_exec($ch);
        if ($resp === false) {
            error_log('[S3Storage] curl error: ' . curl_error($ch));
            // curl_close() 在 PHP 8.0+ 无作用且 8.5 已废弃，句柄自动释放
            return null;
        }

        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $respHeaders = substr($resp, 0, $headerSize);
        $respBody = $method === 'HEAD' ? '' : substr($resp, $headerSize);
        // curl_close() 在 PHP 8.0+ 无作用且 8.5 已废弃，句柄自动释放

        $parsedHeaders = [];
        foreach (explode("\r\n", $respHeaders) as $line) {
            if (strpos($line, ':') !== false) {
                list($k, $v) = explode(':', $line, 2);
                $parsedHeaders[strtolower(trim($k))][] = trim($v);
            }
        }

        return ['code' => $code, 'headers' => $parsedHeaders, 'body' => $respBody];
    }
}
