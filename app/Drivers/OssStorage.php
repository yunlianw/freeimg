<?php
namespace App\Drivers;

use App\Core\Db;

/**
 * 阿里云 OSS（v1 签名）
 *
 * 文档：https://help.aliyun.com/document_detail/31951.html
 *
 * config 字段：
 *   - endpoint   如 oss-cn-hangzhou.aliyuncs.com
 *   - region     如 cn-hangzhou（OSS 桶级别）
 *   - bucket     桶名
 *   - access_key LTAI...
 *   - secret_key ...
 *   - scheme     https 或 http（默认 https）
 *   - public_url 自定义 CDN 域名（可选）
 */
class OssStorage implements StorageDriverInterface
{
    private string $endpoint;
    private string $region;
    private string $bucket;
    private string $accessKey;
    private string $secretKey;
    private string $scheme;
    private ?string $publicUrl;

    public function __construct(array $config)
    {
        foreach (['endpoint', 'region', 'bucket', 'access_key', 'secret_key'] as $k) {
            if (empty($config[$k])) {
                throw new \InvalidArgumentException("OssStorage 缺少配置: $k");
            }
        }
        $this->endpoint  = rtrim($config['endpoint'], '/');
        $this->region    = $config['region'];
        $this->bucket    = $config['bucket'];
        $this->accessKey = $config['access_key'];
        $this->secretKey = $config['secret_key'];
        $this->scheme    = $config['scheme'] ?? 'https';
        $this->publicUrl = isset($config['public_url']) ? rtrim($config['public_url'], '/') : null;
    }

    public function driverName(): string
    {
        return 'oss';
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
        // OSS 公开 URL：{bucket}.{endpoint} 或 {bucket}.{region}.{endpoint-without-region}
        return $this->scheme . '://' . $this->bucket . '.' . $this->endpoint . '/' . ltrim($path, '/');
    }

    public function testConnection(): bool
    {
        $headers = $this->signedHeaders('GET', '');
        $url = $this->bucketUrl('');
        $resp = $this->httpRequest('GET', $url, $headers);
        return $resp !== null && ($resp['code'] === 200 || $resp['code'] === 404);
    }

    public function usage(): int
    {
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

    // ================== 内部：OSS v1 签名 ==================

    private function objectUrl(string $path): string
    {
        return $this->bucketUrl(ltrim($path, '/'));
    }

    private function bucketUrl(string $path): string
    {
        return $this->scheme . '://' . $this->bucket . '.' . $this->endpoint . '/' . ltrim($path, '/');
    }

    /**
     * OSS v1 签名（HMAC-SHA1）
     * 文档：https://help.aliyun.com/document_detail/31951.html
     */
    private function signedHeaders(string $method, string $path, string $body = ''): array
    {
        $now = time();
        $date = gmdate('D, d M Y H:i:s', $now) . ' GMT';

        $host = $this->bucket . '.' . $this->endpoint;
        $contentMd5 = md5($body);
        $contentType = ''; // OSS 支持任意 Content-Type

        // CanonicalizedOSSHeaders
        $canonicalizedHeaders = '';
        // CanonicalizedResource
        $canonicalizedResource = '/' . $this->bucket . '/' . ltrim($path, '/');

        // 处理 query string（用于 GET 用 ?max-keys=）
        if (isset($path[0]) && $path[0] === '?') {
            $canonicalizedResource = '/';
            parse_str(substr($path, 1), $params);
            if ($params) {
                ksort($params);
                $qs = http_build_query($params);
                if ($qs !== '') $canonicalizedResource .= '?' . $qs;
            }
        }

        // StringToSign
        $stringToSign = implode("\n", [
            strtoupper($method),
            $contentMd5,
            $contentType,
            $date,
            $canonicalizedHeaders,
            $canonicalizedResource,
        ]);

        // 签名
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->secretKey, true));

        $authorization = sprintf('OSS %s:%s', $this->accessKey, $signature);

        $httpHeaders = [
            'Authorization: ' . $authorization,
            'Host: ' . $host,
            'Date: ' . $date,
        ];
        if ($contentMd5) {
            $httpHeaders[] = 'Content-MD5: ' . $contentMd5;
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
            error_log('[OssStorage] curl error: ' . curl_error($ch));
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
