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
        // _local_file：仅内部/CLI 使用（测试脚本、服务端导入），REST API 不会传入此参数，
        // 用于绕过 is_uploaded_file 检查直接处理服务器本地文件
        if (!is_uploaded_file($tmp) && empty($opts['_local_file'])) {
            return $this->fail('非法的上传文件');
        }

        $originalName = $this->sanitizeName($file['name'] ?? 'image');
        // Phase 9.3: 浏览器压缩后上传 → 前端传 original_size（真正的原图字节数）
        // 安全：original_size 不允许小于实际收到内容大小（防伪造绕过限制）
        // 也不允许超过 maxSize 的 2 倍（防恶意虚增导致 ratio 荒谬）
        $content = file_get_contents($tmp);
        if ($content === false || $content === '') {
            return $this->fail('读取上传文件失败');
        }
        $realSize = strlen($content);
        $claimedOrig = !empty($opts['original_size']) ? (int)$opts['original_size'] : 0;
        // 大小上限优先读后台"上传设置→最大文件大小"（MB），未配置时回落到 config.php
        $maxSize = (int)(config('settings.upload_max_size') ?? 0);
        $maxSize = $maxSize > 0 ? $maxSize * 1024 * 1024 : (int)(config('upload.max_size') ?? 10 * 1024 * 1024);
        if ($claimedOrig > 0 && $claimedOrig < $realSize) {
            // 声称的原图比实际内容还小 → 伪造，忽略
            $claimedOrig = 0;
        }
        if ($claimedOrig > $maxSize * 2) {
            // 声称原图超过限制 2 倍 → 视为伪造，忽略
            $claimedOrig = 0;
        }
        $originalSize = $claimedOrig > 0 ? $claimedOrig : $realSize;

        $realMime = $this->detectMime($tmp, $content);
        if (!$this->isAllowedMime($realMime)) {
            return $this->fail('不允许的图片格式: ' . $realMime . '（当前允许：' . $this->allowedTypesHint() . '）');
        }

        // Phase 9.3: 大小限制基于实际收到的内容（realSize），而不是声称的原图大小
        // 浏览器 double 模式：原图可能 > maxSize，但前端压缩后的 blob 可能 < maxSize
        // 若限制原图大小会误拒本可通过的压缩上传
        if ($realSize > $maxSize) {
            return $this->fail('文件大小超过限制 (max=' . round($maxSize / 1024 / 1024, 1) . 'MB)');
        }

        $sha256 = hash('sha256', $content);
        // force_recompress=1：跳过 SHA256 去重，允许同一张图按不同压缩档重新压缩出多份记录
        if (empty($opts['force_recompress'])) {
            $exist = $this->images->findBySha256($sha256, $userId);
            if ($exist) {
                return [
                    'success' => true,
                    'duplicate' => true,
                    'image'   => $exist,
                    'message' => '已存在相同图片，已返回原记录',
                ];
            }
        }

        $qualityLevel = $opts['quality'] ?? (config('settings.default_compression') ?: 'saver');  // v1.3.8: 与 Installer seed 一致
        // 本次实际使用的压缩档代码（写库 compression 字段）：
        // 有 profile → 用 profile.code；否则用 quality 档代码（original/saver/extreme/ultra...）
        $compressionCode = !empty($opts['_compression_profile'])
            ? (string)($opts['_compression_profile']['code'] ?? $qualityLevel)
            : (string)$qualityLevel;

        // 从 Profile 拿参数（如果提供）
        if (!empty($opts['_compression_profile'])) {
            $profile = $opts['_compression_profile'];
            $maxW = (int)$profile['max_dimension'];
            $maxH = 0; // Profile 只配宽
            // output_format: auto/jpg/webp/png — 决定是否切换到 WebP（更小30%）
            $of = (string)($profile['output_format'] ?? 'auto');
            if ($of === 'webp') {
                $quality = (int)$profile['webp_quality']; // webp 档时用 webp_quality
                $format = 'webp';
            } elseif ($of === 'jpg') {
                $quality = (int)$profile['jpeg_quality'];
                $format = 'jpg';
            } elseif ($of === 'png') {
                $quality = (int)$profile['png_compression'];
                $format = 'png';
            } else {
                // auto：保留原图格式
                $quality = (int)$profile['jpeg_quality'];
                $format = null;
            }
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

        // 安全：像素炸弹防护——超大尺寸图片会在 GD 解码时耗尽内存（DoS）
        // getimagesize 只读头部，解码发生在压缩链中；这里先按宽高乘积拦截
        // 默认 16MP：128M memory_limit 下 GD 解码临界安全（再大会 OOM）
        // 用除法形式避免 $srcW*$srcH 溢出后转 float 的语义依赖
        $maxPixels = (int)(config('upload.max_pixels') ?? 16 * 1024 * 1024);
        if ($maxPixels <= 0) $maxPixels = 16 * 1024 * 1024; // 防止配错值导致所有图片被拒
        $srcW = (int)($imageInfo['width'] ?? 0);
        $srcH = (int)($imageInfo['height'] ?? 0);
        if ($srcW > 0 && $srcH > 0 && $srcW > (int)($maxPixels / $srcH)) {
            @unlink($tempSrc);
            return $this->fail('图片尺寸过大（超过 ' . round($maxPixels / 1024 / 1024, 1) . 'MP 像素上限）');
        }

        $ext = $format ?: $imageInfo['extension'];

        $prefix = trim((string)(config('settings.url_path_prefix') ?: 'img'), '/');
        $prefix = preg_replace('/[^a-zA-Z0-9\/_-]/', '', $prefix); // 支持多级目录（如 img/tu）
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
        // Phase 9.3: 浏览器已压缩（skip_compress=1）→ 跳过压缩分支
        $skipByBrowser = !empty($opts['skip_compress']);
        // 后台开启水印时：即使 skip_compress 也走 chain（但仅加水印，不缩放不降质）
        $forceWatermark = !empty($opts['_force_watermark']);
        $isOriginal = (($opts['quality'] ?? '') === 'original' || $profileIsOriginal || $skipByBrowser) && !$forceWatermark;

        if ($isOriginal) {
            $storedContent = $content;
            $tmpInfo = $this->makeTempFile($storedContent, $realMime);
            $origInfo = $this->processor->info($tmpInfo);
            @unlink($tmpInfo);

            $finalSize = strlen($storedContent);
            $finalMime = $realMime;
            $finalExt = $imageInfo['extension'] ?: $ext;
            // 宽高优先用已成功的 imageInfo（$tempSrc 已确认可解析），
            // origInfo 解析失败时兜底，避免 0×0
            $width = ($origInfo['width'] ?? 0) > 0 ? (int)$origInfo['width'] : (int)($imageInfo['width'] ?? 0);
            $height = ($origInfo['height'] ?? 0) > 0 ? (int)$origInfo['height'] : (int)($imageInfo['height'] ?? 0);
            $ratio = $originalSize > 0 ? round($finalSize / $originalSize, 4) : 1.0;
            // skip_compress（浏览器压缩）→ compressor 标 browser，source 标 browser
            $compressor = $skipByBrowser ? 'browser' : 'original';
            $compSource = $skipByBrowser ? 'browser' : 'none';
            $preserved = true;
            @unlink($tempSrc);
            // 原图档：保留原图字节（不缩放、不重编码、不剥 EXIF）
            // 用户显式选「原图」=知情同意保留 EXIF（适合做图床、保留原始元数据）
            // strip_exif 默认行为：仅对走 chain 压缩的档位生效（保护缩略图/压缩档）
            // skipByBrowser（前端已压缩）也尊重用户意图：保留原字节
        } else {
            // ========== Phase 9.2：CompressionChain 统一入口 ==========
            $chain = new \App\Processors\CompressionChain();
            // Phase 9.3: 浏览器已压缩 + 仅需水印 → 不缩放（max_width=0），
            // quality 用 85（视觉无损、避免 q92 膨胀），只重编码一次以应用水印
            $chainMaxW = $skipByBrowser ? 0 : $maxW;
            $chainQuality = $skipByBrowser ? 85 : $quality;
            // v1.3.9: 双重压缩"取小"方案 — 前端已压过图（含 EXIF 已被 canvas 剥掉），
            // 不再走 strip_exif 强制 q92 重写（会偷偷把前端小图撑大到 150~250KB）
            $inputFromBrowser = $skipByBrowser || (!empty($opts['original_size']) && (int)$opts['original_size'] !== $realSize);
            $chainResult = $chain->process($tempSrc, $realMime, !empty($opts['_api_compress']) ? 'api-server' : 'browser', [
                'max_width'       => $chainMaxW,
                'max_height'      => $skipByBrowser ? 0 : $maxH,
                'quality'         => $chainQuality,           // 默认（JPEG/WebP）
                'jpeg_quality'    => (int)($profile['jpeg_quality'] ?? $chainQuality),
                'webp_quality'    => (int)($profile['webp_quality'] ?? $chainQuality),
                'png_compression' => (int)($profile['png_compression'] ?? 6),
                'png_quality_min' => (int)($profile['png_quality_min'] ?? 40),
                'png_quality_max' => (int)($profile['png_quality_max'] ?? 80),
                'min_quality'     => (int)($profile['minimum_quality'] ?? 30),
                'target_size_kb' => (int)($profile['target_size_kb'] ?? 0),
                // output_format: auto/jpg/webp/png — WebP 比 JPEG 小30%
                'output_format'   => (string)($profile['output_format'] ?? 'auto'),
                // strip_metadata 已废弃：GD 重编码本身就不写 EXIF/IPTC/XMP（GdProcessor 删了死代码）。
                // 用 strip_exif（chain 层）统一控制剥 EXIF。
                // v1.3.9: 前端已压缩的图（double 模式）→ 不剥 EXIF，保留前端小体积
                'strip_exif'      => $inputFromBrowser ? 0 : (int)(config('settings.strip_exif') ?? 1),
                'watermark'       => WatermarkConfigResolver::resolve(),
            ]);
            // 重要：不要在这里 unlink($tempSrc)！chain 复制了 tempSrc 到自己的 tempIn 工作目录
            // 上传方（browser）请求结束后 php-fpm 会自动清理 tempSrc，我们不能碰

            if (!$chainResult['success']) {
                @unlink($tempSrc);
                $chain->cleanup($chainResult['output_path'] ?? null);
                return $this->fail('压缩失败: ' . ($chainResult['error'] ?? '未知错误'));
            }

            // 从 final 路径读回（chain 复制了 inputFile 到 tempIn，所有产物在 tempIn 命名空间）
            $storedContent = file_get_contents($chainResult['output_path']);
            // 让 chain 清理临时文件（final 和 tempIn 和 .cmp）
            $chain->cleanup($chainResult['output_path']);
            // 清理自建输入副本（makeTempFile 产物 PHP 不会自动清理）
            @unlink($tempSrc);

            $finalSize   = (int)($chainResult['size'] ?? 0);
            $finalMime   = $chainResult['mime'] ?? $realMime;
            $finalExt    = $chainResult['extension'] ?? $ext;
            // 宽高：chain 未返回时（preserve 原图分支）用已成功的 $imageInfo 兜底，避免 0×0
            $width       = (int)($chainResult['width'] ?? 0) > 0
                ? (int)$chainResult['width']
                : (int)($imageInfo['width'] ?? 0);
            $height      = (int)($chainResult['height'] ?? 0) > 0
                ? (int)$chainResult['height']
                : (int)($imageInfo['height'] ?? 0);
            $ratio       = (float)($chainResult['compression_ratio'] ?? 1.0);
            $compressor  = $chainResult['compressor'] ?? 'original';
            $compSource  = $chainResult['compression_source'] ?? 'none';
            $preserved   = !empty($chainResult['preserved_original']);
            // Phase 9.3: 浏览器链路（skip_compress 或 double 模式）语义修正
            //  - skip_compress：最终文件是浏览器压缩结果 → 无条件标 browser
            //  - double 后端压不动保留原 blob：同样是浏览器结果 → 标 browser
            //  - double 后端真正压缩：保持 gd（compressor 指最终处理者）
            // ratio 一律基于真实原图大小（final / 原图），而非上传 blob
            $isBrowserChain = $skipByBrowser || ($originalSize > 0 && $originalSize !== $realSize);
            if ($isBrowserChain) {
                if ($skipByBrowser || $compressor === 'original' || $preserved) {
                    $compressor = 'browser';
                    $compSource = 'browser';
                }
                if ($originalSize > 0) {
                    $ratio = round($finalSize / $originalSize, 4);
                }
            }
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
            'compression'        => $compressionCode,
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

        // 水印强一致：后台开启水印时，API 强制走水印分支
        // 例外：
        // 1. 调试模式（_debug_no_watermark=1）：跳过强制水印
        // 2. 原图档（profile=original）：用户显式选原图 = 不要任何处理（包括水印），原图/原图档=字节级原图
        $profileCode = (string)($opts['_compression_profile']['code'] ?? '');
        $isOriginalProfile = $profileCode === 'original';
        if (WatermarkConfigResolver::resolve() !== null
            && empty($opts['_debug_no_watermark'])
            && !$isOriginalProfile) {
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
        // 与 compression_profiles 表内置档保持一致（small 已更名 saver，保留 small 别名向后兼容）
        $preset = [
            'original' => [0, 0, 100, null],
            'high'     => [2048, 0, 85, null],
            'balanced' => [1600, 0, 70, null],
            'saver'    => [1100, 0, 45, null],
            'small'    => [1100, 0, 45, null], // 向后兼容：small = saver
            'extreme'  => [800, 0, 30, null],
            'ultra'    => [600, 0, 20, null],
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
        // 后台"上传设置→允许的文件类型"作为附加白名单（与硬性黑名单取交集，双向收紧）
        $allowed = config('settings.upload_allowed_types');
        if (!empty($allowed)) {
            $extByMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/bmp' => 'bmp'];
            $ext = $extByMime[$mime] ?? null;
            if ($ext === null) {
                return false; // 不在白名单映射内的格式一律拒绝
            }
            $list = array_map('trim', explode(',', strtolower((string)$allowed)));
            if (!in_array($ext, $list, true) && !in_array($mime === 'image/jpeg' ? 'jpeg' : $ext, $list, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * 上传白名单错误信息（供外部 catch 后格式化）
     */
    public function allowedTypesHint(): string
    {
        $allowed = config('settings.upload_allowed_types');
        if (empty($allowed)) {
            return 'jpg, jpeg, png, gif, webp, bmp';
        }
        return trim((string)$allowed);
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
