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
 * 相册管理（后台）：虚拟相册 = folders 表，图片通过 folder_id 关联
 */
class AlbumController
{
    /** 相册列表页 */
    public function index(Request $request): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();
        $folders = (new FolderRepository())->listByUserWithStats((int)$user['id']);

        Response::view('albums/index', [
            'folders' => $folders,
            'csrf'    => csrf_token(),
        ], 'main');
    }

    /** 创建相册 */
    public function create(Request $request): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();
        if (!csrf_check($request->post('csrf_token', ''))) {
            flash('error', '会话已过期');
            Response::redirect(base_url('albums'));
        }

        $name = trim((string)$request->post('name', ''));
        if ($name === '' || mb_strlen($name) > 128) {
            flash('error', '相册名称无效（1-128 字符）');
            Response::redirect(base_url('albums'));
        }

        $repo = new FolderRepository();
        if ($repo->nameExists($name, (int)$user['id'], null)) {
            flash('error', '同名相册已存在');
            Response::redirect(base_url('albums'));
        }

        $repo->create([
            'user_id'   => (int)$user['id'],
            'parent_id' => null,
            'name'      => $name,
            'path'      => $name,
            'created_at'=> date('Y-m-d H:i:s'),
        ]);
        flash('success', "相册「{$name}」已创建");
        Response::redirect(base_url('albums'));
    }

    /** 重命名 */
    public function rename(Request $request, array $params): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();
        if (!csrf_check($request->post('csrf_token', ''))) {
            flash('error', '会话已过期');
            Response::redirect(base_url('albums'));
        }

        $id = (int)($params['id'] ?? 0);
        $repo = new FolderRepository();
        $folder = $repo->find($id);
        if (!$folder || (int)$folder['user_id'] !== (int)$user['id']) {
            flash('error', '相册不存在或无权操作');
            Response::redirect(base_url('albums'));
        }

        $name = trim((string)$request->post('name', ''));
        if ($name === '' || mb_strlen($name) > 128) {
            flash('error', '相册名称无效（1-128 字符）');
            Response::redirect(base_url('albums'));
        }
        if ($repo->nameExists($name, (int)$user['id'], null, $id)) {
            flash('error', '同名相册已存在');
            Response::redirect(base_url('albums'));
        }

        $repo->rename($id, $name);
        flash('success', '已重命名');
        Response::redirect(base_url('albums'));
    }

    /** 删除（软删，图片移出保留） */
    public function delete(Request $request, array $params): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();
        if (!csrf_check($request->post('csrf_token', ''))) {
            flash('error', '会话已过期');
            Response::redirect(base_url('albums'));
        }

        $id = (int)($params['id'] ?? 0);
        $repo = new FolderRepository();
        $folder = $repo->find($id);
        if (!$folder || (int)$folder['user_id'] !== (int)$user['id']) {
            flash('error', '相册不存在或无权操作');
            Response::redirect(base_url('albums'));
        }

        \App\Core\Db::beginTransaction();
        try {
            $repo->detachImages($id, (int)$user['id']);
            $repo->softDelete($id);
            \App\Core\Db::commit();
        } catch (\Throwable $e) {
            \App\Core\Db::rollBack();
            flash('error', '删除失败，请重试');
            Response::redirect(base_url('albums'));
        }
        flash('success', "相册「{$folder['name']}」已删除，图片已移出保留");
        Response::redirect(base_url('albums'));
    }

    /** 开启/更新分享 */
    public function share(Request $request, array $params): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();
        if (!csrf_check($request->post('csrf_token', ''))) {
            flash('error', '会话已过期');
            Response::redirect(base_url('albums'));
        }

        $id = (int)($params['id'] ?? 0);
        $repo = new FolderRepository();
        $folder = $repo->find($id);
        if (!$folder || (int)$folder['user_id'] !== (int)$user['id']) {
            flash('error', '相册不存在或无权操作');
            Response::redirect(base_url('albums'));
        }

        $token = (string)$folder['share_token'];
        if ($token === '') {
            $token = bin2hex(random_bytes(16));
            $tries = 0;
            while ($repo->findByShareToken($token) && $tries++ < 5) {
                $token = bin2hex(random_bytes(16));
            }
        }

        $expires = trim((string)$request->post('expires', ''));
        $allowed = [0, 1, 7, 30];
        $expiresAt = null;
        if ($expires !== '') {
            // 严格数字校验：拒绝 "abc"（(int) 会宽松转成 0）、"7abc" 等
            if (!ctype_digit($expires) || !in_array((int)$expires, $allowed, true)) {
                flash('error', '有效期选项无效');
                Response::redirect(base_url('albums'));
            }
            $days = (int)$expires;
        } else {
            $days = 0;
        }
        if ($days > 0) {
            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$days} days"));
        }

        // 密码：留空 = 保留原密码；显式勾选 clear_password 才清除
        $password = trim((string)$request->post('password', ''));
        $passwordHash = (string)$folder['share_password'] !== '' ? $folder['share_password'] : null;
        if ($request->post('clear_password') !== null) {
            $passwordHash = null;
        } elseif ($password !== '') {
            if (strlen($password) < 6 || strlen($password) > 64) {
                flash('error', '访问密码需 6-64 字符');
                Response::redirect(base_url('albums'));
            }
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        }

        $repo->setShare($id, $token, $expiresAt, $passwordHash);
        flash('success', '分享已开启：' . base_url('s/' . $token));
        Response::redirect(base_url('albums'));
    }

    /** 关闭分享 */
    public function unshare(Request $request, array $params): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();
        if (!csrf_check($request->post('csrf_token', ''))) {
            flash('error', '会话已过期');
            Response::redirect(base_url('albums'));
        }

        $id = (int)($params['id'] ?? 0);
        $repo = new FolderRepository();
        $folder = $repo->find($id);
        if (!$folder || (int)$folder['user_id'] !== (int)$user['id']) {
            flash('error', '相册不存在或无权操作');
            Response::redirect(base_url('albums'));
        }

        $repo->unshare($id);
        flash('success', '分享已关闭');
        Response::redirect(base_url('albums'));
    }

    /** 相册详情：相册内图片网格 */
    public function view(Request $request, array $params): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();
        $id = (int)($params['id'] ?? 0);

        $repo = new FolderRepository();
        $folder = $repo->find($id);
        if (!$folder || (int)$folder['user_id'] !== (int)$user['id']) {
            http_response_code(404);
            echo '相册不存在或无权访问';
            return;
        }

        $page = max(1, (int)$request->query('page', 1));
        $list = (new ImageRepository())->paginate(
            ['user_id' => (int)$user['id'], 'status' => 'active', 'folder_id' => $id],
            $page,
            24
        );

        Response::view('albums/view', [
            'folder' => $folder,
            'list'   => $list,
            'csrf'   => csrf_token(),
        ], 'main');
    }

    /**
     * 批量添加图片到相册（事务）
     * POST：folder_id + image_ids[]
     */
    public function addImages(Request $request): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();
        if (!csrf_check($request->post('csrf_token', ''))) {
            flash('error', '会话已过期');
            Response::redirect(base_url('albums'));
        }

        $folderId = (int)$request->post('folder_id', 0);
        $imageIds = (array)$request->post('image_ids', []);

        $repo = new FolderRepository();
        $folder = $repo->find($folderId);
        if (!$folder || (int)$folder['user_id'] !== (int)$user['id']) {
            flash('error', '相册不存在');
            Response::redirect(base_url('albums'));
        }
        if (empty($imageIds)) {
            flash('error', '未选择任何图片');
            Response::redirect(base_url('albums/' . $folderId));
        }

        Db::beginTransaction();
        try {
            $count = (new ImageRepository())->attachToFolder($imageIds, $folderId, (int)$user['id']);
            Db::commit();
            flash('success', "已添加 {$count} 张图片到相册「{$folder['name']}」");
        } catch (\Throwable $e) {
            Db::rollBack();
            flash('error', '添加失败：' . $e->getMessage());
        }
        Response::redirect(base_url('albums/' . $folderId));
    }



    /** 单图移出相册 */
    public function removeImage(Request $request, array $params): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();
        if (!csrf_check($request->post('csrf_token', ''))) {
            flash('error', '会话已过期');
            Response::redirect(base_url('albums'));
        }

        $id = (int)($params['id'] ?? 0);
        $folderId = (int)($params['folder_id'] ?? 0);
        $repo = new FolderRepository();
        $folder = $repo->find($folderId);
        if (!$folder || (int)$folder['user_id'] !== (int)$user['id']) {
            flash('error', '相册不存在或无权操作');
            Response::redirect(base_url('albums'));
        }

        $img = (new ImageRepository())->find($id);
        if (!$img || (int)$img['user_id'] !== (int)$user['id']) {
            flash('error', '图片不存在');
            Response::redirect(base_url('albums/' . $folderId));
        }
        if ((int)$img['folder_id'] !== $folderId) {
            flash('error', '图片不在该相册中');
            Response::redirect(base_url('albums/' . $folderId));
        }

        (new ImageRepository())->move($id, null);
        flash('success', '已移出相册');
        Response::redirect(base_url('albums/' . $folderId));
    }
}
