<?php
namespace App\Drivers;

use App\Core\Db;

/**
 * 腾讯云 COS XML API（q-sign-algorithm=sha1 官方签名）
 *
 * 文档：https://cloud.tencent.com/document/product/436/7778
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
    private ?array $lastResponse = null;

    public function __construct(array $config)
    {
        foreach (['endpoint', 'region', 'bucket', 'secret_id', 'secret_key'] as $k) {
            if (empty($config[$k])) {
                throw new \InvalidArgumentException("CosStorage 缺少配置: $k");
            }
        }
        // 智能解析 endpoint（用户可能填各种格式）
        // 1. 去掉协议头
        $rawEndpoint = preg_replace('#^https?://#i', '', $config['endpoint']);
        // 2. 去掉尾部斜杠
        $rawEndpoint = rtrim($rawEndpoint, '/');
        // 3. 去掉路径部分（如果有 /storage 之类）
        $rawEndpoint = preg_replace('#/.*$#', '', $rawEndpoint);
        // 4. 自动从 endpoint 提取 bucket（如果 endpoint 已含 bucket.cos.region.myqcloud.com）
        //    COS 桶域名格式: {bucket}-{appid}.cos.{region}.myqcloud.com
        //    所以 endpoint 第一段 = bucket（因为 bucket 已含 -appid）
        $bucket = $config['bucket'];
        $parts = explode('.', $rawEndpoint);
        $firstPart = $parts[0] ?? '';
        // 判定：endpoint 第一段等于 bucket（含 -appid 后缀的完整桶名）
        if ($firstPart === $bucket) {
            // endpoint 已包含 bucket 前缀：去掉 endpoint 中重复的 bucket 段
            // 例如：endpoint="ceshi-1345376568.cos.ap-beijing-1.myqcloud.com", bucket="ceshi-1345376568"
            // → 规范化 endpoint="cos.ap-beijing-1.myqcloud.com"
            array_shift($parts);
            $rawEndpoint = implode('.', $parts);
        }
        $this->endpoint  = $rawEndpoint;
        $this->region    = $config['region'];
        $this->bucket    = $config['bucket'];
        $this->secretId  = $config['secret_id'];
        $this->secretKey = $config['secret_key'];
        $this->scheme    = $config['scheme'] ?? 'https';
        $this->publicUrl = isset($config['public_url']) ? rtrim($config['public_url'], '/') : null;
    }

    /**
     * 智能判断：是否需要在 endpoint 中拼 bucket
     * 真实 COS 桶域名格式: {bucket}-{appid}.cos.{region}.myqcloud.com
     * 当 endpoint 形如 cos.{region}.myqcloud.com（无 bucket）→ 需要拼
     * 当 endpoint 形如 {bucket}-{appid}.cos.{region}.myqcloud.com（已含 bucket）→ 直接用
     */
    private function buildHost(): string
    {
        $host = $this->endpoint;
        // 如果 endpoint 已经包含了桶名前缀（如 "ceshi-1345376568.cos.ap-beijing-1.myqcloud.com"），直接用
        if (str_starts_with($host, $this->bucket . '.') || str_starts_with($host, $this->bucket . '-')) {
            return $host;
        }
        // 否则按 {bucket}.cos.{region}.myqcloud.com 拼
        // 注意：COS 桶名已包含 -appid 后缀（如 mybucket-1250000000），直接拼即可
        return $this->bucket . '.' . $host;
    }

    public function driverName(): string
    {
        return 'cos';
    }

    public function put(string $path, string $content): bool
    {
        $url = $this->objectUrl($path);
        $headers = $this->signedHeaders('PUT', $path, $content);
        // 显式设置 Content-Type（curl 默认会用 application/x-www-form-urlencoded，
        // 导致 COS 里的对象 MIME 不正确，图床直链显示异常）
        $mime = $this->guessMime($path);
        $headers[] = 'Content-Type: ' . $mime;
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
        // COS 公开访问 URL：{bucket}.cos.{region}.myqcloud.com/{path}
        return $this->scheme . '://' . $this->buildHost() . '/' . ltrim($path, '/');
    }

    public function testConnection(): bool
    {
        // 用 GET 探测桶（HEAD 在某些腾讯云网关会被拒），200/403/404 都算能联通
        $headers = $this->signedHeaders('GET', '');
        $url = $this->bucketUrl('');
        $resp = $this->httpRequest('GET', $url, $headers);
        if ($resp === null) return false;
        // 成功条件：HTTP 200/204/403/404 都算能联通（COS 公共读桶可能返 403 但能通）
        if (in_array($resp['code'], [200, 204, 403, 404], true)) return true;
        // 详细错误：写日志让前端展示
        error_log('[CosStorage::testConnection] HTTP ' . $resp['code'] . ' URL=' . $url . ' body=' . substr($resp['body'] ?? '', 0, 200));
        return false;
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

    /**
     * 拼请求 URL
     * - path 部分做 RFC3986 编码（curl 要求 URL 合法：空格/中文等必须编码）
     * - query 部分（以 ? 开头）原样保留
     * 注意：签名 UriPathname 用的是【原始未编码】路径（COS 服务端会先 decode
     * 请求行再比对签名），与 URL 编码互不冲突。
     */
    private function bucketUrl(string $path): string
    {
        $query = '';
        if (isset($path[0]) && $path[0] === '?') {
            $query = $path;
            $path = '';
        }
        $encPath = $this->encodeUrlPath($path);
        return $this->scheme . '://' . $this->buildHost() . '/' . $encPath . $query;
    }

    /**
     * RFC3986 编码路径段（保留 /），用于 URL
     */
    private function encodeUrlPath(string $path): string
    {
        if ($path === '') return '';
        $segments = explode('/', ltrim($path, '/'));
        $segments = array_map(function ($s) {
            return str_replace(['+', ' ', '*', '%7E'], ['%20', '%20', '%2A', '~'], rawurlencode($s));
        }, $segments);
        return implode('/', $segments);
    }

    /**
     * 按路径猜 Content-Type
     * 仅识别常见扩展名，兜底 application/octet-stream
     */
    private function guessMime(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $map = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'bmp'  => 'image/bmp',
            'svg'  => 'image/svg+xml',
            'ico'  => 'image/x-icon',
            'avif' => 'image/avif',
            'heic' => 'image/heic',
            'mp4'  => 'video/mp4',
            'webm' => 'video/webm',
            'mov'  => 'video/quicktime',
            'pdf'  => 'application/pdf',
            'txt'  => 'text/plain',
            'json' => 'application/json',
        ];
        return $map[$ext] ?? 'application/octet-stream';
    }

    /**
     * COS XML 签名（官方 q-sign-algorithm=sha1）
     * 文档：https://cloud.tencent.com/document/product/436/7778
     *
     * KeyTime     = StartTimestamp;EndTimestamp
     * SignKey     = HMAC-SHA1(SecretKey, KeyTime)
     * HttpString  = HttpMethod\nUriPathname\nHttpParameters\nHttpHeaders\n
     * StringToSign = sha1\nKeyTime\nSHA1(HttpString)\n
     * Signature   = HMAC-SHA1(SignKey, StringToSign)
     */
    private function signedHeaders(string $method, string $path, string $body = ''): array
    {
        // 签名有效期（Unix 时间戳，秒）
        $start = time();
        $end   = $start + 3600;
        $keyTime = $start . ';' . $end;

        // SignKey = HMAC-SHA1(SecretKey, KeyTime)
        $signKey = hash_hmac('sha1', $keyTime, $this->secretKey);

        // UriPathname：实际请求路径（不含 query），如 "/" 或 "/dir/file.jpg"
        // ⚠️ 实测（2026-09-01）：COS 服务端会先对请求行路径做 URL-decode，
        //    再与签名中的 UriPathname 比对。因此这里必须用【原始未编码】路径，
        //    否则中文/空格等路径会 SignatureDoesNotMatch（HTTP 403）。
        $uriPath = '/';
        if ($path !== '' && (isset($path[0]) && $path[0] !== '?')) {
            $uriPath = '/' . ltrim($path, '/');
        }

        // HttpParameters：key 转小写、值 URLEncode，按 key 字典序
        $httpParams = '';
        $paramList  = '';
        if (isset($path[0]) && $path[0] === '?') {
            parse_str(substr($path, 1), $params);
            $params = array_change_key_case($params, CASE_LOWER);
            ksort($params);
            $pairs = [];
            foreach ($params as $k => $v) {
                $pairs[] = strtolower(rawurlencode((string)$k)) . '=' . rawurlencode((string)$v);
            }
            $httpParams = implode('&', $pairs);
            $paramList  = implode(';', array_keys($params));
        }

        // HttpHeaders：只签 host
        $host = $this->buildHost();
        $httpHeaders = 'host=' . rawurlencode($host);
        $headerList  = 'host';

        // HttpString / StringToSign / Signature
        $httpString   = strtolower($method) . "\n" . $uriPath . "\n" . $httpParams . "\n" . $httpHeaders . "\n";
        $stringToSign = "sha1\n" . $keyTime . "\n" . sha1($httpString) . "\n";
        $signature    = hash_hmac('sha1', $stringToSign, $signKey);

        $authorization = sprintf(
            'q-sign-algorithm=sha1&q-ak=%s&q-sign-time=%s&q-key-time=%s&q-header-list=%s&q-url-param-list=%s&q-signature=%s',
            $this->secretId, $keyTime, $keyTime, $headerList, $paramList, $signature
        );

        return [
            'Authorization: ' . $authorization,
            'Host: ' . $host,
        ];
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

        $result = ['code' => $code, 'headers' => $parsedHeaders, 'body' => $respBody];
        // 保存最后一次响应（让 testConnection 失败时能给前端精确诊断）
        $this->lastResponse = $result;
        return $result;
    }

    public function getLastResponse(): ?array
    {
        return $this->lastResponse ?? null;
    }
}
