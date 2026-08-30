<?php
/**
 * FreeImg 升级脚本（自动随 install/Installer 触发）
 *
 * 当前版本：v1.1.4-alpha
 * - 清理 v1.1.3 残留的孤儿 settings 行（site_description/default_storage/allow_signup/maintenance_mode）
 * - 幂等：可重复运行，已清理的 key 不会报错
 *
 * 触发方式：Installer::createLock() 末尾 require_once
 * 注意：脚本仅清理已知孤儿 key（4 个白名单），无任何敏感操作；install/ 目录本身有 install.lock 守护，重装保护机制已就位。
 */

if (!defined('FREEIMG_ROOT')) {
    define('FREEIMG_ROOT', dirname(__DIR__));
}

$config = require FREEIMG_ROOT . '/config/config.php';

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $config['database']['host'],
        $config['database']['port'] ?? 3306,
        $config['database']['dbname'],
        $config['database']['charset'] ?? 'utf8mb4'
    );
    $pdo = new PDO(
        $dsn,
        $config['database']['username'],
        $config['database']['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $e) {
    // DB 连不上 → 跳过升级（安装阶段未初始化 DB 时常见）
    return;
}

// v1.1.4: 清理孤儿 settings 行
$orphanKeys = ['site_description', 'default_storage', 'allow_signup', 'maintenance_mode'];

$deleted = 0;
foreach ($orphanKeys as $key) {
    try {
        $stmt = $pdo->prepare('DELETE FROM settings WHERE `key` = :k');
        $stmt->execute([':k' => $key]);
        $deleted += $stmt->rowCount();
    } catch (Throwable $e) {
        // 表不存在/字段不存在 → 静默跳过，不阻塞升级
    }
}

// v1.1.5: 添加 strip_exif 默认设置（不存在则插入）
try {
    $check = $pdo->prepare('SELECT COUNT(*) FROM settings WHERE `key` = :k');
    $check->execute([':k' => 'strip_exif']);
    if ((int)$check->fetchColumn() === 0) {
        $ins = $pdo->prepare("INSERT INTO settings (`key`, `value`, `group`, created_at) VALUES ('strip_exif', '1', 'image', NOW())");
        $ins->execute();
    }
} catch (Throwable $e) {
    // 表不存在 → 跳过
}

// 记录升级日志（可选）
if ($deleted > 0) {
    error_log('[FreeImg upgrade v1.1.4-alpha] Cleaned ' . $deleted . ' orphan settings rows');
}
