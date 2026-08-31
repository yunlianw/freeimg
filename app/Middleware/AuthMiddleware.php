<?php
namespace App\Middleware;

use App\Core\Response;
use App\Services\AuthService;
use App\Services\SessionService;

class AuthMiddleware
{
    public static function handle(): void
    {
        if (!AuthService::check()) {
            if (self::isAjax()) {
                Response::json(['error' => 'Unauthorized'], 401);
            }
            Response::redirect(base_url('login'));
        }

        // DB-backed 会话验证 + 滑动过期
        $token = $_SESSION['session_token'] ?? '';
        if ($token) {
            $row = SessionService::findValid($token);
            if (!$row) {
                // 会话过期或被销毁
                AuthService::logout();
                if (self::isAjax()) {
                    Response::json(['error' => 'Session expired'], 401);
                }
                flash('error', '会话已过期，请重新登录');
                Response::redirect(base_url('login'));
            }
            // 滑动过期（每分钟最多刷新一次避免频繁写）
            $lastTouch = strtotime($row['last_activity_at']);
            if (time() - $lastTouch > 60) {
                SessionService::touch($token);
            }
        }
    }

    private static function isAjax(): bool
    {
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') return true;
        // 任何期望 JSON 响应的请求都视为 Ajax（fetch 默认 Accept 含 */*，但前端若显式 json 则 ajax）
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (stripos($accept, 'application/json') !== false) return true;
        return false;
    }
}