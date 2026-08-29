<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\UploadService;
use App\Services\AuthService;
use App\Repositories\ImageRepository;
use App\Repositories\FolderRepository;
use App\Middleware\AuthMiddleware;

class UploadController
{
    /**
     * 上传页面
     */
    public function page(Request $request): void
    {
        AuthMiddleware::handle();
        $folders = (new FolderRepository())->listByUser((int)\App\Services\AuthService::user()['id']);
        // Phase 8: 取可见存储（多存储时显示下拉，单存储时自动选）
        $visibleStorages = \App\Drivers\StorageManager::listVisible();

        // Phase 9: 取 Web 默认压缩档位（从 compression_profiles 取 code）
        $webProfileId = (int)config('settings.web_compression_profile_id', 0);
        $webProfile = null;
        if ($webProfileId > 0) {
            $webProfile = \App\Core\Db::fetchOne(
                'SELECT code, name FROM compression_profiles WHERE id = :id AND enabled = 1',
                ['id' => $webProfileId]
            );
        }
        $defaultQuality = $webProfile['code'] ?? 'balanced';

        Response::view('upload/index', [
            'csrf'    => csrf_token(),
            'folders' => $folders,
            'albums'  => $folders,
            'visible_storages' => $visibleStorages,
            'default_quality'  => $defaultQuality,
        ], 'main');
    }

    /**
     * 归一化 folder_id：空串/null/0/'0' 都视为 null（未分类）
     */
    private function normalizeFolderId($value): ?int
    {
        if ($value === null || $value === '' || $value === '0' || $value === 0) {
            return null;
        }
        $id = (int)$value;
        return $id > 0 ? $id : null;
    }

    /**
     * AJAX 上传
     */
    public function handle(Request $request): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();

        $token = $request->post('csrf_token', '');
        if (!csrf_check($token)) {
            Response::json(['success' => false, 'message' => '会话已过期'], 419);
        }

        if (empty($_FILES)) {
            Response::json(['success' => false, 'message' => '没有上传文件'], 400);
        }

        $opts = [
            'quality'    => $request->post('quality', 'balanced'),
            'max_width'  => (int)$request->post('max_width', 0),
            'max_height' => (int)$request->post('max_height', 0),
            'folder_id'  => $this->normalizeFolderId($request->post('folder_id')),
            'is_public'  => (int)$request->post('is_public', 1),
            'subdir'     => trim((string)$request->post('custom_path', ''), '/'),
            'keep_name'  => $request->post('keep_name') === '1' || $request->post('keep_name') === 'true',
            'expires_at' => $request->post('expires_at', ''),
            'tags'       => array_filter(array_map('trim', explode(',', (string)$request->post('tags', '')))),
            'storage_id' => (int)$request->post('storage_id', 0),  // Phase 8: 用户可手动选存储
        ];

        // 校验 folder 存在且属于当前用户（避免孤儿引用）
        if ($opts['folder_id'] !== null) {
            $folder = (new FolderRepository())->find($opts['folder_id']);
            if (!$folder || (int)$folder['user_id'] !== (int)$user['id']) {
                Response::json(['success' => false, 'message' => '目标文件夹不存在或无权访问'], 400);
            }
        }

        $svc = new UploadService();
        $results = $svc->uploadBatch($_FILES, (int)$user['id'], $opts);

        // 单文件场景：直接返回 image
        if (count($results) === 1) {
            Response::json($results[0]);
        }
        Response::json(['success' => true, 'results' => $results]);
    }
}