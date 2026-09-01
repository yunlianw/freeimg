<?php
namespace App\Drivers;

/**
 * 华为云 OBS 驱动（OBS 自定义签名 v2 · HMAC-SHA1）
 *
 * 文档：https://support.huaweicloud.com/api-obs/obs_04_0010.html
 *
 * 注意：华为云 OBS 的签名机制与 AWS S3 SigV4 不同（虽然 URL 风格相似）。
 * 本驱动实现 OBS 原生签名：
 *   - StringToSign = HTTP-Verb\nContent-MD5\nContent-Type\nDate\nCanonicalizedHeaders\nCanonicalizedResource
 *   - Signature = Base64(HMAC-SHA1(SK, StringToSign))
 *   - Authorization: OBS {AK}:{Signature}
 *
 * config 字段：
 *   - endpoint    如 obs.cn-north-4.myhuaweicloud.com
 *   - region      如 cn-north-4
 *   - bucket      桶名（如 example）
 *   - access_key  AK
 *   - secret_key  SK
 *   - public_url  自定义 CDN 域名（可选）
 */
class ObsStorage implements StorageDriverInterface
{
    private string $endpoint;
    private string $region;
    private string $bucket;
    private string $accessKey;
    private string $secretKey;
    private ?string $publicUrl;
    private ?array $lastResponse = null;

    public function __construct(array $config)
    {
        foreach (['endpoint', 'region', 'bucket', 'access_key', 'secret_key'] as $k) {
            if (empty($config[$k])) {
                throw new \InvalidArgumentException("ObsStorage 缺少配置: $k");
            }
        }
        // 智能解析 endpoint（用户可能填各种格式）
        // 1. 去掉协议头
        $rawEndpoint = preg_replace('#^https?://#i', '', $config['endpoint']);
        // 2. 去掉尾部斜杠
        $rawEndpoint = rtrim($rawEndpoint, '/');
        // 3. 去掉路径部分
        $rawEndpoint = preg_replace('#/.*$#', '', $rawEndpoint);
        $this->endpoint  = $rawEndpoint;
        $this->region    = $config['region'];
        $this->bucket    = $config['bucket'];
        $this->accessKey = $config['access_key'];
        $this->secretKey = $config['secret_key'];
        $this->publicUrl = isset($config['public_url']) ? rtrim($config['public_url'], '/') : null;
    }

    public function driverName(): string
    {
        return 'obs';
    }

    public function put(string $path, string $content): bool
    {
        $url  = $this->objectUrl($path);
        $headers = $this->sign('PUT', $url, $content, [
            'Content-Type' => 'application/octet-stream',
        ]);
        $resp = $this->httpRequest('PUT', $url, $headers, $content);
        return $resp !== null && ($resp['code'] === 200 || $resp['code'] === 204);
    }

    public function get(string $path): ?string
    {
        $url  = $this->objectUrl($path);
        $headers = $this->sign('GET', $url);
        $resp = $this->httpRequest('GET', $url, $headers);
        if ($resp === null || $resp['code'] !== 200) return null;
        return $resp['body'];
    }

    public function delete(string $path): bool
    {
        $url  = $this->objectUrl($path);
        $headers = $this->sign('DELETE', $url);
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
        $url  = $this->objectUrl($path);
        $headers = $this->sign('HEAD', $url);
        $resp = $this->httpRequest('HEAD', $url, $headers);
        return $resp !== null && $resp['code'] === 200;
    }

    public function stat(string $path): ?array
    {
        $url  = $this->objectUrl($path);
        $headers = $this->sign('HEAD', $url);
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
        // 公有读桶公开访问：https://{bucket}.{endpoint}/{path}
        return 'https://' . $this->bucket . '.' . $this->endpoint . '/' . ltrim($path, '/');
    }

    public function testConnection(): bool
    {
        // 用 HEAD 探测桶根（公有读返回 200，私有返回 403，都算连通）
        $url = $this->bucketUrl();
        $headers = $this->sign('HEAD', $url);
        $resp = $this->httpRequest('HEAD', $url, $headers);
        if ($resp === null) return false;
        if (in_array($resp['code'], [200, 204, 403, 404], true)) return true;
        error_log('[ObsStorage::testConnection] HTTP ' . $resp['code'] . ' URL=' . $url . ' body=' . substr($resp['body'] ?? '', 0, 200));
        return false;
    }

    public function getLastResponse(): ?array
    {
        return $this->lastResponse ?? null;
    }

