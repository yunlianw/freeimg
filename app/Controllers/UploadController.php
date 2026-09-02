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

        // Phase 9.3: 浏览器上传压缩模式（double=双重 / browser=仅浏览器 / backend=仅后端）
        $browserMode = (string)config('settings.browser_upload_mode', 'browser');
        if (!in_array($browserMode, ['double', 'browser', 'backend'], true)) {
            $browserMode = 'browser';
        }

        // v1.3.8 重做：上传页 quality 下拉根据 browser_mode 切换选项
        // - browser/double 模式：用前端 QUALITY_PRESETS 5 档（独立体系，JS 5 档对应）
        // - backend 模式：用后端 compression_profiles 全档（按 code 查后端）
        // desc 必须严格跟 public/assets/upload.js QUALITY_PRESETS 数值一致！
        $browserPresets = [
            ['code' => 'original', 'name' => '原图', 'desc' => '不压缩'],
            ['code' => 'high',     'name' => '高清', 'desc' => '2048px / 0.85'],
            ['code' => 'balanced', 'name' => '⭐ 均衡', 'desc' => '1600px / 0.70'],
            ['code' => 'saver',    'name' => '省流', 'desc' => '1200px / 0.55'],
            ['code' => 'extreme',  'name' => '极限省流', 'desc' => '900px / 0.40'],
        ];
        $serverProfiles = \App\Core\Db::fetchAll(
            'SELECT id, code, name, max_dimension, jpeg_quality, output_format, target_size_kb
             FROM compression_profiles WHERE enabled = 1 ORDER BY sort_order ASC, id ASC'
        );

        // 默认档：backend 模式用 web_compression_profile_id 对应 code，browser/double 模式用 settings.default_compression
        if ($browserMode === 'backend') {
            $webProfileId = (int)config('settings.web_compression_profile_id', 0);
            $webProfile = $webProfileId > 0
                ? \App\Core\Db::fetchOne('SELECT code FROM compression_profiles WHERE id = :id AND enabled = 1', ['id' => $webProfileId])
                : null;
            $defaultQuality = $webProfile['code'] ?? 'saver';
        } else {
            // v1.3.9: browser/double 模式读 settings.default_compression（白名单 = 前端 5 档）
            // 之前 v1.3.8 写死 'balanced'，导致后台改了没用
            // v1.3.9.1: 兜底从 'balanced' 改 'saver'，跟 UploadService L86 + Installer seed 一致
            // 老库没该行（upgrade.php 从未补种过）会触发兜底，统一 saver 避免 UI/实际档位不一致
            $defaultQuality = (string)config('settings.default_compression', 'saver');
            if (!in_array($defaultQuality, ['original', 'high', 'balanced', 'saver', 'extreme'], true)) {
                $defaultQuality = 'saver';
            }
        }

        Response::view('upload/index', [
            'csrf'    => csrf_token(),
            'folders' => $folders,
            'albums'  => $folders,
            'visible_storages' => $visibleStorages,
            'default_quality'  => $defaultQuality,
            'browser_presets'  => $browserPresets,
            'server_profiles'  => $serverProfiles,
            'browser_mode'     => $browserMode,
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

        // Phase 9.3: 浏览器上传压缩模式
        //  - browser: 前端压缩 → skip_compress=1（后端不动，除非水印）
        //  - double : 前端压缩 + 后端按 Web 默认档位再压（双重）
        //  - backend: 原图直传 → 后端按 Web 默认档位压
        $browserMode = (string)config('settings.browser_upload_mode', 'browser');
        if (!in_array($browserMode, ['double', 'browser', 'backend'], true)) {
            $browserMode = 'browser';
        }
        $opts = [
            // v1.3.8: 默认 '' — JS dirty 方案要求不传 = '' = 走 web 默认档，'balanced' 会让 dirty 闭环失效
            'quality'    => $request->post('quality', ''),
            'max_width'  => (int)$request->post('max_width', 0),
            'max_height' => (int)$request->post('max_height', 0),
            'skip_compress'  => $browserMode === 'browser' && $request->post('skip_compress') === '1',
            'original_size'  => (int)$request->post('original_size', 0),
            'folder_id'  => $this->normalizeFolderId($request->post('folder_id')),
            'is_public'  => (int)$request->post('is_public', 1),
            'subdir'     => trim((string)$request->post('custom_path', ''), '/'),
            'keep_name'  => $request->post('keep_name') === '1' || $request->post('keep_name') === 'true',
            'expires_at' => $request->post('expires_at', ''),
            'tags'       => array_filter(array_map('trim', explode(',', (string)$request->post('tags', '')))),
            'storage_id' => (int)$request->post('storage_id', 0),  // Phase 8: 用户可手动选存储
        ];

        // double/backend 模式：后端压缩
        // v1.3.8 重做：用户在前端 quality 选的具体档优先（按 code 查 DB），没传或查不到才用 Web 默认档
        // - JS 用 qualityDirty 标记，只在用户改了 quality 时才传 quality
        // - 后端不再有 'balanced' 特例（防止 backend 模式 balanced 档不可达）
        if ($browserMode !== 'browser') {
            $userQuality = (string)($opts['quality'] ?? '');
            $userProfile = null;
            if ($userQuality !== '') {
                // 用户选了具体档 → 查 DB
                $userProfile = \App\Core\Db::fetchOne(
                    'SELECT * FROM compression_profiles WHERE code = :c AND enabled = 1 LIMIT 1',
                    ['c' => $userQuality]
                );
            }
            if (!$userProfile) {
                // 兜底：web_compression_profile_id（settings 里的 Web 默认档）
                $webId = (int)config('settings.web_compression_profile_id', 0);
                if ($webId > 0) {
                    $userProfile = \App\Core\Db::fetchOne(
                        'SELECT * FROM compression_profiles WHERE id = :id AND enabled = 1',
                        ['id' => $webId]
                    );
                }
                if (!$userProfile) {
                    // 终极兜底：balanced 内置档
                    $userProfile = \App\Core\Db::fetchOne(
                        "SELECT * FROM compression_profiles WHERE code = 'balanced' AND enabled = 1 ORDER BY id ASC LIMIT 1"
                    );
                }
            }
            if (!empty($userProfile['id'])) {
                $opts['_compression_profile'] = $userProfile;
                $opts['quality'] = $userProfile['code'] ?? $opts['quality'];
            }
        }

        // 校验 folder 存在且属于当前用户（避免孤儿引用）
        if ($opts['folder_id'] !== null) {
            $folder = (new FolderRepository())->find($opts['folder_id']);
            if (!$folder || (int)$folder['user_id'] !== (int)$user['id']) {
                Response::json(['success' => false, 'message' => '目标文件夹不存在或无权访问'], 400);
            }
        }

        // Phase 9.3: 后台开启水印时，浏览器上传同样强制加水印（防 skip_compress 绕过）
        if (\App\Services\WatermarkConfigResolver::resolve() !== null) {
            $opts['_force_watermark'] = true;
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