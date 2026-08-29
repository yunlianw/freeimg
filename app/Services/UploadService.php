<?php
namespace App\Services;

use App\Drivers\StorageManager;
use App\Processors\GdProcessor;
use App\Processors\ImageProcessorInterface;
use App\Repositories\ImageRepository;
use App\Repositories\FolderRepository;
use App\Core\Db;

class UploadService
{
    private ImageRepository $images;
    private FolderRepository $folders;
    private ImageProcessorInterface $processor;

    public function __construct()
    {
        $this->images = new ImageRepository();
        $this->folders = new FolderRepository();
        $this->processor = new GdProcessor();
    }

    public function upload(array $file, int $userId, array $opts = []): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->fail('上传失败 (code=' . ($file['error'] ?? '?') . ')');
        }

        $tmp = $file['tmp_name'] ?? '';
        if (!is_uploaded_file($tmp)) {
            return $this->fail('非法的上传文件');
        }

        $originalName = $this->sanitizeName($file['name'] ?? 'image');
        $originalSize = (int)$file['size'];
        $content = file_get_contents($tmp);
        if ($content === false || $content === '') {
            return $this->fail('读取上传文件失败');
        }

        $realMime = $this->detectMime($tmp, $content);
        if (!$this->isAllowedMime($realMime)) {
            return $this->fail('不允许的图片格式: ' . $realMime);
        }

        $maxSize = (int)(config('upload.max_size') ?? 10 * 1024 * 1024);
        if ($originalSize > $maxSize) {
            return $this->fail('文件大小超过限制 (max=' . round($maxSize / 1024 / 1024, 1) . 'MB)');
        }

        $sha256 = hash('sha256', $content);
        $exist = $this->images->findBySha256($sha256, $userId);
        if ($exist) {
            return [
                'success' => true,
                'duplicate' => true,
                'image'   => $exist,
                'message' => '已存在相同图片，已返回原记录',
            ];
        }

        $isApiCompress = !empty($opts['_api_compress']);
        $qualityLevel = $opts['quality'] ?? 'balanced';

        // 从 Profile 拿参数（如果提供）
        if (!empty($opts['_compression_profile'])) {
            $profile = $opts['_compression_profile'];
            $maxW = (int)$profile['max_dimension'];
            $maxH = 0; // Profile 只配宽
            $quality = (int)$profile['jpeg_quality'];
            $format = null;
        } else {
            [$maxW, $maxH, $quality, $format] = $this->resolveQuality($qualityLevel, $opts);
        }

        try {
            // 智能选存储：用户指定优先，否则按 priority 自动选
            [$storage, $storageRow] = StorageManager::pickForUpload(
                $userId,
                !empty($opts['storage_id']) ? (int)$opts['storage_id'] : null
            );
            $storageId = (int)$storageRow['id'];
        } catch (\Throwable $e) {
            return $this->fail('存储未配置：' . $e->getMessage());
        }

        $tempSrc = $this->makeTempFile($content, $realMime);
        $imageInfo = $this->processor->info($tempSrc);
        if (!$imageInfo) {
            @unlink($tempSrc);
            return $this->fail('无法解析图片信息');
        }

        $ext = $format ?: $imageInfo['extension'];

        $prefix = trim((string)(config('settings.url_path_prefix') ?: 'img'), '/');
        $prefix = preg_replace('/[^a-zA-Z0-9\/_-]/', '', $prefix); // 保留斜杠（支持多级前缀如 rest/new）
        if ($prefix === '') $prefix = 'img';

        $subdir = trim((string)($opts['subdir'] ?? ''), '/');
        $subdir = preg_replace('/[^a-zA-Z0-9\/_-]/', '', $subdir);
        // 未手动指定子目录时，应用全局目录规则（按日期/自定义）
        if ($subdir === '') {
            $subdir = FileNameService::dirRule((string)config('settings.dir_rule'), (string)$originalName);
        }

        $filename = FileNameService::build((string)config('settings.rename_rule'), $ext, (string)$originalName);
        if (!empty($opts['keep_name']) && $originalName) {
            $filename = FileNameService::safeBaseName((string)$originalName) ?: $filename;
        }
        // 最终文件名截断，防超长文件名（占位符展开后可能超 255 字节）
        $filename = mb_substr($filename, 0, 180, 'UTF-8');
        $filename = trim($filename, '._');
        if ($filename === '') $filename = FileNameService::short();

        $storagePath = $subdir !== ''
            ? sprintf('%s/%s/%s.%s', $prefix, $subdir, $filename, $ext)
            : sprintf('%s/%s.%s', $prefix, $filename, $ext);

        $profileIsOriginal = !empty($opts['_compression_profile']) && $opts['_compression_profile']['code'] === 'original';
        $isOriginal = ($opts['quality'] ?? '') === 'original' || $profileIsOriginal;
        // 后台开启水印时强制走压缩分支（确保水印生效，方案 A）
        if (!empty($opts['_force_watermark'])) {
            $isOriginal = false;
        }

        if ($isOriginal) {
            $storedContent = $content;
            $tmpInfo = $this->makeTempFile($storedContent, $realMime);
            $origInfo = $this->processor->info($tmpInfo);
            @unlink($tmpInfo);

            $finalSize = strlen($storedContent);
            $finalMime = $realMime;
            $finalExt = $imageInfo['extension'] ?: $ext;
            $width = $origInfo['width'] ?? 0;
            $height = $origInfo['height'] ?? 0;
            $ratio = 1.0;
            $compressor = 'original';
            $compSource = 'none';
            $preserved = true;
            @unlink($tempSrc);
        } else {
            // ========== Phase 9.2：CompressionChain 统一入口 ==========
            $chain = new \App\Processors\CompressionChain();
            $chainResult = $chain->process($tempSrc, $realMime, !empty($opts['_api_upload']) ? 'api-server' : 'browser', [
                'max_width'       => $maxW,
                'max_height'      => $maxH,
                'quality'         => $quality,           // 默认（JPEG/WebP）
                'jpeg_quality'    => (int)($profile['jpeg_quality'] ?? $quality),
                'webp_quality'    => (int)($profile['webp_quality'] ?? $quality),
                'png_compression' => (int)($profile['png_compression'] ?? 6),
                'png_quality_min' => (int)($profile['png_quality_min'] ?? 40),
                'png_quality_max' => (int)($profile['png_quality_max'] ?? 80),
                'min_quality'     => (int)($profile['minimum_quality'] ?? 30),
                'target_size_kb' => (int)($profile['target_size_kb'] ?? 0),
                'strip_metadata'  => (int)($profile['strip_metadata'] ?? 1),
                'watermark'       => WatermarkConfigResolver::resolve(),
            ]);
            // 重要：不要在这里 unlink($tempSrc)！chain 复制了 tempSrc 到自己的 tempIn 工作目录
            // 上传方（browser）请求结束后 php-fpm 会自动清理 tempSrc，我们不能碰

            if (!$chainResult['success']) {
                return $this->fail('压缩失败: ' . ($chainResult['error'] ?? '未知错误'));
            }

            // 从 final 路径读回（chain 复制了 inputFile 到 tempIn，所有产物在 tempIn 命名空间）
            $storedContent = file_get_contents($chainResult['output_path']);
            // 让 chain 清理临时文件（final 和 tempIn）
            $chain->cleanup($chainResult['output_path']);
            // 同时清理 .cmp 中间产物
            if (!empty($chainResult['output_path'])) {
                @unlink(str_replace('.final', '.cmp', $chainResult['output_path']));
            }

            $finalSize   = (int)($chainResult['size'] ?? 0);
            $finalMime   = $chainResult['mime'] ?? $realMime;
            $finalExt    = $chainResult['extension'] ?? $ext;
            $width       = (int)($chainResult['width'] ?? 0);
            $height      = (int)($chainResult['height'] ?? 0);
            $ratio       = (float)($chainResult['compression_ratio'] ?? 1.0);
            $compressor  = $chainResult['compressor'] ?? 'original';
            $compSource  = $chainResult['compression_source'] ?? 'none';
            $preserved   = !empty($chainResult['preserved_original']);
        }

        if ($storedContent === false) {
            return $this->fail('读取结果失败');
        }
        // 防并发覆盖：目标文件已存在时重新生成文件名重试（≤5 次），仍冲突则报错
        $attempt = 0;
        while ($attempt < 5 && $storage->exists($storagePath)) {
            $attempt++;
            $filename = FileNameService::build((string)config('settings.rename_rule'), $ext, (string)$originalName);
            $filename = mb_substr($filename, 0, 180, 'UTF-8');
            $filename = trim($filename, '._');
            if ($filename === '') $filename = FileNameService::short();
            $storagePath = $subdir !== ''
                ? sprintf('%s/%s/%s.%s', $prefix, $subdir, $filename, $ext)
                : sprintf('%s/%s.%s', $prefix, $filename, $ext);
        }
        if ($storage->exists($storagePath)) {
            return $this->fail('文件名冲突，请重试');
        }
        if (!$storage->put($storagePath, $storedContent)) {
            return $this->fail('写入存储失败');
        }

        $publicUrl = $storage->url($storagePath);
        $uuidForDb = uuid();

        $imageId = $this->images->create([
            'uuid'               => $uuidForDb,
            'user_id'            => $userId,
            'storage_id'         => $storageId,
            // 强制写 folder_id 字段（即使 null），避免 schema default=' ' 覆盖
            'folder_id'          => isset($opts['folder_id']) && (int)$opts['folder_id'] > 0 ? (int)$opts['folder_id'] : null,
            'original_name'      => $originalName,
            'stored_name'        => $filename . '.' . $finalExt,
            'extension'          => $finalExt,
            'mime_type'          => $finalMime,
            'width'              => $width,
            'height'             => $height,
            'original_size'      => $originalSize,
            'final_size'         => $finalSize,
            'compression_ratio'  => $ratio,
            'sha256'             => $sha256,
            'storage_path'       => $storagePath,
            'public_url'         => $publicUrl,
            'status'             => 'active',
            'is_public'          => isset($opts['is_public']) ? (int)!!$opts['is_public'] : 1,
            'expires_at'         => !empty($opts['expires_at']) ? date('Y-m-d H:i:s', strtotime($opts['expires_at'])) : null,
            'original_mime'      => $realMime,
            'original_extension' => $ext,
            'compressor'         => $compressor ?? 'original',
            'compression_source' => $compSource ?? 'none',
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
        ]);

        if (!empty($opts['tags']) && is_array($opts['tags'])) {
            $this->images->addTags($imageId, array_filter(array_map('trim', $opts['tags'])));
        }

        // 累加存储容量
        StorageManager::addUsage($storageId, $finalSize);
        $this->logUpload($userId, null, $imageId, $originalName, $originalSize, 'success', null);

        return [
            'success' => true,
            'duplicate' => false,
            'image' => $this->images->find($imageId),
            'message' => '上传成功',
        ];
    }

    public function uploadBatch(array $files, int $userId, array $opts = []): array
    {
        $results = [];
        foreach ($this->normalizeBatch($files) as $f) {
            $results[] = $this->upload($f, $userId, $opts);
        }
        return $results;
    }

    public function uploadForApi(array $file, int $userId, array $opts = [], ?array $apiKey = null): array
    {
        $profileRepo = new \App\Repositories\CompressionProfileRepository();
        $profile = $profileRepo->resolve($opts, $apiKey);
        if (!empty($profile)) {
            $opts['_api_compress'] = true;
            $opts['_compression_profile'] = $profile;
            $opts['quality'] = $profile['code'];
        }

        // 水印强一致：后台开启水印时，API 即使选"原图"也强制走水印分支
        if (WatermarkConfigResolver::resolve() !== null) {
            $opts['_force_watermark'] = true;
        }

        return $this->upload($file, $userId, $opts);
    }

    public function uploadBatchForApi(array $files, int $userId, array $opts = [], ?array $apiKey = null): array
    {
        $results = [];
        foreach ($this->normalizeBatch($files) as $f) {
            $results[] = $this->uploadForApi($f, $userId, $opts, $apiKey);
        }
        return $results;
    }

    /** 批量上传文件规范化（兼容单文件/多文件两种表单结构） */
    private function normalizeBatch(array $files): array
    {
        $flat = [];
        foreach ($files as $fileSet) {
            if (is_array($fileSet['name'] ?? null)) {
                $count = count($fileSet['name']);
                for ($i = 0; $i < $count; $i++) {
                    $flat[] = [
                        'name'     => $fileSet['name'][$i],
                        'tmp_name' => $fileSet['tmp_name'][$i],
                        'size'     => $fileSet['size'][$i],
                        'error'    => $fileSet['error'][$i],
                        'type'     => $fileSet['type'][$i] ?? '',
                    ];
                }
            } else {
                $flat[] = $fileSet;
            }
        }
        return $flat;
    }

    private function resolveQuality(string $level, array $opts): array
    {
        $preset = [
            'original' => [0, 0, 100, null],
            'high'     => [2048, 0, 85, null],
            'balanced' => [1600, 0, 70, null],
            'saver'    => [1200, 0, 55, null],
            'extreme'  => [900, 0, 40, null],
        ];

        if (!empty($opts['max_width']) && isset($opts['quality'])) {
            $quality = (int)$opts['quality'];
            if ($quality < 1 || $quality > 100) {
                $quality = 70;
            }
            return [(int)$opts['max_width'], 0, $quality, $opts['format'] ?? null];
        }

        return $preset[$level] ?? $preset['balanced'];
    }

    private function detectMime(string $file, string $content): string
    {
        $mime = 'application/octet-stream';

        if (function_exists('getimagesize')) {
            $info = @getimagesize($file);
            if ($info && !empty($info['mime']) && str_starts_with($info['mime'], 'image/')) {
                return $info['mime'];
            }
        }

        if (function_exists('finfo_open') && ($finfo = @finfo_open(FILEINFO_MIME_TYPE))) {
            $detected = @finfo_file($finfo, $file);
            @finfo_close($finfo);
            if ($detected) $mime = $detected;
        }

        return $mime;
    }

    private function isAllowedMime(string $mime): bool
    {
        if (!str_starts_with($mime, 'image/')) return false;
        $forbidden = ['php', 'phtml', 'phar', 'html', 'js', 'svg'];
        foreach ($forbidden as $f) {
            if (str_contains($mime, $f)) return false;
        }
        return true;
    }

    private function sanitizeName(string $name): string
    {
        $name = preg_replace('/[^\w\-\.]/u', '_', $name);
        return mb_substr($name ?? 'image', 0, 200);
    }

    private function makeTempFile(string $content, string $mime): string
    {
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/bmp' => 'bmp'][$mime] ?? 'jpg';
        $tmp = sys_get_temp_dir() . '/freeimg_' . bin2hex(random_bytes(8)) . '.' . $ext;
        file_put_contents($tmp, $content);
        return $tmp;
    }

    private function fail(string $msg): array
    {
        return ['success' => false, 'message' => $msg];
    }

    private function logUpload(int $userId, ?int $apiKeyId, ?int $imageId, string $name, int $size, string $status, ?string $error): void
    {
        Db::insert('upload_logs', [
            'user_id'    => $userId,
            'api_key_id' => $apiKeyId,
            'image_id'   => $imageId,
            'filename'   => $name,
            'size'       => $size,
            'status'     => $status,
            'error_msg'  => $error,
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
