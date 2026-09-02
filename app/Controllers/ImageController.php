<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Drivers\StorageManager;
use App\Repositories\ImageRepository;
use App\Repositories\FolderRepository;
use App\Services\AuthService;
use App\Middleware\AuthMiddleware;

class ImageController
{
    public function index(Request $request): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();

        $page = max(1, (int)$request->query('page', 1));
        $pageSize = 24;
        $keyword = trim((string)$request->query('q', ''));
        $folderId = $request->query('folder', '');
        $status = $request->query('status', 'active'); // active / recycle

        $where = ['user_id' => (int)$user['id'], 'status' => $status];
        if ($folderId !== '' && $folderId !== '0') {
            // folderId 现在是物理路径字符串（如 'covers' 或 'covers/2024'）
            // 用 LIKE 匹配 storage_path 前缀
            $where['folder_path'] = $folderId;
        }
        if ($keyword !== '') {
            $where['keyword'] = $keyword;
        }

        $repo = new ImageRepository();
        $list = $repo->paginate($where, $page, $pageSize);

        // 真实物理目录扫描（不再用 folders 表的虚拟目录）
        $folders = $this->scanPhysicalDirs();

        Response::view('images/index', [

            'list'    => $list,
            'folders' => $folders,
            'keyword' => $keyword,
            'folder'  => $folderId,
            'status'  => $status,
            'csrf'    => csrf_token(),
        ], 'main');
    }

    /**
     * 扫描 public/img/ 下的真实物理子目录
     * 返回 [{name: 'covers', path: 'covers'}, ...]
     */
    private function scanPhysicalDirs(): array
    {
        $prefix = trim((string)(\config('settings.url_path_prefix') ?: 'img'), '/');
        $prefix = preg_replace('/[^a-zA-Z0-9\/_-]/', '', $prefix);
        if ($prefix === '') $prefix = 'img';

        $baseDir = FREEIMG_ROOT . '/public/' . $prefix;
        if (!is_dir($baseDir)) return [];

        $dirs = [];
        // 递归扫描子目录（深度 2 足够：年/月 这种 Y/m 结构）
        $items = @scandir($baseDir);
        if (!$items) return [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $full = $baseDir . '/' . $item;
            if (is_dir($full)) {
                $dirs[] = ['name' => $item, 'path' => $item, 'depth' => 1];
                // 扫描二级子目录（如 2026/08、2026/09）
                $subItems = @scandir($full);
                if (!$subItems) continue;
                foreach ($subItems as $sub) {
                    if ($sub === '.' || $sub === '..') continue;
                    $subFull = $full . '/' . $sub;
                    if (is_dir($subFull)) {
                        $dirs[] = ['name' => $item . '/' . $sub, 'path' => $item . '/' . $sub, 'depth' => 2];
                    }
                }
            }
        }
        usort($dirs, fn($a, $b) => strcmp($a['path'], $b['path']));
        return $dirs;
    }

    /**
     * 单图详情
     */
    public function show(Request $request, array $params): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();
        $id = (int)($params['id'] ?? 0);
        $img = (new ImageRepository())->find($id);
        if (!$img || (int)$img['user_id'] !== (int)$user['id']) {
            http_response_code(404);
            echo '图片不存在或无权访问';
            return;
        }
        Response::view('images/show', [
            'image' => $img,
            'csrf'  => csrf_token(),
        ], 'main');
    }

    /**
     * 重命名
     */
    public function rename(Request $request, array $params): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();
        $id = (int)($params['id'] ?? 0);
        $name = trim((string)$request->post('name', ''));

        $token = $request->post('csrf_token', '');
        if (!csrf_check($token)) {
            flash('error', '会话已过期');
            Response::redirect(base_url("images"));
        }

        $repo = new ImageRepository();
        $img = $repo->find($id);
        if (!$img || (int)$img['user_id'] !== (int)$user['id']) {
            http_response_code(403);
            echo '无权操作';
            return;
        }
        if ($name === '') {
            flash('error', '名称不能为空');
            Response::redirect(base_url("images/{$id}"));
        }

        $repo->rename($id, $name);
        flash('success', '已重命名');
        Response::redirect(base_url("images/{$id}"));
    }

    /**
     * 软删除（移入回收站）
     */
    public function trash(Request $request, array $params): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();
        $id = (int)($params['id'] ?? 0);

        $token = $request->post('csrf_token', '');
        if (!csrf_check($token)) {
            Response::json(['success' => false, 'message' => '会话已过期'], 419);
        }

        $repo = new ImageRepository();
        $img = $repo->find($id);
        if (!$img || (int)$img['user_id'] !== (int)$user['id']) {
            Response::json(['success' => false, 'message' => '无权操作'], 403);
        }
        $repo->softDelete($id);
        Response::json(['success' => true, 'message' => '已移入回收站']);
    }

    /**
     * 从回收站恢复
     */
    public function restore(Request $request, array $params): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();
        $id = (int)($params['id'] ?? 0);

        $token = $request->post('csrf_token', '');
        if (!csrf_check($token)) {
            Response::json(['success' => false, 'message' => '会话已过期'], 419);
        }

        $repo = new ImageRepository();
        $img = $repo->find($id);
        if (!$img || (int)$img['user_id'] !== (int)$user['id']) {
            Response::json(['success' => false, 'message' => '无权操作'], 403);
        }
        $repo->restore($id);
        Response::json(['success' => true, 'message' => '已恢复']);
    }

    /**
     * 永久删除（真删：DB + 文件）
     */
    public function destroy(Request $request, array $params): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();
        $id = (int)($params['id'] ?? 0);

        $token = $request->post('csrf_token', '');
        if (!csrf_check($token)) {
            Response::json(['success' => false, 'message' => '会话已过期'], 419);
        }

        $repo = new ImageRepository();
        $img = $repo->find($id);
        if (!$img || (int)$img['user_id'] !== (int)$user['id']) {
            Response::json(['success' => false, 'message' => '无权操作'], 403);
        }

        // 删文件（用真实 storage_id，避免删错位置）
        $fileDeleted = false;
        try {
            $driver = StorageManager::driver((int)$img['storage_id']);
            // 给 LocalStorage 设置 prefix（如果图片来自 Local 存储）
            if ($driver instanceof \App\Drivers\LocalStorage) {
                $prefix = trim((string)(\config('settings.url_path_prefix') ?: 'img'), '/');
                $prefix = preg_replace('/[^a-zA-Z0-9\/_-]/', '', $prefix);
                if ($prefix !== '') $driver->setPrefix($prefix);
            }
            $fileDeleted = $driver->delete($img['storage_path']);
        } catch (\Throwable $e) {
            // 真实环境下不应该静默，但为了 UX 我们记录 + 继续删 DB
            error_log('[ImageController] storage delete failed: ' . $e->getMessage());
        }

        // 减容量统计
        if (!empty($img['storage_id'])) {
            \App\Drivers\StorageManager::subUsage((int)$img['storage_id'], (int)$img['final_size']);
        }
        // 删 DB 记录
        $repo->hardDelete($id);
        Response::json(['success' => true, 'message' => '已永久删除']);
    }

    /**
     * 移动到文件夹
     */
    public function move(Request $request, array $params): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();
        $id = (int)($params['id'] ?? 0);

        $token = $request->post('csrf_token', '');
        if (!csrf_check($token)) {
            Response::json(['success' => false, 'message' => '会话已过期'], 419);
        }

        $repo = new ImageRepository();
        $img = $repo->find($id);
        if (!$img || (int)$img['user_id'] !== (int)$user['id']) {
            Response::json(['success' => false, 'message' => '无权操作'], 403);
        }

        $folderId = $request->post('folder_id', '');
        $folderId = $folderId === '' || $folderId === '0' ? null : (int)$folderId;

        // 校验目标文件夹归属当前用户
        if ($folderId !== null) {
            $folder = (new FolderRepository())->find($folderId);
            if (!$folder || (int)$folder['user_id'] !== (int)$user['id']) {
                Response::json(['success' => false, 'message' => '目标文件夹不存在或无权访问'], 403);
            }
        }

        $repo->move($id, $folderId);
        Response::json(['success' => true, 'message' => '已移动']);
    }

    /**
     * 批量删除（移到回收站）
     * POST /images/batch-trash
     * Body: ids[]=1&ids[]=2&ids[]=3
     */
    public function batchTrash(Request $request): void
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
        $ok = 0; $fail = 0;
        foreach ($ids as $id) {
            $img = $repo->find($id);
            if ($img && (int)$img['user_id'] === (int)$user['id']) {
                $repo->softDelete($id);
                $ok++;
            } else {
                $fail++;
            }
        }
        Response::json([
            'success' => true,
            'message' => "已删除 {$ok} 张图片" . ($fail ? "（{$fail} 张失败）" : ''),
            'deleted' => $ok,
            'failed'  => $fail,
        ]);
    }

}
