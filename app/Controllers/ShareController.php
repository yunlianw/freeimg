<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\FolderRepository;
use App\Repositories\ImageRepository;

/**
 * 公开分享页（无需登录）
 * /s/{token}        相册分享页（可选密码/有效期）
 * /s/img/{uuid}     单图分享页
 */
class ShareController
{
    /** 相册公开页 */
    public function folder(Request $request, array $params): void
    {
        $token = (string)($params['token'] ?? '');
        $folder = (new FolderRepository())->findByShareToken($token);
        if (!$folder) {
            http_response_code(404);
            echo '分享不存在或已关闭';
            return;
        }

        // 有效期检查
        if (!empty($folder['share_expires_at']) && strtotime($folder['share_expires_at']) < time()) {
            http_response_code(404);
            echo '分享已过期';
            return;
        }

        // 密码门
        $unlocked = !empty($_SESSION['share_' . $token]);
        if (!empty($folder['share_password']) && !$unlocked) {
            $this->passwordGate($request, $folder, $token);
            return;
        }

        $list = (new ImageRepository())->paginate(
            ['folder_id' => (int)$folder['id'], 'status' => 'active'],
            1,
            200
        );

        Response::view('share/folder', [
            'folder' => $folder,
            'list'   => $list['items'],
        ], null);
    }

    /** 单图公开页 */
    public function image(Request $request, array $params): void
    {
        $uuid = (string)($params['uuid'] ?? '');
        $img = (new ImageRepository())->findByUuid($uuid);
        if (!$img || $img['status'] !== 'active' || (int)$img['is_public'] !== 1) {
            http_response_code(404);
            echo '图片不存在或未公开';
            return;
        }

        Response::view('share/image', [
            'image' => $img,
        ], null);
    }

    /** 密码验证（POST 到同路由），带失败次数限制：≥5 次锁定 15 分钟 */
    private function passwordGate(Request $request, array $folder, string $token): void
    {
        $failKey = 'share_fail_' . $token;
        $lockKey = 'share_lock_' . $token;
        $lockedUntil = $_SESSION[$lockKey] ?? 0;

        if ($lockedUntil > time()) {
            $mins = (int)ceil(($lockedUntil - time()) / 60);
            Response::view('share/password', [
                'token' => $token,
                'error' => "尝试次数过多，请 {$mins} 分钟后再试",
            ], null);
            return;
        }

        if ($request->isPost()) {
            $password = (string)$request->post('password', '');
            if (password_verify($password, (string)$folder['share_password'])) {
                unset($_SESSION[$failKey], $_SESSION[$lockKey]);
                $_SESSION['share_' . $token] = true;
                Response::redirect(base_url('s/' . $token));
            }

            $fails = (int)($_SESSION[$failKey] ?? 0) + 1;
            if ($fails >= 5) {
                $_SESSION[$lockKey] = time() + 900; // 锁定 15 分钟
                unset($_SESSION[$failKey]);
                $error = '密码错误次数过多，已锁定 15 分钟';
            } else {
                $_SESSION[$failKey] = $fails;
                $error = "密码错误，还可尝试 " . (5 - $fails) . " 次";
            }
        } else {
            $error = null;
        }

        Response::view('share/password', [
            'token' => $token,
            'error' => $error,
        ], null);
    }
}
