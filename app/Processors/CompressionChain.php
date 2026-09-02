<?php
namespace App\Processors;

use App\Core\Db;

/**
 * 压缩策略链
 *
 * 流程：
 *   1. detectMime（已由 UploadService 完成，传 realMime 入）
 *   2. 按 MIME 分发：
 *      - JPEG/WEBP  → GdProcessor::compress() + target_size 二分
 *      - PNG         → GdPngProcessor + GD fallback
 *      - GIF         → 原样保留（不做压缩）
 *   3. 比大小：压缩后 >= 原图 → 保留原图
 *   4. 返回统一结果（含 compressor / source）
 *
 * 返回结构：
 *   success, width, height, size, mime, extension, compression_ratio,
 *   compressor,     // pngquant / gd / cwebp / original
 *   compression_source, // browser / api-server / none
 *   preserved_original, // true/false
 */
class CompressionChain
{
    private GdProcessor $gd;
    private GdPngProcessor $pngquant;

    public function __construct()
    {
        $this->gd = new GdProcessor();
        $this->pngquant = new GdPngProcessor();
    }

    /**
     * 主处理入口
     *
     * @param string $inputFile  源文件（必存在）
     * @param string $realMime   UploadService::detectMime() 探测的真实 MIME
     * @param string $source     压缩源：browser / api-server
     * @param array{
     *   profile?: array,
     *   max_dimension?: int,
     *   jpeg_quality?: int,
     *   webp_quality?: int,
     *   png_compression?: int,
     *   png_quality_min?: int,
     *   png_quality_max?: int,
     *   png_speed?: int,
     *   target_size_kb?: int,
     *   min_quality?: int,
     *   strip_metadata?: bool,
     *   watermark?: array,
     * } $opts
     * @return array{
     *   success: bool,
     *   width?: int,
     *   height?: int,
     *   size?: int,
     *   mime?: string,
     *   extension?: string,
     *   compression_ratio?: float,
     *   compressor?: string,
     *   compression_source?: string,
     *   preserved_original?: bool,
     *   output_path?: string,
     *   error?: string,
     * }
     */
    public function process(string $inputFile, string $realMime, string $source, array $opts = []): array
    {
        if (!file_exists($inputFile)) {
            return ['success' => false, 'error' => 'input file missing'];
        }

        $srcSize = filesize($inputFile);

        // GIF：原样保留（不动图，避免丢动画帧）
        // 注意：$inputFile 在 chain 同步调用期间始终有效（$_FILES 临时文件请求结束才清理）
        // 除非 strip_exif 强制开启：GIF 没有标准 EXIF 段（只有 GIF 注释），重绘会丢动画，所以仍原样返回
        if ($realMime === 'image/gif') {
            return [
                'success' => true,
                'size'    => $srcSize,
                'mime'    => 'image/gif',
                'extension' => 'gif',
                'compression_ratio' => 1.0,
                'compressor' => 'original',
                'compression_source' => 'none',
                'preserved_original' => true,
                'output_path' => $inputFile,
            ];
        }

        // 关键：把 inputFile 复制到 chain 自管的临时文件
        // 原因：inputFile 可能是 PHP 上传临时文件（$_FILES['tmp_name']），
        // fpm 请求结束会自动清理；如果我们依赖它在 chain 处理期间还在，会被外部清理掉
        $tempIn = sys_get_temp_dir() . '/freeimg_chain_' . bin2hex(random_bytes(8)) . '.' . $this->mimeToExt($realMime);
        if (!@copy($inputFile, $tempIn)) {
            return ['success' => false, 'error' => 'failed to copy input'];
        }

        // 选处理器
        $tmpDest = $tempIn . '.cmp';
        $result = null;

        // 选处理器
        // 水印开启时：PNG 也必须走 GdProcessor（GdPngProcessor 不画水印）
        // output_format=webp/jpg 时：PNG 也要走 GdProcessor 才能切换输出格式（GdPngProcessor 只产 PNG）
        $hasWatermark = !empty($opts['watermark']) && is_array($opts['watermark']);
        $needsFormatSwitch = !empty($opts['output_format']) && $opts['output_format'] !== 'auto' && $opts['output_format'] !== 'png';
        if ($realMime === 'image/png') {
            $result = ($hasWatermark || $needsFormatSwitch)
                ? $this->gd->compress($tempIn, $tmpDest, $opts)
                : $this->pngquant->compress($tempIn, $tmpDest, $opts);
        } elseif ($realMime === 'image/jpeg') {
            $result = $this->gd->compress($tempIn, $tmpDest, $opts);
        } elseif ($realMime === 'image/webp') {
            $result = $this->gd->compress($tempIn, $tmpDest, $opts);
        } else {
            // 未知 MIME（bmp 等）：水印开启时尝试 GD 兜底（GD 支持 bmp 读写）
            if ($hasWatermark) {
                $result = $this->gd->compress($tempIn, $tmpDest, $opts);
            } else {
                $result = null;
            }
            if (empty($result['success'])) {
                @unlink($tmpDest);
                // P1-1 修复：strip_exif 开启时仍尝试 GD 重绘剥 EXIF（BMP 等未知 MIME 不能直接跳过）
                if (!empty($opts['strip_exif'])) {
                    $rw = $this->stripExifRewrite($tempIn, $realMime);
                    if ($rw['success']) {
                        $finalPath = $tempIn . '.final';
                        @rename($rw['output_path'], $finalPath);
                        return [
                            'success' => true,
                            'width'   => (int)($rw['width'] ?? 0),
                            'height'  => (int)($rw['height'] ?? 0),
                            'size'    => (int)($rw['size'] ?? 0),
                            'mime'    => $realMime,
                            'extension' => $this->mimeToExt($realMime),
                            'compression_ratio' => $srcSize > 0 ? round((int)($rw['size'] ?? 0) / $srcSize, 4) : 1.0,
                            'compressor' => 'gd-rewrite',
                            'compression_source' => $source,
                            'preserved_original' => false,
                            'output_path' => $finalPath,
                        ];
                    }
                }
                return [
                    'success' => true,
                    'size'    => $srcSize,
                    'mime'    => $realMime,
                    'extension' => $this->mimeToExt($realMime),
                    'compression_ratio' => 1.0,
                    'compressor' => 'original',
                    'compression_source' => 'none',
                    'preserved_original' => true,
                    'output_path' => $tempIn,
                ];
            }
        }

        // 处理器失败：原样保留
        // 但如果 strip_exif 开启且该 MIME 支持 GD 重绘 → 仍尝试剥 EXIF
        if (empty($result['success'])) {
            @unlink($tmpDest);
            if (!empty($opts['strip_exif']) && in_array($realMime, ['image/jpeg', 'image/png', 'image/webp', 'image/bmp'], true)) {
                $rewriteResult = $this->stripExifRewrite($tempIn, $realMime);
                if ($rewriteResult['success']) {
                    $finalPath = $tempIn . '.final';
                    @rename($rewriteResult['output_path'], $finalPath);
                    return [
                        'success' => true,
                        'width'   => (int)($rewriteResult['width'] ?? 0),
                        'height'  => (int)($rewriteResult['height'] ?? 0),
                        'size'    => (int)($rewriteResult['size'] ?? 0),
                        'mime'    => $realMime,
                        'extension' => $this->mimeToExt($realMime),
                        'compression_ratio' => $srcSize > 0 ? round((int)($rewriteResult['size'] ?? 0) / $srcSize, 4) : 1.0,
                        'compressor' => 'gd-rewrite',
                        'compression_source' => $source,
                        'preserved_original' => false,
                        'output_path' => $finalPath,
                    ];
                }
            }
            return [
                'success' => true,
                'size'    => $srcSize,
                'mime'    => $realMime,
                'extension' => $this->mimeToExt($realMime),
                'compression_ratio' => 1.0,
                'compressor' => 'original',
                'compression_source' => 'none',
                'preserved_original' => true,
                'output_path' => $tempIn,
            ];
        }

        // 比大小：压缩后 >= 原图 → 保留原图
        // 例外：启用水印时不能保留原图（水印已画在 .cmp 上，保留原图=丢水印）
        // strip_exif 不影响大小判断：保留原图 = EXIF 自然不会泄露（用户上传时 EXIF 本就保留在原图副本路径里）
        // v1.3.9: 上游 inputFromBrowser=1（double 模式前端已压）→ strip_exif=0 已传，$stripExif=false，
        //          下面 stripExifRewrite 不会跑，"取小"方案生效
        $cmpSize = (int)($result['size'] ?? 0);
        $hasWatermark = !empty($opts['watermark']) && is_array($opts['watermark']);
        $stripExif = !empty($opts['strip_exif']);
        if ($cmpSize >= $srcSize && !$hasWatermark) {
            @unlink($tmpDest);
            // P0 安全修复：保留原图前若 strip_exif 开启 → 必须先剥 EXIF（GPS/设备信息会泄露）
            // v1.3.9: 上游已判定 inputFromBrowser→0，$stripExif 为 false，本块跳过 → 保留前端小图
            if ($stripExif && in_array($realMime, ['image/jpeg', 'image/png', 'image/webp', 'image/bmp'], true)) {
                $rw = $this->stripExifRewrite($tempIn, $realMime);
                if ($rw['success']) {
                    $finalPath = $tempIn . '.final';
                    @rename($rw['output_path'], $finalPath);
                    return [
                        'success' => true,
                        'width'   => (int)($rw['width'] ?? 0),
                        'height'  => (int)($rw['height'] ?? 0),
                        'size'    => (int)($rw['size'] ?? 0),
                        'mime'    => $realMime,
                        'extension' => $this->mimeToExt($realMime),
                        'compression_ratio' => $srcSize > 0 ? round((int)($rw['size'] ?? 0) / $srcSize, 4) : 1.0,
                        'compressor' => 'gd-rewrite',
                        'compression_source' => $source,
                        'preserved_original' => false, // 已重绘剥 EXIF，不是原图
                        'output_path' => $finalPath,
                    ];
                }
                // stripExifRewrite 失败 → 兜底：拒绝保留带 EXIF 的原图（安全优先于可用性）
                return ['success' => false, 'error' => 'strip_exif 开启但重绘失败，拒绝保留含 EXIF 的原图'];
            }
            return [
                'success' => true,
                'size'    => $srcSize,
                'mime'    => $realMime,
                'extension' => $this->mimeToExt($realMime),
                'compression_ratio' => 1.0,
                'compressor' => 'original',
                'compression_source' => 'none',
                'preserved_original' => true,
                'output_path' => $tempIn,
            ];
        }

        // strip_exif 开启但 GdProcessor 已被 strip_metadata 选项处理（产物在 tmpDest 已剥 EXIF），
        // 此时正常走下方"移到 final"流程即可。
        // strip_exif 兜底：若 GdProcessor 没产出文件（BMP/未知 MIME 走到 else 分支返回 preserved 时），
        // chain 不会到这里；只有当 GdProcessor 真正失败但 strip_exif 又要剥时：
        if ($stripExif && !file_exists($tmpDest)) {
            $rewriteResult = $this->stripExifRewrite($tempIn, $realMime);
            if ($rewriteResult['success']) {
                // stripExifRewrite 写到 $tempIn.noexif，搬到 .final
                $finalPath = $tempIn . '.final';
                @rename($rewriteResult['output_path'], $finalPath);
                return [
                    'success' => true,
                    'width'   => (int)($rewriteResult['width'] ?? 0),
                    'height'  => (int)($rewriteResult['height'] ?? 0),
                    'size'    => (int)($rewriteResult['size'] ?? 0),
                    'mime'    => $realMime,
                    'extension' => $this->mimeToExt($realMime),
                    'compression_ratio' => $srcSize > 0 ? round((int)($rewriteResult['size'] ?? 0) / $srcSize, 4) : 1.0,
                    'compressor' => 'gd-rewrite',
                    'compression_source' => $source,
                    'preserved_original' => false,
                    'output_path' => $finalPath,
                ];
            }
            // P1-3 修复：rewrite 失败时直接返回 preserved_original（不要继续走到下方 rename/copy 不存在的 $tmpDest）
            return [
                'success' => true,
                'size'    => $srcSize,
                'mime'    => $realMime,
                'extension' => $this->mimeToExt($realMime),
                'compression_ratio' => 1.0,
                'compressor' => 'original',
                'compression_source' => 'none',
                'preserved_original' => true,
                'output_path' => $tempIn,
            ];
        }

        // 压缩成功：移到目标
        if (!@rename($tmpDest, $tempIn . '.final')) {
            // rename 失败 → 复制
            @copy($tmpDest, $tempIn . '.final');
            @unlink($tmpDest);
        }
        $finalPath = $tempIn . '.final';

        return [
            'success' => true,
            'width'   => (int)($result['width'] ?? 0),
            'height'  => (int)($result['height'] ?? 0),
            'size'    => $cmpSize,
            'mime'    => $result['mime'] ?? $realMime,
            'extension' => $result['extension'] ?? $this->mimeToExt($realMime),
            'compression_ratio' => $srcSize > 0 ? round($cmpSize / $srcSize, 4) : 1.0,
            'compressor' => $result['method'] ?? 'gd',  // pngquant / gd-fallback / gd
            'compression_source' => $source,           // browser / api-server
            'preserved_original' => false,
            'output_path' => $finalPath,
        ];
    }

