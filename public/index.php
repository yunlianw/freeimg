<?php
/**
 * FreeImg 单入口
 *
 * 部署说明：
 * - 整个 public/ 目录应作为 Web 根（或作为子目录映射）
 * - Nginx: root /www/wwwroot/freeimg/public;
 * - Apache: .htaccess 已配置
 */

declare(strict_types=1);

define('FREEIMG_START', microtime(true));

// 路径常量
define('FREEIMG_ROOT', dirname(__DIR__));
define('FREEIMG_PUBLIC', __DIR__);

// 自动加载
require FREEIMG_ROOT . '/app/Helpers/functions.php';

spl_autoload_register(function (string $class) {
    if (strpos($class, 'App\\') !== 0) return;
    $rel = str_replace(['App\\', '\\'], ['', '/'], $class);
    $file = FREEIMG_ROOT . '/app/' . $rel . '.php';
    if (file_exists($file)) require $file;
});

// 未安装 → 跳转安装
if (!is_installed()) {
    // 安装期间跳过
    if (strpos($_SERVER['REQUEST_URI'] ?? '', '/install') === false) {
        header('Location: /install/');
        exit;
    }
} else {
    // 加载配置 + 启动 Session
    $config = require FREEIMG_ROOT . '/config/config.php';

    // Session 配置
    // v1.4.1 修复：PHP session cookie 寿命必须跟 DB session TTL 一致，
    // 否则 DB 里 session_token 还活着，PHP session cookie 先过期 → 被踢
    // 优先用 SessionService::ttlSeconds()（DB settings.session_ttl_hours，缺失时默认 24h），
    // 回退到 config.php session.lifetime
    // 语义：绝对上限（登录后固定 TTL 到期），后台改 TTL 不影响已登录会话，只影响新登录
    $sessionTtlSeconds = 0;
    try {
        if (class_exists('App\\Services\\SessionService')) {
            $sessionTtlSeconds = \App\Services\SessionService::ttlSeconds();
        }
    } catch (\Throwable $e) {
        // 安装中 / Db 未就绪 → 忽略
    }
    if ($sessionTtlSeconds <= 0) {
        $sessionTtlSeconds = (int)($config['session']['lifetime'] ?? 7200);
    }
    if ($sessionTtlSeconds < 3600) {
        $sessionTtlSeconds = 3600; // 保底 1 小时，防 7200 秒默认让"活跃会话"设置看起来没生效
    }

    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.gc_maxlifetime', (string)$sessionTtlSeconds);
    ini_set('session.cookie_lifetime', (string)$sessionTtlSeconds); // 持久 cookie，关闭浏览器不丢
    if (!empty($config['session']['cookie_secure'])) {
        ini_set('session.cookie_secure', '1');
    }
    session_name($config['session']['name'] ?? 'FREEIMG_SESS');
    if (session_status() === PHP_SESSION_NONE) {
        // 显式设置 cookie lifetime（ini_set 必须在 session_start 之前；保险起见再调一次）
        session_set_cookie_params([
            'lifetime' => $sessionTtlSeconds,
            'path'     => '/',
            'domain'   => '',
            'secure'   => !empty($config['session']['cookie_secure']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    // 时区
    date_default_timezone_set($config['app']['timezone'] ?? 'Asia/Shanghai');

    // 安全响应头（PHP 层兜底，nginx/Apache 部署均生效）
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob: https:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");

    // 错误显示
    if (!empty($config['app']['debug'])) {
        error_reporting(E_ALL);
        ini_set('display_errors', '1');
    } else {
        error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
        ini_set('display_errors', '0');
    }

    // 路由
    require FREEIMG_ROOT . '/config/routes.php';
}