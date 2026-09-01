<?php
/**
 * FreeImg 公共函数库
 */

if (!defined('FREEIMG_ROOT')) {
    define('FREEIMG_ROOT', dirname(__DIR__, 2));
}

if (!defined('FREEIMG_APP')) {
    define('FREEIMG_APP', FREEIMG_ROOT . '/app');
}

/**
 * 配置读取
 * 优先查 settings 表（动态配置），其次查 config/config.php
 */
function config(string $key, $default = null)
{
    static $config = null;
    if ($config === null) {
        $configFile = FREEIMG_ROOT . '/config/config.php';
        $config = file_exists($configFile) ? require $configFile : [];
    }

    // 顶层 'settings.xxx' 走数据库查询
    if (str_starts_with($key, 'settings.')) {
        $settingKey = substr($key, 9);
        // 单请求内静态缓存，避免重复查库（一次上传会读多个 settings 键）
        static $settingsCache = null;
        static $settingsLoaded = false;
        if (!$settingsLoaded) {
            $settingsLoaded = true;
            try {
                if (class_exists('App\\Core\\Db')) {
                    $rows = \App\Core\Db::fetchAll('SELECT `key`, `value` FROM settings');
                    foreach ($rows as $r) {
                        $settingsCache[$r['key']] = $r['value'];
                    }
                }
            } catch (\Throwable $e) {
                // 数据库不可用（安装中/未安装）→ 忽略
            }
        }
        if (isset($settingsCache[$settingKey]) && $settingsCache[$settingKey] !== '') {
            return $settingsCache[$settingKey];
        }
        // 落到 config.php 的 settings 段
        $keys = explode('.', 'settings');
        $value = $config;
        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }
        if (!isset($value[$settingKey])) {
            return $default;
        }
        return $value[$settingKey];
    }

    $keys = explode('.', $key);
    $value = $config;
    foreach ($keys as $k) {
        if (!isset($value[$k])) {
            return $default;
        }
        $value = $value[$k];
    }
    return $value;
}

/**
 * HTML 转义
 */
function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * JSON 输出
 */
function json($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * 重定向
 */
function redirect(string $url, int $code = 302): void
{
    header('Location: ' . $url, true, $code);
    exit;
}

/**
 * 当前 URL
 */
function current_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    return $scheme . '://' . $host . $uri;
}

/**
 * 当前请求的 origin（scheme + host，无 path）
 * 例：https://pic.5276.net
 * 优先级：
 *   1. settings.site_url（后台可设置，必填，安装时自动写入访问域名）
 *   2. config.app.force_url + app.url
 *   3. 当前请求的 host（动态）
 *   4. localhost 兜底
 *
 * 注意：site_url 安装时必写访问域名，老用户后台可改；没有"留空=访问域名"的语义。
 */
function base_origin(): string
{
    return site_origin();
}

/**
 * 主域名 origin（settings.site_url 优先）
 *
 * 多域名模式开关 settings.url_follow_host：
 *   '0'（默认）：完全走 settings.site_url → config.app.force_url → HTTP_HOST → localhost
 *   '1'        ：跳过 site_url，直接走请求 host 兜底（适合多域名指向同一目录场景）
 *                注意：开启此模式必须配合 config.app.allowed_hosts 白名单，否则 HTTP_HOST 可被伪造
 */
function site_origin(): string
{
    // 多域名模式：跳过 settings.site_url，直接走请求 host（复用 request_origin 安全网）
    try {
        $followHost = \App\Core\Db::fetchValue(
            "SELECT `value` FROM settings WHERE `key` = 'url_follow_host' LIMIT 1"
        );
        if ($followHost === '1') {
            return request_origin();
        }
    } catch (\Throwable $e) {
        // 静默 fallback
    }

    // 1. settings.site_url（后台可改，安装时自动写入）
    try {
        $siteUrl = \App\Core\Db::fetchValue(
            "SELECT `value` FROM settings WHERE `key` = 'site_url' LIMIT 1"
        );
        if (is_string($siteUrl) && $siteUrl !== '') {
            return extract_origin($siteUrl);
        }
    } catch (\Throwable $e) {
        // 表不存在或 DB 异常时静默 fallback
    }

    // 2. config 强制开关
    if (config('app.force_url', false)) {
        $url = rtrim((string)config('app.url', ''), '/');
        if ($url !== '') return extract_origin($url);
    }

    // 3. 当前请求的 host
    return request_origin();
}

