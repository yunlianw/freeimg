<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\AuthService;
use App\Middleware\AuthMiddleware;
use App\Repositories\ApiKeyRepository;
use App\Repositories\DebugKeyRepository;
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
     * API 调试上传（仅登录用户，使用专用测试 Key）
     */
    public function debugUpload(Request $request): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();

        if (!csrf_check($request->post("csrf_token", ""))) {
            Response::json(["success" => false, "message" => "会话已过期"], 400);
        }

        $file = $_FILES["file"] ?? null;
        if (!$file || $file["error"] !== UPLOAD_ERR_OK) {
            Response::json(["success" => false, "message" => "未收到文件或上传失败"], 400);
        }

        $compression = trim((string)$request->post("compression", ""));
        // 从 DB 动态取 enabled=1 档位（避免硬编码漂移）
        $validCodes = array_column(
            Db::fetchAll('SELECT code FROM compression_profiles WHERE enabled = 1'),
            'code'
        );
        if ($compression === "" || !in_array($compression, $validCodes, true)) {
            Response::json(["success" => false, "message" => "压缩档位无效"], 400);
        }

        // 自动创建 / 获取测试 Key（专用）
                $repo = new DebugKeyRepository();
        $debugKey = $repo->findOrCreateDebugKey((int)$user["id"]);
        if (!$debugKey) {
            Response::json(["success" => false, "message" => "测试 Key 创建失败"], 500);
        }

        $sizeBefore = (int)$file["size"];
        $opts = [
            "compression"   => $compression,
            "force_recompress" => 1,  // 调试必须强制重新压缩
            "is_public"     => 0,    // 调试上传的不公开
            "_local_file"   => true, // 内部钩子绕过 is_uploaded_file
            "_debug_no_watermark" => 1, // 调试模式：不强制水印，让原图档真的是原图
        ];

        $svc = new UploadService();
        $result = $svc->uploadForApi($file, (int)$user["id"], $opts, $debugKey);

        if (!$result["success"]) {
            Response::json($result, 400);
        }

        $sizeAfter = (int)($result["image"]["final_size"] ?? 0);
        $ratio = $sizeBefore > 0 ? round($sizeBefore / max(1, $sizeAfter), 2) : 0;
        $saved = max(0, $sizeBefore - $sizeAfter);

        Response::json([
            "success"    => true,
            "message"    => "上传成功",
            "image"      => $result["image"],
            "size_before" => $sizeBefore,
            "size_after"  => $sizeAfter,
            "ratio"       => $ratio,
            "saved_bytes" => $saved,
            "compression" => $compression,
        ]);
    }
}