    public function usage(): int
    {
        // ListBucket（max-keys=1000），私有桶返回 403 时 usage = 0
        $url = $this->bucketUrl() . '?max-keys=1000';
        $headers = $this->sign('GET', $url);
        $resp = $this->httpRequest('GET', $url, $headers);
        if ($resp === null || $resp['code'] !== 200) return 0;
        $total = 0;
        if (preg_match_all('/<Size>(\d+)<\/Size>/', $resp['body'], $m)) {
            foreach ($m[1] as $size) $total += (int)$size;
        }
        return $total;
    }

    // ================== URL ==================

    private function objectUrl(string $path): string
    {
        return $this->bucketUrl() . '/' . ltrim($path, '/');
    }

    private function bucketUrl(): string
    {
        return 'https://' . $this->bucket . '.' . $this->endpoint;
    }

    // ================== OBS v2 签名（HMAC-SHA1）====================

    /**
     * @param string $method       HTTP 方法
     * @param string $url          完整请求 URL（与 httpRequest 实际发出的完全一致）
     * @param string $body         请求体（PUT/POST 时有内容）
     * @param array  $extraHeaders 额外 header（如 Content-Type）
     * @return array               可直接传给 curl 的 header 列表
     */
    private function sign(string $method, string $url, string $body = '', array $extraHeaders = []): array
    {
        $parsed = parse_url($url);
        $host   = $parsed['host']  ?? '';
        $port   = $parsed['port']  ?? null;
        $path   = $parsed['path']  ?? '/';
        $query  = $parsed['query'] ?? '';

        $hostWithPort = $host . ($port ? ':' . $port : '');

        // 1) 收集 OBS 自定义 header（x-obs-*）和 content-md5/content-type/date
        $contentMd5   = '';
        $contentType  = $extraHeaders['Content-Type'] ?? '';
        $date         = gmdate('D, d M Y H:i:s \G\M\T'); // HTTP 标准日期格式（OBS 强制要求）

        $canonicalizedHeaders = [];
        foreach ($extraHeaders as $name => $value) {
            $lname = strtolower($name);
            if (str_starts_with($lname, 'x-obs-')) {
                $canonicalizedHeaders[$lname] = trim((string)$value);
            }
        }
        ksort($canonicalizedHeaders);

        // 2) 构造 CanonicalizedResource = /{bucket}/{object}?{subResources}
        $subResources = ['acl', 'append', 'attname', 'cors', 'customdomain', 'delete', 'deletebucket',
            'directcoldaccess', 'encryption', 'inventory', 'length', 'lifecycle', 'location', 'logging',
            'metadata', 'mirrorBackToSource', 'modify', 'name', 'notification', 'obscompresspolicy',
            'orchestration', 'partNumber', 'policy', 'position', 'quota', 'rename', 'replication',
            'response-cache-control', 'response-content-disposition', 'response-content-encoding',
            'response-content-language', 'response-content-type', 'response-expires', 'restore',
            'storageClass', 'storagePolicy', 'storageinfo', 'tagging', 'torrent', 'truncate',
            'uploadId', 'uploads', 'versionId', 'versioning', 'versions', 'website',
            'x-image-process', 'x-image-save-bucket', 'x-image-save-object'];
        $canonicalizedResource = '/' . $this->bucket . ($path !== '' ? $path : '/');
        if ($query !== '') {
            parse_str($query, $q);
            ksort($q);
            $parts = [];
            foreach ($q as $k => $v) {
                if (in_array((string)$k, $subResources, true)) {
                    $parts[] = rawurlencode((string)$k) . ($v !== '' ? '=' . rawurlencode((string)$v) : '');
                }
            }
            if (!empty($parts)) {
                $canonicalizedResource .= '?' . implode('&', $parts);
            }
        }

        // 3) 构造 StringToSign
        $stringToSign = $method . "\n"
                      . $contentMd5 . "\n"
                      . $contentType . "\n"
                      . $date . "\n";
        foreach ($canonicalizedHeaders as $k => $v) {
            $stringToSign .= $k . ':' . $v . "\n";
        }
        $stringToSign .= $canonicalizedResource;

        // 4) 计算 Signature = Base64(HMAC-SHA1(SK, StringToSign))
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->secretKey, true));

        // 5) Authorization header
        $authorization = 'OBS ' . $this->accessKey . ':' . $signature;

        $httpHeaders = [
            'Authorization: ' . $authorization,
            'Host: ' . $hostWithPort,
            'Date: ' . $date,
        ];
        if ($contentType) {
            $httpHeaders[] = 'Content-Type: ' . $contentType;
        }

        return $httpHeaders;
    }

    // ================== HTTP ==================

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
            error_log('[ObsStorage] curl error: ' . curl_error($ch));
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

        $result = ['code' => $code, 'headers' => $parsedHeaders, 'body' => $respBody];
        // 保存最后一次响应（让 testConnection 失败时能给前端精确诊断）
        $this->lastResponse = $result;
        return $result;
    }
}