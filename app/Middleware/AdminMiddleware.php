<?php
namespace App\Middleware;

use App\Core\Response;
use App\Services\AuthService;

class AdminMiddleware
{
    public static function handle(): void
    {
        if (!AuthService::check()) {
            Response::redirect(base_url('login'));
        }
        if (!AuthService::admin()) {
            http_response_code(403);
            echo '<h1>403 Forbidden</h1><p>需要管理员权限</p>';
            exit;
        }
    }
}