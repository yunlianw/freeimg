<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Db;
use App\Services\AuthService;
use App\Middleware\AuthMiddleware;

/**
 * 存储扫描与清理
 * 扫描 public/storage/images/ 下的所有图片，对比数据库
 * - DB 有但磁盘没 → 孤儿记录（可清理）
 * - 磁盘有但 DB 没 → 孤儿文件（可清理）
 */
class StorageScanController
{
    /**
     * GET /api/storage/scan
     * 扫描结果（JSON）
     */
    public function scan(Request $request): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();
        if (($user['role'] ?? '') !== 'admin') {
            Response::json(['success' => false, 'message' => '需要管理员权限'], 403);
        }

        // 扫两个根目录（兼容历史目录结构）：
        // 1) public/storage/images/ — 代码上传目标（最近）
        // 2) public/{prefix}/ — 历史路径（nginx alias 仍服务）
        $baseDirs = [FREEIMG_ROOT . '/public/storage/images'];
        $prefix = trim((string)(\config('settings.url_path_prefix') ?: 'img'), '/'); // 单级 prefix（img/cdn），多级（rest/new）会被剥斜杠
        $prefix = preg_replace('/[^a-zA-Z0-9_-]/', '', $prefix);
        if ($prefix === '') $prefix = 'img';
        $baseDirs[] = FREEIMG_ROOT . '/public/' . $prefix;

        // 扫描磁盘所有图片文件
        $filesOnDisk = [];
        $diskKeys = []; // 用于快速查 disk 是否存在某 storage_path（兼容裸路径 + 带 prefix）
        foreach ($baseDirs as $baseDir) {
            if (!is_dir($baseDir)) continue;
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && preg_match('/\.(jpe?g|png|gif|webp|bmp)$/i', $file->getFilename())) {
                    $rel = substr($file->getPathname(), strlen($baseDir) + 1);
                    $rel = str_replace('\\', '/', $rel);
                    $info = [
                        'path'  => $rel,
                        'size'  => $file->getSize(),
                        'mtime' => $file->getMTime(),
                    ];
                    $filesOnDisk[$rel] = $info;
                    // 同时记录带 prefix 的 key（兼容历史 storage_path=img/xxx）
                    $diskKeys[] = $rel;
                    $diskKeys[] = $prefix . '/' . $rel;
                }
            }
        }
        $diskKeys = array_flip(array_unique($diskKeys));

        // DB 里所有图片的 storage_path
        $dbRows = Db::fetchAll('SELECT id, original_name, stored_name, storage_path, status FROM images');
        $filesInDb = [];
        foreach ($dbRows as $row) {
            $key = ltrim($row['storage_path'], '/');
            $filesInDb[$key] = $row;
        }

        // 比对
        $orphans = [];  // 磁盘有但 DB 没
        $missing = [];  // DB 有但磁盘没

        // DB 文件对应的磁盘文件 key（可能带 prefix 也可能裸路径）→ 反查
        $dbInDisk = [];
        foreach (array_keys($filesInDb) as $dbKey) {
            // dbKey 是 storage_path（如 img/xxx.jpg 或 xxx.jpg）
            // 如果 diskKeys 含 dbKey（带 prefix 形式），标记
            if (isset($diskKeys[$dbKey])) {
                $dbInDisk[$dbKey] = true;
            } else {
                // 反向：diskKeys 里的某 key 的 basename 或自身等于 dbKey
                foreach (array_keys($diskKeys) as $dk) {
                    if ($dk === $dbKey || basename($dk) === $dbKey) {
                        $dbInDisk[$dbKey] = true;
                        break;
                    }
                }
            }
        }
        foreach ($filesInDb as $key => $row) {
            if (!isset($dbInDisk[$key]) && $row['status'] === 'active') {
                $missing[] = $row;
            }
        }
        // 孤儿文件：磁盘有但 DB 没
        foreach ($filesOnDisk as $key => $info) {
            $hasInDb = false;
            // 反向匹配：DB 里有没有带 prefix 的对应记录
            foreach (array_keys($filesInDb) as $dbKey) {
                if ($dbKey === $key || $dbKey === $prefix . '/' . $key || substr($dbKey, -strlen('/' . $key)) === '/' . $key) {
                    $hasInDb = true;
                    break;
                }
            }
            if (!$hasInDb) {
                $orphans[] = $info;
            }
        }

        Response::json([
            'success' => true,
            'baseDir' => $baseDir,
            'prefix'  => $prefix,
            'stats'   => [
                'files'    => count($filesOnDisk),
                'db_total' => count($dbRows),
                'orphans'  => count($orphans),
                'missing'  => count($missing),
            ],
            'orphans' => $orphans,
            'missing' => $missing,
        ]);
    }

    /**
     * POST /api/storage/cleanup
     * 清理孤儿（DB 没记录但磁盘有文件）
     * Body: confirm=1
     */
    public function cleanup(Request $request): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();
        if (($user['role'] ?? '') !== 'admin') {
            Response::json(['success' => false, 'message' => '需要管理员权限'], 403);
        }

        $token = $request->post('csrf_token', '');
        if (!csrf_check($token)) {
            Response::json(['success' => false, 'message' => '会话已过期'], 419);
        }

        // 二次确认：必须传 confirm=I_UNDERSTAND 才真删（防误操作）
        $confirm = $request->post('confirm', '');

        // 扫两个根目录（与 scan 一致：public/storage/images + public/{prefix}）
        $baseDirs = [FREEIMG_ROOT . '/public/storage/images'];
        $prefix = trim((string)(\config('settings.url_path_prefix') ?: 'img'), '/');
        $prefix = preg_replace('/[^a-zA-Z0-9_-]/', '', $prefix);
        if ($prefix === '') $prefix = 'img';
        $baseDirs[] = FREEIMG_ROOT . '/public/' . $prefix;
        if (!array_filter($baseDirs, 'is_dir')) {
            Response::json(['success' => true, 'deleted' => 0]);
        }

