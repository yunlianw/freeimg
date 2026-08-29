<?php
namespace App\Controllers;

use App\Core\Db;
use App\Core\Response;
use App\Repositories\ImageRepository;
use App\Repositories\FolderRepository;
use App\Services\AuthService;
use App\Middleware\AuthMiddleware;

class DashboardController
{
    public function index(): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();
        $userId = (int)$user['id'];

        $imgRepo = new ImageRepository();
        $folderRepo = new FolderRepository();

        // 统计
        $totalImages = (int)Db::fetchValue(
            'SELECT COUNT(*) FROM images WHERE user_id = :uid AND status = "active"',
            ['uid' => $userId]
        );
        $totalFolders = count($folderRepo->listByUser($userId));
        $totalSize = $imgRepo->userUsage($userId);
        $recycleCount = (int)Db::fetchValue(
            'SELECT COUNT(*) FROM images WHERE user_id = :uid AND status = "recycle"',
            ['uid' => $userId]
        );

        // 最近 8 张
        $recent = $imgRepo->paginate(['user_id' => $userId, 'status' => 'active'], 1, 8);

        Response::view('dashboard/index', [
            'stats' => [
                'images'  => $totalImages,
                'folders' => $totalFolders,
                'size'    => $totalSize,
                'recycle' => $recycleCount,
            ],
            'recent' => $recent['items'],
            'csrf'   => csrf_token(),
        ], 'main');
    }
}