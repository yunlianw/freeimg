<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\FolderRepository;
use App\Services\AuthService;
use App\Middleware\AuthMiddleware;

class FolderController
{
    public function index(Request $request): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();
        $folders = (new FolderRepository())->listByUser((int)$user['id']);
        Response::json(['success' => true, 'data' => $folders]);
    }

    public function create(Request $request): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();

        $token = $request->post('csrf_token', '');
        if (!csrf_check($token)) {
            Response::json(['success' => false, 'message' => '会话已过期'], 419);
        }

        $name = trim((string)$request->post('name', ''));
        $parent = $request->post('parent_id', '');
        $parentId = $parent === '' ? null : (int)$parent;

        if ($name === '' || mb_strlen($name) > 128) {
            Response::json(['success' => false, 'message' => '名称无效']);
        }

        $repo = new FolderRepository();
        if ($repo->nameExists($name, (int)$user['id'], $parentId)) {
            Response::json(['success' => false, 'message' => '同名文件夹已存在']);
        }

        $id = $repo->create([
            'user_id'  => (int)$user['id'],
            'parent_id'=> $parentId,
            'name'     => $name,
            'path'     => $name,
            'created_at'=> date('Y-m-d H:i:s'),
        ]);

        Response::json(['success' => true, 'id' => $id, 'name' => $name]);
    }

    public function rename(Request $request, array $params): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();
        $id = (int)($params['id'] ?? 0);

        $token = $request->post('csrf_token', '');
        if (!csrf_check($token)) {
            Response::json(['success' => false, 'message' => '会话已过期'], 419);
        }

        $repo = new FolderRepository();
        $folder = $repo->find($id);
        if (!$folder || (int)$folder['user_id'] !== (int)$user['id']) {
            Response::json(['success' => false, 'message' => '无权操作'], 403);
        }

        $name = trim((string)$request->post('name', ''));
        if ($name === '' || mb_strlen($name) > 128) {
            Response::json(['success' => false, 'message' => '名称无效']);
        }
        if ($repo->nameExists($name, (int)$user['id'], $folder['parent_id'] !== null ? (int)$folder['parent_id'] : null, $id)) {
            Response::json(['success' => false, 'message' => '同名文件夹已存在']);
        }

        $repo->rename($id, $name);
        Response::json(['success' => true, 'message' => '已重命名']);
    }

    public function delete(Request $request, array $params): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();
        $id = (int)($params['id'] ?? 0);

        $token = $request->post('csrf_token', '');
        if (!csrf_check($token)) {
            Response::json(['success' => false, 'message' => '会话已过期'], 419);
        }

        $repo = new FolderRepository();
        $folder = $repo->find($id);
        if (!$folder || (int)$folder['user_id'] !== (int)$user['id']) {
            Response::json(['success' => false, 'message' => '无权操作'], 403);
        }

        $repo->softDelete($id);
        Response::json(['success' => true, 'message' => '已删除']);
    }
}