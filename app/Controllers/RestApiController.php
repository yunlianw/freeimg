<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Db;
use App\Drivers\StorageManager;
use App\Repositories\ApiKeyRepository;
use App\Repositories\ImageRepository;
use App\Services\UploadService;
use App\Support\ApiAuthHelper;

/**
 * FreeImg 公共 REST API
 * 端点：/api/v1/*
 * 鉴权：Bearer ACCESS_KEY:SECRET_KEY 或 X-API-Key + X-API-Secret
 *
 * 完整端点：
 *   POST   /api/v1/upload             — 上传图片（PicGo / ShareX / 帝国CMS）
 *   GET    /api/v1/upload             — 友好提示
 *   GET    /api/v1/images             — 列出图片（帝国CMS 编辑器选图）
 *   GET    /api/v1/images/{id}        — 单图详情
 *   POST   /api/v1/images/{id}/delete — 删除图片（兼容）
 *   DELETE /api/v1/images/{id}        — 删除图片（REST 标准）
 *   GET    /api/v1/folders            — 文件夹列表
 */
class RestApiController
{
    use ApiAuthHelper;

    /**
     * GET /api/v1/upload — 友好提示（避免浏览器 GET 404）
     */
    public function uploadInfo(Request $request): void
    {
        $endpoint = rtrim(api_url(), '/') . '/api/v1/upload';
        Response::json([
            'service'      => 'FreeImg API',
            'endpoint'     => $endpoint,
            'method'       => 'POST (multipart/form-data)',
            'auth'         => 'Authorization: Bearer ACCESS_KEY:SECRET_KEY',
            'doc'          => 'https://github.com/yunlianw/freeimg',
            'message'      => '❌ 这个 endpoint 只接受 POST 上传，不接受浏览器直接访问',
            'usage'        => '请用 PicGo / ShareX / 帝国CMS 插件 / curl 等工具调用',
            'curl_example' => 'curl -X POST -H "Authorization: Bearer YOUR_AK:YOUR_SK" -F "file=@/path/to/image.jpg" ' . $endpoint,
        ]);
    }

    /**
     * POST /api/v1/upload — 上传图片
     */
    public function upload(Request $request): void
    {
        $apiKey = $this->apiAuth($request);
        if (!$apiKey) return;

        $file = null;
        if (!empty($_FILES['file'])) {
            $file = $_FILES['file'];
        } elseif (!empty($_FILES['image'])) {
            $file = $_FILES['image'];
        } elseif (!empty($_FILES['files'])) {
            $file = $_FILES['files'];
        }
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            Response::json(['success' => false, 'message' => '未收到上传文件'], 400);
        }

        $opts = [
            'quality'          => $request->post('compression', '') ?: null,
            'compression'      => $request->post('compression', '') ?: null,
            'force_recompress' => in_array(strtolower((string)$request->post('force_recompress', '')), ['1', 'true', 'yes', 'on'], true) ? 1 : 0,
            'folder_id'        => $request->post('folder_id', '') ?: null,
            'is_public'        => $request->post('is_public', 1),
        ];

        $svc = new UploadService();
        $result = $svc->uploadForApi($file, (int)$apiKey['user_id'], $opts, $apiKey);

        if (!$result['success']) {
            Response::json($result, 400);
        }

