<?php
namespace App\Controllers;

use App\Core\Response;
use App\Services\AuthService;
use App\Middleware\AuthMiddleware;
use App\Drivers\StorageManager;

/**
 * 存储浏览（列出指定存储下的子目录）
 * GET /api/storage/dirs?prefix=img
 */
class StorageBrowseController
{
    public function dirs(): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();
        // 安全：prefix 只允许字母/数字/下划线/短横线/斜杠（支持多级如 img/tu），禁止 .. 路径穿越
        $prefix = trim((string)($_GET['prefix'] ?? ''), '/');
        $prefix = preg_replace('/[^a-zA-Z0-9\/_-]/', '', $prefix);
        if (str_contains($prefix, '..')) {
            $prefix = '';
        }

        // 默认驱动
        $rows = \App\Core\Db::fetchAll(
            'SELECT id, driver, config FROM storages WHERE status = 1 ORDER BY id ASC LIMIT 5'
        );

        $dirs = [];
        foreach ($rows as $r) {
            $cfg = json_decode(\decrypt_secret($r['config']), true);
            if (!$cfg || empty($cfg['path'])) continue;

            // 转绝对路径
            $basePath = $cfg['path'];
            if ($basePath[0] !== '/' && defined('FREEIMG_ROOT')) {
                $basePath = FREEIMG_ROOT . '/' . $basePath;
            }

            // 拼上 prefix
            $scanPath = $prefix === '' ? $basePath : $basePath . '/' . $prefix;

            // 安全：realpath 边界校验，确保 scanPath 仍在 basePath 之下
            if ($prefix !== '') {
                $realBase = realpath($basePath);
                $realScan = realpath($scanPath);
                if ($realBase === false || $realScan === false || strpos($realScan, $realBase . DIRECTORY_SEPARATOR) !== 0) {
                    continue; // 越界访问 → 静默跳过
                }
            }

            if (!is_dir($scanPath)) continue;

            // 扫描子目录
            $items = @scandir($scanPath);
            if (!$items) continue;

            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                $full = $scanPath . '/' . $item;
                if (is_dir($full)) {
                    $dirs[] = $item;
                }
            }
            break; // 只用第一个存储
        }

        Response::json(['success' => true, 'dirs' => array_values(array_unique($dirs))]);
    }
}