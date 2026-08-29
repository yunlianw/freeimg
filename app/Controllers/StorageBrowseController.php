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
        $prefix = trim((string)($_GET['prefix'] ?? ''), '/');

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