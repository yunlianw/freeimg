<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Drivers\StorageManager;
use App\Services\AuthService;
use App\Middleware\AuthMiddleware;
use App\Repositories\ImageRepository;

/**
 * 批量图片操作（从 ImageController 拆出以保 < 400 行红线）
 */
class BatchImageController
{
    /**
     * 批量永久删除（物理 + DB）
     * POST /images/batch-destroy
     */
    public function batchDestroy(Request $request): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();
        $token = $request->post('csrf_token', '');
        if (!csrf_check($token)) {
            Response::json(['success' => false, 'message' => '会话已过期'], 419);
        }

        $ids = $request->post('ids', []);
        if (!is_array($ids)) $ids = [];
        $ids = array_filter(array_map('intval', $ids));
        if (empty($ids)) {
            Response::json(['success' => false, 'message' => '请选择要删除的图片'], 400);
        }

        $repo = new ImageRepository();
        $prefix = trim((string)(\config('settings.url_path_prefix') ?: 'img'), '/');
        $prefix = preg_replace('/[^a-zA-Z0-9\/_-]/', '', $prefix);

        $ok = 0; $fail = 0; $errors = [];
        foreach ($ids as $id) {
            $img = $repo->find($id);
            if (!$img || (int)$img['user_id'] !== (int)$user['id']) {
                $fail++;
                continue;
            }
            // 删物理文件
            try {
                $driver = StorageManager::driver((int)$img['storage_id']);
                if ($driver instanceof \App\Drivers\LocalStorage && $prefix !== '') {
                    $driver->setPrefix($prefix);
                }
                $driver->delete($img['storage_path']);
            } catch (\Throwable $e) {
                $errors[] = $img['storage_path'] . ': ' . $e->getMessage();
            }
            // 减容量统计
            if (!empty($img['storage_id'])) {
                \App\Drivers\StorageManager::subUsage((int)$img['storage_id'], (int)$img['final_size']);
            }
            // 删 DB
            $repo->hardDelete($id);
            $ok++;
        }
        Response::json([
            'success' => $ok > 0,
            'message' => "已永久删除 {$ok} 张" . ($fail ? "（{$fail} 张失败）" : ''),
            'deleted' => $ok,
            'failed'  => $fail,
            'errors'  => $errors,
        ]);
    }

    /**
     * 清空回收站（全部永久删除）
     * POST /images/empty-recycle
     */
    public function emptyRecycle(Request $request): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();
        $token = $request->post('csrf_token', '');
        if (!csrf_check($token)) {
            Response::json(['success' => false, 'message' => '会话已过期'], 419);
        }

        $repo = new ImageRepository();
        $prefix = trim((string)(\config('settings.url_path_prefix') ?: 'img'), '/');
        $prefix = preg_replace('/[^a-zA-Z0-9\/_-]/', '', $prefix);

        // 找当前用户所有回收站图片
        $rows = \App\Core\Db::fetchAll(
            'SELECT id, storage_id, storage_path FROM images WHERE user_id = :uid AND status = :s AND deleted_at IS NOT NULL',
            ['uid' => (int)$user['id'], 's' => 'recycle']
        );

        $ok = 0; $fail = 0; $errors = [];
        foreach ($rows as $img) {
            try {
                $driver = StorageManager::driver((int)$img['storage_id']);
                if ($driver instanceof \App\Drivers\LocalStorage && $prefix !== '') {
                    $driver->setPrefix($prefix);
                }
                $driver->delete($img['storage_path']);
            } catch (\Throwable $e) {
                $errors[] = $img['storage_path'] . ': ' . $e->getMessage();
            }
            // 减容量
            if (!empty($img['storage_id'])) {
                \App\Drivers\StorageManager::subUsage((int)$img['storage_id'], (int)$img['final_size']);
            }
            $repo->hardDelete((int)$img['id']);
            $ok++;
        }

        Response::json([
            'success' => true,
            'message' => "已清空回收站：{$ok} 张永久删除" . ($fail ? "（{$fail} 张失败）" : ''),
            'deleted' => $ok,
            'failed'  => $fail,
            'errors'  => $errors,
        ]);
    }
}