/**
 * 从当前请求中提取 origin（带 host 白名单 + 字符清洗）
 * 单独抽出供 site_origin() 在多域名模式和默认 fallback 复用
 */
function request_origin(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    // 反代转发：X-Forwarded-Proto 白名单（防 header 注入）
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $forwarded = strtolower(trim((string)$_SERVER['HTTP_X_FORWARDED_PROTO']));
        $first = explode(',', $forwarded, 2)[0] ?? '';
        $first = trim($first);
        if ($first === 'https' || $first === 'http') {
            $scheme = $first;
        }
    }
    // host：优先 X-Forwarded-Host（CDN/反代场景）
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $host = trim(explode(',', $host, 2)[0] ?? '');
    // 白名单校验（多域名模式必须配）
    // 优先级：settings.allowed_hosts（后台可改） → config.app.allowed_hosts（兼容旧版）
    $allowed = null;
    try {
        $settingsAllowed = \App\Core\Db::fetchValue(
            "SELECT `value` FROM settings WHERE `key` = 'allowed_hosts' LIMIT 1"
        );
        if (is_string($settingsAllowed) && $settingsAllowed !== '') {
            // 兼容用户手填完整 URL 和裸域名：split 后 strip scheme，得到裸 host 用于比对
            $allowed = array_values(array_filter(array_map(function ($v) {
                $v = trim($v);
                if ($v === '') return '';
                // 去 scheme、去尾部斜杠、统一小写
                $v = preg_replace('#^https?://#i', '', $v);
                $v = rtrim($v, '/');
                return strtolower($v);
            }, preg_split('/[\r\n,]+/', $settingsAllowed)), function ($v) {
                return $v !== '';
            }));
        }
    } catch (\Throwable $e) {
        // 静默 fallback
    }
    if (!is_array($allowed) || empty($allowed)) {
        $allowed = config('app.allowed_hosts', null);
    }
    if (is_array($allowed) && !empty($allowed) && !in_array($host, $allowed, true)) {
        // host 不在白名单 → fallback 到 config.app.url
        $fallback = rtrim((string)config('app.url', ''), '/');
        if ($fallback !== '') return extract_origin($fallback);
        $host = 'localhost';
    }
    // host 字符白名单（防注入）
    $host = preg_replace('/[^a-zA-Z0-9.\-:_]/', '', $host);
    if ($host === '') $host = 'localhost';
    return $scheme . '://' . $host;
}

/**
 * 分享域名 origin
 * 优先级：settings.share_url → site_origin
 */
function share_origin(): string
{
    try {
        $url = \App\Core\Db::fetchValue(
            "SELECT `value` FROM settings WHERE `key` = 'share_url' LIMIT 1"
        );
        if (is_string($url) && $url !== '') {
            return extract_origin($url);
        }
    } catch (\Throwable $e) {
        // 静默 fallback
    }
    return site_origin();
}

/**
 * API 域名 origin
 * 优先级：settings.api_url → site_origin
 */
function api_origin(): string
{
    try {
        $url = \App\Core\Db::fetchValue(
            "SELECT `value` FROM settings WHERE `key` = 'api_url' LIMIT 1"
        );
        if (is_string($url) && $url !== '') {
            return extract_origin($url);
        }
    } catch (\Throwable $e) {
        // 静默 fallback
    }
    return site_origin();
}

/**
 * 从完整 URL 中提取 origin（scheme://host:port）
 * 带 host 字符白名单，防止恶意输入
 */
function extract_origin(string $url): string
{
    $url = trim($url);
    if (!preg_match('#^https?://[a-zA-Z0-9.\-_:]+(/.*)?$#', $url)) {
        return 'http://localhost';
    }
    $parts = parse_url($url);
    $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
    if (isset($parts['port'])) $origin .= ':' . $parts['port'];
    return $origin !== '://' ? $origin : 'http://localhost';
}

/**
 * 基础 URL
 * 优先用当前请求的 host（动态），config 兜底
 */
function base_url(string $path = ''): string
{
    $base = base_origin();
    return $base . '/' . ltrim($path, '/');
}

/**
 * 主域名 URL（用于图片外链等基础 URL 生成）
 * 等价于 base_url，但语义更清晰
 */
function site_url(string $path = ''): string
{
    return site_origin() . '/' . ltrim($path, '/');
}

