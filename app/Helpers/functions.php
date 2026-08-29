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
 * 例：https://your-domain.com
 * 注意：HTTP_HOST 可能被反代/客户端伪造，生产环境如用 CDN 请设置 config.app.force_url=true 走写死值
 */
function base_origin(): string
{
    // 1. config 强制开关（生产/CDN 推荐）
    if (config('app.force_url', false)) {
        return rtrim((string)config('app.url', ''), '/');
    }
    // 2. 当前请求的 host（动态）
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    // 反代转发：X-Forwarded-Proto 白名单（防止 header 注入型 XSS）
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $forwarded = strtolower(trim((string)$_SERVER['HTTP_X_FORWARDED_PROTO']));
        // 多值取首段（防逗号串联）
        $first = explode(',', $forwarded, 2)[0] ?? '';
        $first = trim($first);
        if ($first === 'https' || $first === 'http') {
            $scheme = $first;
        }
    }
    // host：优先用 X-Forwarded-Host（CDN/反代场景），取首段 + 域名白名单
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');
    // 多值取首段（逗号分隔，如 "a.com, b.com"）
    $host = trim(explode(',', $host, 2)[0] ?? '');
    // config 可配置 allowed_hosts 白名单（生产推荐）
    $allowed = config('app.allowed_hosts', null);
    if (is_array($allowed) && !empty($allowed) && !in_array($host, $allowed, true)) {
        // host 不在白名单时 fallback 到 config.app.url
        $fallback = rtrim((string)config('app.url', ''), '/');
        if ($fallback !== '') return $fallback;
        $host = 'localhost';
    }
    // host 字符白名单（防注入）
    $host = preg_replace('/[^a-zA-Z0-9.\-:_]/', '', $host);
    if ($host === '') $host = 'localhost';
    return $scheme . '://' . $host;
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