// 拿到 DB 里所有 storage_path（裸路径 + 带 prefix 两种 key 都要建）
        $dbRows = Db::fetchAll('SELECT storage_path FROM images');
        $dbPaths = [];
        foreach ($dbRows as $row) {
            $key = ltrim($row['storage_path'], '/');
            $dbPaths[$key] = true;
            $dbPaths[basename($key)] = true; // 兼容裸路径
        }

        $deleted = 0;
        $skipped = 0;
        $errors = [];
        $wouldDelete = [];

        foreach ($baseDirs as $baseDir) {
            if (!is_dir($baseDir)) continue;
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) continue;
                if (!preg_match('/\.(jpe?g|png|gif|webp|bmp)$/i', $file->getFilename())) continue;

                $rel = substr($file->getPathname(), strlen($baseDir) + 1);
                $rel = str_replace('\\', '/', $rel);

                if (isset($dbPaths[$rel])) {
                    $skipped++;
                    continue;
                }

                // Dry-run：默认只列出不删，必须显式 confirm=I_UNDERSTAND 才真删
                if ($confirm !== 'I_UNDERSTAND') {
                    $wouldDelete[] = [
                        'path'  => $rel,
                        'size'  => $file->getSize(),
                    ];
                    continue;
                }

                // 真删
                if (@unlink($file->getPathname())) {
                    $deleted++;
                } else {
                    $errors[] = $rel;
                }
            }
        }

        if ($confirm !== 'I_UNDERSTAND') {
            Response::json([
                'success' => true,
                'dry_run' => true,
                'would_delete' => $wouldDelete,
                'skipped' => $skipped,
                'message' => '预览：' . count($wouldDelete) . ' 个文件将被删除。请传 confirm=I_UNDERSTAND 确认。',
            ]);
        }

        Response::json([
            'success' => true,
            'dry_run' => false,
            'deleted' => $deleted,
            'skipped' => $skipped,
            'errors'  => $errors,
        ]);
    }

    /**
     * POST /api/storage/cleanup-records
     * 清理孤儿记录（DB 有但磁盘没文件 — 仅 status='active'）
     * Body: confirm=1
     */
    public function cleanupRecords(Request $request): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();
        if (($user['role'] ?? '') !== 'admin') {
            Response::json(['success' => false, 'message' => '需要管理员权限'], 403);
        }

        $token = $request->post('csrf_token', '');
        if (!csrf_check($token)) {
            Response::json(['success' => false, 'message' => '会话已过期，请刷新页面重试'], 419);
        }

        $prefix = trim((string)(\config('settings.url_path_prefix') ?: 'img'), '/');
        $prefix = preg_replace('/[^a-zA-Z0-9_-]/', '', $prefix);
        if ($prefix === '') $prefix = 'img';

        // 扫两个根目录（与 scan 一致）
        $baseDirs = [FREEIMG_ROOT . '/public/storage/images'];
        $baseDirs[] = FREEIMG_ROOT . '/public/' . $prefix;

        // 拿到磁盘上所有图片路径（与 scan 保持一致：包含 prefix 与裸路径两种 key）
        $diskFiles = [];
        foreach ($baseDirs as $baseDir) {
            if (!is_dir($baseDir)) continue;
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) continue;
                if (!preg_match('/\.(jpe?g|png|gif|webp|bmp)$/i', $file->getFilename())) continue;
                $rel = substr($file->getPathname(), strlen($baseDir) + 1);
                $rel = str_replace('\\', '/', $rel);
                // 同时存两个 key：裸路径 + 带 prefix（兼容历史数据 storage_path=img/xxx）
                $diskFiles[$rel] = true;
                $diskFiles[$prefix . '/' . $rel] = true;
            }
        }

        // 找出 active 状态但磁盘没文件的记录
        $dbRows = Db::fetchAll(
            'SELECT id, original_name, storage_path, status FROM images WHERE status = "active"'
        );
        $orphanIds = [];
        $orphans = [];
        foreach ($dbRows as $row) {
            $key = ltrim($row['storage_path'], '/');
            if (!isset($diskFiles[$key])) {
                $orphanIds[] = (int)$row['id'];
                $orphans[] = $row;
            }
        }

        if (empty($orphanIds)) {
            Response::json(['success' => true, 'deleted' => 0, 'message' => '没有孤儿记录']);
        }

        // 物理删除 + 删 upload_logs
        $deleted = 0;
        Db::beginTransaction();
        try {
            // 标记为 recycle（软删，安全可回滚）+ 删 upload_logs
            $placeholders = implode(',', array_fill(0, count($orphanIds), '?'));
            $now = date('Y-m-d H:i:s');
            // 顺序对应：deleted_at=?, updated_at=?, id_1, id_2, ...
            $params = array_merge([$now, $now], $orphanIds);
            $stmt = Db::execute(
                "UPDATE images SET status = 'recycle', deleted_at = ?, updated_at = ?
                 WHERE id IN ($placeholders) AND status = 'active'",
                $params
            );
            $deleted = $stmt->rowCount();

            // 同步删除 upload_logs
            $stmt2 = Db::execute(
                "DELETE FROM upload_logs WHERE image_id IN ($placeholders)",
                $orphanIds
            );
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollBack();
            Response::json(['success' => false, 'message' => '清理失败：' . $e->getMessage()], 500);
        }

        Response::json([
            'success' => true,
            'deleted' => $deleted,
            'items'   => $orphans,
            'message' => "已清理 {$deleted} 条孤儿记录（移入回收站）",
        ]);
    }

    /**
     * GET /api/csrf
     * 返回当前 session 的 csrf_token（防止页面停留过久 token 过期）
     */
    public function csrf(Request $request): void
    {
        AuthMiddleware::handle();
        Response::json([
            'success'    => true,
            'csrf_token' => csrf_token(),
        ]);
    }
}