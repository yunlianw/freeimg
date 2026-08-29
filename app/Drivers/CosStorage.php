<?php
namespace App\Drivers;

use App\Core\Db;

/**
 * 腾讯云 COS XML API（与 S3 签名有差异）
 *
 * 文档：https://cloud.tencent.com/document/product/436/7773
 *
 * config 字段：
 *   - endpoint   如 https://cos.ap-guangzhou.myqcloud.com
 *   - region     如 ap-guangzhou
 *   - bucket     桶名（如 example-1250000000）
 *   - secret_id  AKID...
 *   - secret_key ...
 *   - scheme     https 或 http（默认 https）
 *   - public_url 自定义 CDN 域名（可选）
 */
class CosStorage implements StorageDriverInterface
{
    private string $endpoint;
    private string $region;
    private string $bucket;
    private string $secretId;
    private string $secretKey;
    private string $scheme;
    private ?string $publicUrl;

    public function __construct(array $config)
    {
        foreach (['endpoint', 'region', 'bucket', 'secret_id', 'secret_key'] as $k) {
            if (empty($config[$k])) {
                throw new \InvalidArgumentException("CosStorage 缺少配置: $k");
            }
        }
        $this->endpoint  = rtrim($config['endpoint'], '/');
        $this->region    = $config['region'];
        $this->bucket    = $config['bucket'];
        $this->secretId  = $config['secret_id'];
        $this->secretKey = $config['secret_key'];
        $this->scheme    = $config['scheme'] ?? 'https';
        $this->publicUrl = isset($config['public_url']) ? rtrim($config['public_url'], '/') : null;
    }

    public function driverName(): string
    {
        return 'cos';
    }

    public function put(string $path, string $content): bool
    {
        $url = $this->objectUrl($path);
        $headers = $this->signedHeaders('PUT', $path, $content);
        $resp = $this->httpRequest('PUT', $url, $headers, $content);
        return $resp !== null && ($resp['code'] === 200 || $resp['code'] === 204);
    }

    public function get(string $path): ?string
    {
        $url = $this->objectUrl($path);
        $headers = $this->signedHeaders('GET', $path);
        $resp = $this->httpRequest('GET', $url, $headers);
        if ($resp === null || $resp['code'] !== 200) return null;
        return $resp['body'];
    }

    public function delete(string $path): bool
    {
        $url = $this->objectUrl($path);
        $headers = $this->signedHeaders('DELETE', $path);
        $resp = $this->httpRequest('DELETE', $url, $headers);
        return $resp !== null && ($resp['code'] === 200 || $resp['code'] === 204 || $resp['code'] === 404);
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
        $headers = $this->signedHeaders('HEAD', $path);
        $resp = $this->httpRequest('HEAD', $url, $headers);
        return $resp !== null && $resp['code'] === 200;
    }

    public function stat(string $path): ?array
    {
        $url = $this->objectUrl($path);
        $headers = $this->signedHeaders('HEAD', $path);
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
        if ($this->publicUrl) return $this->publicUrl . '/' . ltrim($path, '/');
        // COS 公开访问 URL：{bucket}-{appid}.cos.{region}.myqcloud.com/{path}
        $host = str_replace('{bucket}', $this->bucket, $this->endpoint);
        return $host . '/' . ltrim($path, '/');
    }

    public function testConnection(): bool
    {
        // GET / → 200/404 都可以
        $headers = $this->signedHeaders('HEAD', '');
        $url = $this->bucketUrl('');
        $resp = $this->httpRequest('HEAD', $url, $headers);
        return $resp !== null && ($resp['code'] === 200 || $resp['code'] === 404);
    }

    public function usage(): int
    {
        // 极简 GET / 列出
        $headers = $this->signedHeaders('GET', '?max-keys=1000');
        $url = $this->bucketUrl('?max-keys=1000');
        $resp = $this->httpRequest('GET', $url, $headers);
        if ($resp === null || $resp['code'] !== 200) return 0;
        $total = 0;
        if (preg_match_all('/<Size>(\d+)<\/Size>/', $resp['body'], $m)) {
            foreach ($m[1] as $size) $total += (int)$size;
        }
        return $total;
    }

    // ================== 内部：COS XML 签名 ==================

    private function objectUrl(string $path): string
    {
        return $this->bucketUrl(ltrim($path, '/'));
    }

    private function bucketUrl(string $path): string
    {
        return $this->scheme . '://' . $this->endpoint . '/' . ltrim($path, '/');
    }

    /**
     * COS XML 签名（TC3-HMAC-SHA256 算法）
     * 文档：https://cloud.tencent.com/document/product/436/7778
     */
    private function signedHeaders(string $method, string $path, string $body = ''): array
    {
        $now = time();
        $dateShort = gmdate('Y-m-d', $now);
        $dateTime = gmdate('Y-m-d\TH:i:s\Z', $now);

        $host = parse_url($this->endpoint, PHP_URL_HOST);

        // URI: path 已是相对路径，前面带 /
        $canonicalUri = '/' . ltrim($path, '/');
        if ($path === '' || (isset($path[0]) && $path[0] === '?')) {
            $canonicalUri = '/';
        }

        // Canonical query string（按字母排序）
        $canonicalQuery = '';
        if (isset($path[0]) && $path[0] === '?') {
            parse_str(substr($path, 1), $params);
            ksort($params);
            $canonicalQuery = http_build_query($params);
        }

        // Canonical headers（按小写字母排序）
        $contentSha256 = hash('sha256', $body);
        $headersArr = [
            'host'                  => $host,
            'x-amz-content-sha256'  => $contentSha256,
            'x-amz-date'            => $dateTime,
        ];
        ksort($headersArr);
        $canonicalHeaders = '';
        $signedHeadersList = '';
        foreach ($headersArr as $k => $v) {
            $canonicalHeaders .= $k . ':' . trim($v) . "\n";
            $signedHeadersList .= $k . ';';
        }
        $signedHeadersList = rtrim($signedHeadersList, ';');

        // Canonical request
        $canonicalRequest = implode("\n", [
            $method,
            $canonicalUri,
            $canonicalQuery,
            $canonicalHeaders,
            $signedHeadersList,
            $contentSha256,
        ]);

        // String to sign (TC3)
        $credentialScope = "{$dateShort}/cos/{$this->region}/tc3-request";
        $stringToSign = implode("\n", [
            'TC3-HMAC-SHA256',
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        // 计算签名（TC3 派生）
        $secretDate = hash_hmac('sha256', $dateShort, 'TC3' . $this->secretKey, true);
        $secretService = hash_hmac('sha256', "cos", $secretDate, true);
        $secretRegion = hash_hmac('sha256', $this->region, $secretService, true);
        $signature = hash_hmac('sha256', $stringToSign, $secretRegion);

        $authorization = sprintf(
            'TC3-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $this->secretId,
            $credentialScope,
            $signedHeadersList,
            $signature
        );

        $httpHeaders = [
            'Authorization: ' . $authorization,
            'Host: ' . $host,
            'x-amz-content-sha256: ' . $contentSha256,
            'x-amz-date: ' . $dateTime,
        ];

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
            error_log('[CosStorage] curl error: ' . curl_error($ch));
            return null;
        }

        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $respHeaders = substr($resp, 0, $headerSize);
        $respBody = $method === 'HEAD' ? '' : substr($resp, $headerSize);

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
