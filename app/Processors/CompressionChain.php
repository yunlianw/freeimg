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

        // GIF：原样保留（不动图）
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
                'output_path' => $tempIn,
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

        if ($realMime === 'image/png') {
            $result = $this->pngquant->compress($tempIn, $tmpDest, $opts);
        } elseif ($realMime === 'image/jpeg') {
            $result = $this->gd->compress($tempIn, $tmpDest, $opts);
        } elseif ($realMime === 'image/webp') {
            $result = $this->gd->compress($tempIn, $tmpDest, $opts);
        } else {
            // 未知 MIME → fallback 用原文件
            @unlink($tmpDest);
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

        // 处理器失败：原样保留
        if (empty($result['success'])) {
            @unlink($tmpDest);
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
        $cmpSize = (int)($result['size'] ?? 0);
        if ($cmpSize >= $srcSize) {
            @unlink($tmpDest);
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
     * 清理 chain 临时文件（在 caller 读完 output_path 后调用）
     * @param string|null $outputPath
     */
    public function cleanup(?string $outputPath): void
    {
        if ($outputPath && file_exists($outputPath)) {
            @unlink($outputPath);
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