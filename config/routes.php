<?php
/**
 * 路由配置
 */

use App\Core\Request;
use App\Core\Router;
use App\Controllers\AuthController;
use App\Controllers\SettingController;
use App\Controllers\UploadController;
use App\Controllers\ImageController;
use App\Controllers\FolderController;
use App\Controllers\StorageBrowseController;
use App\Middleware\AuthMiddleware;

$router = new Router();

// 首页
$router->get('/', function () {
    if (\App\Services\AuthService::check()) {
        \App\Core\Response::redirect(base_url('dashboard'));
    }
    \App\Core\Response::redirect(base_url('login'));
});

// 登录
$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/login/2fa', [AuthController::class, 'show2fa']);
$router->post('/login/2fa', [AuthController::class, 'verify2fa']);
$router->post('/logout', [AuthController::class, 'logout']);
$router->get('/logout', function () {
    \App\Core\Response::redirect(base_url('login'));
});

// 安全中心
$router->get('/security', [\App\Controllers\SecurityController::class, 'profile']);
$router->get('/security/profile', [\App\Controllers\SecurityController::class, 'profile']);
$router->post('/security/profile', [\App\Controllers\SecurityController::class, 'updateProfile']);
$router->get('/security/2fa', [\App\Controllers\SecurityController::class, 'twofa']);
$router->post('/security/2fa/setup', [\App\Controllers\SecurityController::class, 'setupTotp']);
$router->post('/security/2fa/enable', [\App\Controllers\SecurityController::class, 'enableTotp']);
$router->post('/security/2fa/disable', [\App\Controllers\SecurityController::class, 'disableTotp']);
$router->get('/security/password', [\App\Controllers\SecurityController::class, 'password']);
$router->post('/security/password', [\App\Controllers\SecurityController::class, 'changePassword']);
$router->get('/security/sessions', [\App\Controllers\SecurityController::class, 'sessions']);
$router->post('/security/sessions/{token}/destroy', [\App\Controllers\SecurityController::class, 'destroySession']);
$router->get('/security/login-logs', [\App\Controllers\SecurityController::class, 'loginLogs']);
// 安全策略（管理员）
$router->get('/security/policy', [\App\Controllers\SecurityPolicyController::class, 'policy']);
$router->post('/security/policy', [\App\Controllers\SecurityPolicyController::class, 'savePolicy']);

// Dashboard
$router->get('/dashboard', function () {
    AuthMiddleware::handle();
    (new \App\Controllers\DashboardController())->index();
});

// 设置
$router->get('/settings', function () {
    AuthMiddleware::handle();
    (new SettingController())->index();
});
$router->post('/settings', function (Request $req) {
    AuthMiddleware::handle();
    (new SettingController())->save($req);
});

// 存储驱动管理
$router->get('/storages', function () {
    AuthMiddleware::handle();
    (new \App\Controllers\StorageController())->index();
});
$router->get('/storages/form', function (Request $req) {
    AuthMiddleware::handle();
    (new \App\Controllers\StorageController())->form($req);
});
$router->post('/storages/save', function (Request $req) {
    AuthMiddleware::handle();
    (new \App\Controllers\StorageController())->save($req);
});
$router->post('/storages/toggle', function (Request $req) {
    AuthMiddleware::handle();
    (new \App\Controllers\StorageController())->toggle($req);
});
$router->post('/storages/default', function (Request $req) {
    AuthMiddleware::handle();
    (new \App\Controllers\StorageController())->setDefault($req);
});
$router->post('/storages/delete', function (Request $req) {
    AuthMiddleware::handle();
    (new \App\Controllers\StorageController())->delete($req);
});
$router->post('/storages/test', function (Request $req) {
    AuthMiddleware::handle();
    (new \App\Controllers\StorageController())->test($req);
});
$router->post('/storages/recalc', function (Request $req) {
    AuthMiddleware::handle();
    (new \App\Controllers\StorageController())->recalc($req);
});

// 上传
$router->get('/upload', [UploadController::class, 'page']);
$router->post('/upload', [UploadController::class, 'handle']);

// 图片管理
$router->get('/images', [ImageController::class, 'index']);
$router->get('/images/{id}', [ImageController::class, 'show']);
$router->post('/images/{id}/rename', [ImageController::class, 'rename']);
$router->post('/images/{id}/trash', [ImageController::class, 'trash']);
$router->post('/images/{id}/restore', [ImageController::class, 'restore']);
$router->post('/images/{id}/destroy', [ImageController::class, 'destroy']);
$router->post('/images/{id}/move', [ImageController::class, 'move']);

// 批量操作
$router->post('/images/batch-trash', [ImageController::class, 'batchTrash']);
$router->post('/images/batch-destroy', [\App\Controllers\BatchImageController::class, 'batchDestroy']);
$router->post('/images/empty-recycle', [\App\Controllers\BatchImageController::class, 'emptyRecycle']);

