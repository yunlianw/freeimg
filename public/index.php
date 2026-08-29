<?php
/**
 * FreeImg 单入口
 *
 * 部署说明：
 * - 整个 public/ 目录应作为 Web 根（或作为子目录映射）
 * - Nginx: root /www/wwwroot/pic.5276.net/public;
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
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.gc_maxlifetime', (string)($config['session']['lifetime'] ?? 7200));
    if (!empty($config['session']['cookie_secure'])) {
        ini_set('session.cookie_secure', '1');
    }
    session_name($config['session']['name'] ?? 'FREEIMG_SESS');
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // 时区
    date_default_timezone_set($config['app']['timezone'] ?? 'Asia/Shanghai');

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