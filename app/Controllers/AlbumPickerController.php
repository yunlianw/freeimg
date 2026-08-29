<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\FolderRepository;
use App\Repositories\ImageRepository;
use App\Services\AuthService;
use App\Middleware\AuthMiddleware;
use App\Core\Db;

/**
 * 相册图片选择器（AJAX）：从用户图库挑选图片添加到相册
 */
class AlbumPickerController
{
    /**
     * GET /albums/picker?folder_id=5&path=imgs&page=1
     * 返回当前用户图库的图片列表（含二级目录浏览）
     */
    public function picker(Request $request): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();
        $folderId = (int)$request->query('folder_id', 0);
        $path = trim((string)$request->query('path', ''), '/');
        $page = max(1, (int)$request->query('page', 1));
        $pageSize = 24;

        $folder = (new FolderRepository())->find($folderId);
        if (!$folder || (int)$folder['user_id'] !== (int)$user['id']) {
            Response::json(['success' => false, 'message' => '相册不存在'], 404);
        }

        $where = [
            'user_id' => (int)$user['id'],
            'status'  => 'active',
        ];
        if ($path !== '') {
            $where['folder_path'] = $path;
        }

        $list = (new ImageRepository())->paginate($where, $page, $pageSize);

        // 子目录列表：扫描所有 storage_path，找出当前 path 下的直接子目录
        $subDirs = [];
        $rows = Db::fetchAll(
            'SELECT DISTINCT storage_path FROM images
             WHERE user_id = :uid AND status = "active" AND deleted_at IS NULL',
            ['uid' => (int)$user['id']]
        );
        $seen = [];
        foreach ($rows as $r) {
            $sp = (string)$r['storage_path'];
            // 去掉 prefix（如果是当前 path 的子路径）
            if ($path !== '') {
                $prefix = $path . '/';
                if (strpos($sp, $prefix) !== 0) continue; // 不在当前 path 下
                $rest = substr($sp, strlen($prefix));
            } else {
                $rest = $sp;
            }
            // rest 里如果还含 '/',说明还有下级目录（取第一段）；否则就是叶子（图片）
            if (strpos($rest, '/') === false) continue;
            $first = strtok($rest, '/');
            if ($first === false || $first === '') continue;
            if (!isset($seen[$first])) {
                $seen[$first] = true;
                $fullPath = $path === '' ? $first : ($path . '/' . $first);
                $subDirs[] = ['name' => $first, 'path' => $fullPath];
            }
        }
        // 排序稳定
        usort($subDirs, fn($a, $b) => strcmp($a['name'], $b['name']));

        Response::json([
            'success'    => true,
            'images'     => $list['items'],
            'total'      => $list['total'],
            'page'       => $list['page'],
            'total_pages'=> $list['total_pages'],
            'sub_dirs'   => $subDirs,
            'path'       => $path,
        ]);
    }
}