    /**
     * GD 重绘剥 EXIF（当链不压缩时仍要执行 strip_exif）
     *
     * 原理：imagecreatefrom* + image* 重绘只保留像素和必要的色彩信息，
     * 不会写入 EXIF/IPTC/XMP 等元数据段。
     *
     * 注意：PNG 透明通道会保留（imagecreatefrompng 解析 alpha 通道）；
     *      BMP 没有标准 EXIF 段，重绘基本无变化但仍走一遍。
     *
     * @return array{success:bool, width?:int, height?:int, size?:int, output_path?:string, error?:string}
     */
    public function stripExifRewritePublic(string $inputFile, string $mime): array
    {
        return $this->stripExifRewrite($inputFile, $mime);
    }

    private function stripExifRewrite(string $inputFile, string $mime): array
    {
        $img = null;
        switch ($mime) {
            case 'image/jpeg': $img = @imagecreatefromjpeg($inputFile); break;
            case 'image/png':  $img = @imagecreatefrompng($inputFile);
                if ($img !== false && $img !== null) {
                    // PNG 透明支持
                    imagealphablending($img, false);
                    imagesavealpha($img, true);
                }
                break;
            case 'image/webp': $img = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($inputFile) : false;
            if ($img !== false && $img !== null) {
                imagealphablending($img, false);
                imagesavealpha($img, true);
            }
            break;
            case 'image/bmp':  $img = function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($inputFile) : false; break;
        }
        if ($img === false || $img === null) {
            return ['success' => false, 'error' => 'GD decode failed'];
        }

        $w = imagesx($img);
        $h = imagesy($img);

        // 写到新文件（不覆盖原 tempIn，避免污染）
        $newPath = $inputFile . '.noexif';
        $ok = false;
        switch ($mime) {
            case 'image/jpeg': $ok = imagejpeg($img, $newPath, 92); break;
            case 'image/png':  $ok = imagepng($img, $newPath, 6); break;
            case 'image/webp': $ok = function_exists('imagewebp') ? imagewebp($img, $newPath, 85) : imagejpeg($img, $newPath, 92); break;
            case 'image/bmp':  $ok = function_exists('imagebmp') ? imagebmp($img, $newPath) : imagejpeg($img, preg_replace('/\.bmp$/i', '.jpg', $newPath), 92); break;
        }
        // PHP 8.0+ GD 资源自动释放，无需 imagedestroy

        if (!$ok) {
            return ['success' => false, 'error' => 'GD encode failed'];
        }

        return [
            'success' => true,
            'width'   => $w,
            'height'  => $h,
            'size'    => filesize($newPath),
            'output_path' => $newPath,
        ];
    }

    /**
     * 清理 chain 临时文件（在 caller 读完 output_path 后调用）
     * @param string|null $outputPath
     */
    public function cleanup(?string $outputPath): void
    {
        if (!$outputPath) return;
        // 删 final 产物
        if (file_exists($outputPath)) {
            @unlink($outputPath);
        }
        // 删 tempIn 输入副本（.final 去掉后缀即是）
        $tempIn = preg_replace('/\.final$/', '', $outputPath);
        if ($tempIn !== $outputPath && file_exists($tempIn)) {
            @unlink($tempIn);
        }
        // 删 .cmp 中间产物
        $cmp = $tempIn . '.cmp';
        if (file_exists($cmp)) {
            @unlink($cmp);
        }
        // 删 .noexif 兜底产物（rename 失败时可能残留）
        $noExif = $tempIn . '.noexif';
        if (file_exists($noExif)) {
            @unlink($noExif);
        }
    }

    /**
     * MIME → 扩展名
     */
    private function mimeToExt(string $mime): string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            'image/bmp'  => 'bmp',
        ];
        return $map[$mime] ?? 'jpg';
    }
}