/**
 * 分享域名 URL（用于 /s/{token} 分享链接）
 * 优先级：share_origin() = settings.share_url → site_origin
 */
function share_url(string $path = ''): string
{
    return share_origin() . '/' . ltrim($path, '/');
}

/**
 * API 域名 URL（用于 API 接口自描述 + 返回的图片 URL）
 * 优先级：api_origin() = settings.api_url → site_origin
 */
function api_url(string $path = ''): string
{
    return api_origin() . '/' . ltrim($path, '/');
}

/**
 * 随机字符串
 */
function random_string(int $length = 32): string
{
    return bin2hex(random_bytes(max(1, intval($length / 2))));
}

/**
 * 生成 UUID v4
 */
function uuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * CSRF Token 生成/获取
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = random_string(32);
    }
    return $_SESSION['csrf_token'];
}

/**
 * CSRF Token 校验
 */
function csrf_check(string $token): bool
{
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * 把密钥归一化成 32 字节二进制（AES-256 所需）
 * 支持：
 *   - 32 字节二进制（直接用）
 *   - 64 位 hex（hex2bin 后 32 字节）
 *   - base64 编码的 32 字节
 */
function normalizeEncryptionKey(?string $key): ?string
{
    if (empty($key)) return null;

    // 64 位 hex → 32 字节
    if (strlen($key) === 64 && ctype_xdigit($key)) {
        $bin = @hex2bin($key);
        if ($bin !== false && strlen($bin) === 32) return $bin;
    }

    // 已经是 32 字节
    if (strlen($key) === 32) return $key;

    // 尝试 base64 → 32 字节
    $bin = base64_decode($key, true);
    if ($bin !== false && strlen($bin) === 32) return $bin;

    // 兜底：用 SHA256 把任意长度派生为 32 字节
    return hash('sha256', $key, true);
}

/**
 * 加密存储密钥（AES-256-GCM）
 */
function encrypt_secret(string $plain): string
{
    $rawKey = normalizeEncryptionKey(config('app.encryption_key'));
    if ($rawKey === null) {
        // 实在没有密钥就返回明文 base64（最差兜底，不算"加密"但至少能跑通）
        return base64_encode($plain);
    }
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $rawKey, OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $cipher);
}

/**
 * 解密存储密钥
 */
function decrypt_secret(string $encrypted): string
{
    if ($encrypted === '') return '';

    $raw = base64_decode($encrypted, true);
    // 如果不是合法 base64，认为是明文，原样返回
    if ($raw === false) {
        return $encrypted;
    }

    $rawKey = normalizeEncryptionKey(config('app.encryption_key'));
    // 没密钥或密文太短（不可能是 AES-GCM 密文）→ 当作明文返回
    if ($rawKey === null || strlen($raw) < 28) {
        // 先尝试 JSON 解析密文本身（即明文 JSON）
        $maybeJson = base64_decode($encrypted, true);
        return $maybeJson === false ? $encrypted : $maybeJson;
    }

    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $rawKey, OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? $encrypted : $plain;
}

/**
 * 调试日志
 */
function logger(string $message, string $level = 'info'): void
{
    $dir = FREEIMG_ROOT . '/storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $file = $dir . '/' . date('Y-m-d') . '.log';
    $line = sprintf("[%s] %s: %s\n", date('Y-m-d H:i:s'), strtoupper($level), $message);
    @file_put_contents($file, $line, FILE_APPEND);
}

/**
 * Flash 消息
 */
function flash(string $key, ?string $value = null)
{
    if ($value !== null) {
        $_SESSION['_flash'][$key] = $value;
        return null;
    }
    $msg = $_SESSION['_flash'][$key] ?? null;
    unset($_SESSION['_flash'][$key]);
    return $msg;
}

/**
 * 获取 flash 消息（带类型），优先级 success > error > info
 * 用于视图里通用 flash 提示
 */
function flash_get(): ?array
{
    foreach (['success', 'error', 'info', 'warning'] as $type) {
        $msg = flash($type);
        if ($msg !== null) {
            return ['type' => $type, 'message' => $msg];
        }
    }
    return null;
}

/**
 * 检查是否已安装
 */
function is_installed(): bool
{
    return file_exists(FREEIMG_ROOT . '/config/config.php')
        && file_exists(FREEIMG_ROOT . '/install/install.lock');
}