        Response::json([
            'success'   => true,
            'duplicate' => $result['duplicate'] ?? false,
            'compression' => $result['image']['compression'] ?? null,
            'image'     => [
                'id'          => $result['image']['id'],
                'url'         => $result['image']['public_url'],
                'name'        => $result['image']['original_name'],
                'width'       => (int)$result['image']['width'],
                'height'      => (int)$result['image']['height'],
                'size'        => (int)$result['image']['final_size'],
                'mime'        => $result['image']['mime_type'],
                'compression' => $result['image']['compression'] ?? null,
                'storage_path'=> $result['image']['storage_path'],
                'sha256'      => $result['image']['sha256'],
            ],
        ]);
    }

    /**
     * GET /api/v1/images — 列出图片
     * Query: page (默认 1), per_page (默认 30, max 100), folder_id (可选)
     */
    public function listImages(Request $request): void
    {
        $apiKey = $this->apiAuth($request);
        if (!$apiKey) return;

        $page = max(1, (int)$request->query('page', 1));
        $perPage = min(100, max(1, (int)$request->query('per_page', 30)));
        $folderId = $request->query('folder_id');
        $folderId = ($folderId === '' || $folderId === null) ? null : (int)$folderId;

        $params = ['uid' => (int)$apiKey['user_id']];
        $extra = '';
        if ($folderId !== null) {
            $extra = ' AND folder_id = :fid';
            $params['fid'] = $folderId;
        }

        $offset = ($page - 1) * $perPage;
        $rows = Db::fetchAll(
            "SELECT id, uuid, original_name, stored_name, extension, mime_type,
                    width, height, original_size, final_size, compression_ratio,
                    storage_path, public_url, folder_id, is_public,
                    compression, sha256, created_at
             FROM images
             WHERE user_id = :uid AND status = 'active'" . $extra . "
             ORDER BY id DESC
             LIMIT $perPage OFFSET $offset",
            $params
        );

        $total = (int)Db::fetchValue(
            "SELECT COUNT(*) FROM images WHERE user_id = :uid AND status = 'active'" . $extra,
            $params
        );

        Response::json([
            'success'  => true,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'images'   => array_map(function ($r) {
                return [
                    'id'               => (int)$r['id'],
                    'uuid'             => $r['uuid'],
                    'url'              => $r['public_url'],
                    'name'             => $r['original_name'],
                    'stored_name'      => $r['stored_name'],
                    'extension'        => $r['extension'],
                    'mime'             => $r['mime_type'],
                    'width'            => (int)$r['width'],
                    'height'           => (int)$r['height'],
                    'size'             => (int)$r['final_size'],
                    'original_size'    => (int)$r['original_size'],
                    'compression_ratio'=> (float)$r['compression_ratio'],
                    'compression'      => $r['compression'] ?? null,
                    'folder_id'        => $r['folder_id'] !== null ? (int)$r['folder_id'] : null,
                    'is_public'        => (int)$r['is_public'],
                    'sha256'           => $r['sha256'],
                    'created_at'       => $r['created_at'],
                ];
            }, $rows),
        ]);
    }

    /**
     * GET /api/v1/images/{id} — 单图详情
     */
    public function getImage(Request $request, array $params): void
    {
        $apiKey = $this->apiAuth($request);
        if (!$apiKey) return;
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            Response::json(['success' => false, 'message' => '参数错误'], 400);
        }
        $row = Db::fetchOne(
            "SELECT * FROM images WHERE id = :id AND user_id = :uid",
            ['id' => $id, 'uid' => (int)$apiKey['user_id']]
        );
        if (!$row) {
            Response::json(['success' => false, 'message' => '图片不存在'], 404);
        }
        Response::json([
            'success' => true,
            'image' => [
                'id'               => (int)$row['id'],
                'uuid'             => $row['uuid'],
                'url'              => $row['public_url'],
                'name'             => $row['original_name'],
                'stored_name'      => $row['stored_name'],
                'extension'        => $row['extension'],
                'mime'             => $row['mime_type'],
                'width'            => (int)$row['width'],
                'height'           => (int)$row['height'],
                'size'             => (int)$row['final_size'],
                'original_size'    => (int)$row['original_size'],
                'compression_ratio'=> (float)$row['compression_ratio'],
                'compression'      => $row['compression'] ?? null,
                'folder_id'        => $row['folder_id'] !== null ? (int)$row['folder_id'] : null,
                'is_public'        => (int)$row['is_public'],
                'sha256'           => $row['sha256'],
                'storage_path'     => $row['storage_path'],
                'created_at'       => $row['created_at'],
            ],
        ]);
    }

    /**
     * POST /api/v1/images/{id}/delete 或 DELETE /api/v1/images/{id} — 删除图片
     */
    public function deleteImage(Request $request, array $params): void
    {
        $apiKey = $this->apiAuth($request);
        if (!$apiKey) return;
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            Response::json(['success' => false, 'message' => '参数错误'], 400);
        }
        $row = Db::fetchOne(
            "SELECT id, storage_id, storage_path, final_size, status FROM images WHERE id = :id AND user_id = :uid",
            ['id' => $id, 'uid' => (int)$apiKey['user_id']]
        );
        if (!$row) {
            Response::json(['success' => false, 'message' => '图片不存在'], 404);
        }

        // 删物理文件
        try {
            $driver = StorageManager::driver((int)$row['storage_id']);
            $driver->delete(ltrim((string)$row['storage_path'], '/'));
            StorageManager::subUsage((int)$row['storage_id'], (int)$row['final_size']);
        } catch (\Throwable $e) {
            // 物理删除失败不阻断 DB 删除
        }

        Db::execute("DELETE FROM images WHERE id = :id", ['id' => $id]);
        Response::json(['success' => true, 'message' => '已删除', 'id' => $id]);
    }

    /**
     * GET /api/v1/folders — 文件夹列表
     */
    public function listFolders(Request $request): void
    {
        $apiKey = $this->apiAuth($request);
        if (!$apiKey) return;
        $rows = Db::fetchAll(
            "SELECT id, name, parent_id, path, description, created_at
             FROM folders WHERE user_id = :uid AND deleted_at IS NULL ORDER BY id ASC",
            ['uid' => (int)$apiKey['user_id']]
        );
        Response::json([
            'success' => true,
            'folders' => array_map(function ($r) {
                return [
                    'id' => (int)$r['id'],
                    'name' => $r['name'],
                    'parent_id' => $r['parent_id'] !== null ? (int)$r['parent_id'] : null,
                    'path' => $r['path'],
                    'description' => $r['description'],
                    'created_at' => $r['created_at'],
                ];
            }, $rows),
        ]);
    }
}