// 文件夹
$router->get('/folders', [FolderController::class, 'index']);
$router->post('/folders', [FolderController::class, 'create']);
$router->post('/folders/{id}/rename', [FolderController::class, 'rename']);
$router->post('/folders/{id}/delete', [FolderController::class, 'delete']);

// 相册（Phase 6）
$router->get('/albums', [\App\Controllers\AlbumController::class, 'index']);
$router->post('/albums/create', [\App\Controllers\AlbumController::class, 'create']);
$router->post('/albums/{id}/rename', [\App\Controllers\AlbumController::class, 'rename']);
$router->post('/albums/{id}/delete', [\App\Controllers\AlbumController::class, 'delete']);
$router->post('/albums/{id}/share', [\App\Controllers\AlbumController::class, 'share']);
$router->post('/albums/{id}/unshare', [\App\Controllers\AlbumController::class, 'unshare']);
$router->post('/albums/{folder_id}/remove/{id}', [\App\Controllers\AlbumController::class, 'removeImage']);
// 相册添加图片（picker 必须放在 {id} 之前，避免被吞）
$router->get('/albums/picker', [\App\Controllers\AlbumPickerController::class, 'picker']);
$router->post('/albums/{id}/add-images', [\App\Controllers\AlbumController::class, 'addImages']);
$router->get('/albums/{id}', [\App\Controllers\AlbumController::class, 'view']);

// 公开分享页（无需登录）
$router->get('/s/{token}', [\App\Controllers\ShareController::class, 'folder']);
$router->post('/s/{token}', [\App\Controllers\ShareController::class, 'folder']);
$router->get('/s/img/{uuid}', [\App\Controllers\ShareController::class, 'image']);

// 存储浏览 API（动态列子目录）
$router->get('/api/storage/dirs', [StorageBrowseController::class, 'dirs']);

// 存储扫描与清理
$router->get('/api/storage/scan', [\App\Controllers\StorageScanController::class, 'scan']);
$router->post('/api/storage/cleanup', [\App\Controllers\StorageScanController::class, 'cleanup']);
$router->post('/api/storage/cleanup-records', [\App\Controllers\StorageScanController::class, 'cleanupRecords']);
$router->get('/api/csrf', [\App\Controllers\StorageScanController::class, 'csrf']);

// API Key 管理（后台）
$router->get('/api-keys', [\App\Controllers\ApiKeyController::class, 'index']);
$router->post('/api-keys', [\App\Controllers\ApiKeyController::class, 'create']);
$router->post('/api-keys/toggle', [\App\Controllers\ApiKeyController::class, 'toggle']);
$router->post('/api-keys/delete', [\App\Controllers\ApiKeyController::class, 'delete']);
$router->post('/api-keys/edit', [\App\Controllers\ApiKeyController::class, 'edit']);

// 压缩配置（后台）
$router->get('/compression', function () {
    \App\Middleware\AuthMiddleware::handle();
    (new \App\Controllers\CompressionController())->index();
});
$router->post('/compression/create', function (Request $req) {
    \App\Middleware\AuthMiddleware::handle();
    (new \App\Controllers\CompressionController())->create($req);
});
$router->post('/compression/update', function (Request $req) {
    \App\Middleware\AuthMiddleware::handle();
    (new \App\Controllers\CompressionController())->update($req);
});
$router->post('/compression/toggle', function (Request $req) {
    \App\Middleware\AuthMiddleware::handle();
    (new \App\Controllers\CompressionController())->toggle($req);
});
$router->post('/compression/delete', function (Request $req) {
    \App\Middleware\AuthMiddleware::handle();
    (new \App\Controllers\CompressionController())->delete($req);
});
$router->post('/compression/defaults', function (Request $req) {
    \App\Middleware\AuthMiddleware::handle();
    (new \App\Controllers\CompressionController())->defaults($req);
});

// REST API（公开，无需 session）
// === REST API（外部调用，鉴权 Bearer ACCESS_KEY:SECRET_KEY）===
$router->post('/api/v1/upload', [\App\Controllers\RestApiController::class, 'upload']);
$router->get('/api/v1/upload', [\App\Controllers\RestApiController::class, 'uploadInfo']);
$router->get('/api/v1/images', [\App\Controllers\RestApiController::class, 'listImages']);
$router->get('/api/v1/images/{id}', [\App\Controllers\RestApiController::class, 'getImage']);
$router->post('/api/v1/images/{id}/delete', [\App\Controllers\RestApiController::class, 'deleteImage']);
$router->delete('/api/v1/images/{id}', [\App\Controllers\RestApiController::class, 'deleteImage']);
$router->get('/api/v1/folders', [\App\Controllers\RestApiController::class, 'listFolders']);

// 回收站
$router->get('/recycle', function () {
    \App\Core\Response::redirect(base_url('images?status=recycle'));
});

// 健康检查
$router->get('/health', function () {
    \App\Core\Response::json(['status' => 'ok', 'time' => date('c')]);
});

$router->dispatch(new Request());