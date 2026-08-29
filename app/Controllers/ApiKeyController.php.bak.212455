<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\AuthService;
use App\Middleware\AuthMiddleware;
use App\Repositories\ApiKeyRepository;
use App\Repositories\CompressionProfileRepository;
use App\Services\UploadService;
use App\Core\Db;

class ApiKeyController
{
    /**
     * 后台：API Key 列表
     * GET /api-keys
     */
    public function index(): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();
        $repo = new ApiKeyRepository();
        $keys = $repo->listByUser((int)$user['id']);
        $profileRepo = new CompressionProfileRepository();
        $profiles = $profileRepo->listEnabled();
        Response::view('api_keys/index', [
            'keys'      => $keys,
            'profiles'  => $profiles,
            'csrf'      => csrf_token(),
        ], 'main');
    }

    /**
     * 后台：创建 API Key
     * POST /api-keys
     */
    public function create(Request $request): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();
        $token = $request->post('csrf_token', '');
        if (!csrf_check($token)) {
            flash('error', '会话已过期');
            Response::redirect(base_url('api-keys'));
        }

        $name = trim((string)$request->post('name', ''));
        if ($name === '' || mb_strlen($name) > 64) {
            flash('error', '名称 1-64 字符');
            Response::redirect(base_url('api-keys'));
        }

        $profileId = (int)$request->post('compression_profile_id', 0) ?: null;
        if ($profileId !== null) {
            $exists = Db::fetchValue('SELECT id FROM compression_profiles WHERE id = :id AND enabled = 1', ['id' => $profileId]);
            if (!$exists) {
                flash('error', '压缩预设不存在或已禁用');
                Response::redirect(base_url('api-keys'));
            }
        }
        $expiresRaw = trim((string)$request->post('expires_at', ''));
        $expiresAt = null;
        if ($expiresRaw !== '') {
            $ts = strtotime($expiresRaw);
            if ($ts === false || $ts < time() - 86400) {
                flash('error', '过期时间格式无效');
                Response::redirect(base_url('api-keys'));
            }
            $expiresAt = date('Y-m-d H:i:s', $ts);
        }

        $repo = new ApiKeyRepository();
        $result = $repo->create((int)$user['id'], $name, $profileId, $expiresAt);

        // 把 secret_key 临时存 session，让 create 页能显示一次
        $_SESSION['new_api_key_secret'] = $result['secret_key'];
        $_SESSION['new_api_key_name'] = $name;
        $_SESSION['new_api_key_id'] = $result['id'];
        $_SESSION['new_api_key_access'] = $result['access_key'];

        flash('success', 'API Key 创建成功！请立即保存 secret_key（仅显示一次）');
        Response::redirect(base_url('api-keys?show_secret=1'));
    }

    /**
     * 后台：编辑 API Key（AJAX）
     * POST /api-keys/edit
     * 字段：id, csrf_token, name, compression_profile_id
     */
    public function edit(Request $request): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();

        $token = $request->post('csrf_token', '');
        if (!csrf_check($token)) {
            Response::json(['ok' => false, 'message' => '会话已过期']);
        }

        $id = (int)$request->post('id', 0);
        if ($id <= 0) {
            Response::json(['ok' => false, 'message' => '参数错误']);
        }

        $repo = new ApiKeyRepository();
        $row = $repo->findById($id);
        if (!$row || (int)$row['user_id'] !== (int)$user['id']) {
            Response::json(['ok' => false, 'message' => 'API Key 不存在']);
        }

        // 收集允许字段
        $update = [];

        $name = trim((string)$request->post('name', ''));
        if ($name === '') {
            Response::json(['ok' => false, 'message' => '名称不能为空']);
        }
        if (mb_strlen($name) > 64) {
            Response::json(['ok' => false, 'message' => '名称最多 64 字符']);
        }
        $update['name'] = $name;

        // 压缩预设（0 = 走全局默认）
        $profileId = (int)$request->post('compression_profile_id', 0);
        if ($profileId > 0) {
            $exists = Db::fetchValue('SELECT id FROM compression_profiles WHERE id = :id AND enabled = 1', ['id' => $profileId]);
            if (!$exists) {
                Response::json(['ok' => false, 'message' => '压缩预设不存在或已禁用']);
            }
            $update['compression_profile_id'] = $profileId;
        } else {
            $update['compression_profile_id'] = null;
        }

        $repo->update($id, $update);
        Response::json(['success' => true, 'ok' => true, 'message' => '已保存', 'name' => $update['name'], 'compression_profile_id' => $update['compression_profile_id']]);
    }

    /**
     * 后台：禁用/启用
     */
    public function toggle(Request $request): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();
        $token = $request->post('csrf_token', '');
        if (!csrf_check($token)) {
            Response::json(['success' => false, 'message' => '会话已过期'], 419);
        }
        $id = (int)$request->post('id', 0);
        $action = $request->post('action', 'revoke'); // revoke / activate
        // action 白名单（防止非法值误操作）
        if (!in_array($action, ['activate', 'revoke'], true)) {
            Response::json(['success' => false, 'message' => '参数无效'], 400);
        }

        $repo = new ApiKeyRepository();
        $row = $repo->findById($id);
        if (!$row || (int)$row['user_id'] !== (int)$user['id']) {
            Response::json(['success' => false, 'message' => '未找到或无权操作'], 403);
        }
        if ($action === 'activate') {
            $repo->activate($id);
        } else {
            $repo->revoke($id);
        }
        Response::json(['success' => true, 'message' => '已' . ($action === 'activate' ? '启用' : '禁用')]);
    }

    /**
     * 后台：删除
     */
    public function delete(Request $request): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();
        $token = $request->post('csrf_token', '');
        if (!csrf_check($token)) {
            Response::json(['success' => false, 'message' => '会话已过期'], 419);
        }
        $id = (int)$request->post('id', 0);
        $repo = new ApiKeyRepository();
        $row = $repo->findById($id);
        if (!$row || (int)$row['user_id'] !== (int)$user['id']) {
            Response::json(['success' => false, 'message' => '未找到或无权操作'], 403);
        }
        $repo->delete($id);
        Response::json(['success' => true, 'message' => '已删除']);
    }

    /**
     * REST API 端点：GET 友好提示（避免 404 让用户困惑）
     * GET /api/v1/upload
     */
    public function apiUploadInfo(): void
    {
        $endpoint = rtrim(base_url(), '/') . '/api/v1/upload';
        $msg = [
            'service'   => 'FreeImg API',
            'endpoint'  => $endpoint,
            'method'    => 'POST (multipart/form-data)',
            'auth'      => 'Authorization: Bearer ACCESS_KEY:SECRET_KEY',
            'doc'       => 'https://github.com/yunlianw/freeimg',
            'message'   => '❌ 这个 endpoint 只接受 POST 上传，不接受浏览器直接访问',
            'usage'     => '请用 PicGo / ShareX / 帝国CMS 插件 / curl 等工具调用',
            'curl_example' => 'curl -X POST -H "Authorization: Bearer YOUR_AK:YOUR_SK" -F "file=@/path/to/image.jpg" ' . $endpoint,
        ];
        Response::json($msg);
    }

    /**
     * REST API 端点：上传（PicGo/ShareX 兼容）
     * POST /api/v1/upload
     *
     * 认证：Authorization: Bearer ACCESS_KEY:SECRET_KEY
     *   或 X-API-Key: ACCESS_KEY + X-API-Secret: SECRET_KEY
     * 上传字段：file 或 image（multipart）
     * 可选参数：compression (high/balanced/small/extreme/original)、folder_id、is_public
     *
     * 响应：JSON { success, image: { id, url, ... } }
     */
    public function apiUpload(Request $request): void
    {
        // 认证
        $authHeader = $request->headers['authorization'] ?? '';
        $accessKey = '';
        $secretKey = '';

        if (preg_match('/Bearer\s+([^\s:]+):([^\s]+)/i', $authHeader, $m)) {
            $accessKey = $m[1];
            $secretKey = $m[2];
        } else {
            $accessKey = $request->headers['x-api-key'] ?? '';
            $secretKey = $request->headers['x-api-secret'] ?? '';
        }

        if (!$accessKey || !$secretKey) {
            Response::json(['success' => false, 'message' => '缺少 API Key 或 Secret'], 401);
        }

        $repo = new ApiKeyRepository();
        $apiKey = $repo->verify($accessKey, $secretKey);
        if (!$apiKey) {
            Response::json(['success' => false, 'message' => 'API Key 无效或已过期'], 401);
        }

        // 取上传文件（兼容 PicGo/ShareX 不同字段名）
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
            'quality'    => $request->post('compression', '') ?: null,
            'compression'=> $request->post('compression', '') ?: null,
            'folder_id'  => $request->post('folder_id', '') ?: null,
            'is_public'  => $request->post('is_public', 1),
        ];

        $svc = new UploadService();
        $result = $svc->uploadForApi($file, (int)$apiKey['user_id'], $opts, $apiKey);

        // 上传结果
        if (!$result['success']) {
            Response::json($result, 400);
        }

        Response::json([
            'success' => true,
            'duplicate' => $result['duplicate'] ?? false,
            'image' => [
                'id'          => $result['image']['id'],
                'url'         => $result['image']['public_url'],
                'name'        => $result['image']['original_name'],
                'width'       => (int)$result['image']['width'],
                'height'      => (int)$result['image']['height'],
                'size'        => (int)$result['image']['final_size'],
                'mime'        => $result['image']['mime_type'],
                'storage_path'=> $result['image']['storage_path'],
                'sha256'      => $result['image']['sha256'],
            ],
        ]);
    }
}
