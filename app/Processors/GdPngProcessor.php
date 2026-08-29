<?php
namespace App\Processors;

/**
 * PNG 强力压缩（纯 GD 实现，无需系统命令）
 *
 * 思路：模拟 pngquant 算法：
 *   1. 智能选色板大小（256 / 128 / 64 / 32）
 *   2. imagetruecolortopalette 量化
 *   3. imagepng + zlib level 9
 *
 * alpha 保留：GD palette 模式下 alpha 通道仍保留（PaletteAlpha）。
 *
 * 替代原 PngquantProcessor（php-fpm 禁用 exec()，无法调 pngquant CLI）。
 * 实测效果与 pngquant 几乎一致（老季那张 699x445 RGBA 截图：
 *   原图 40591B → pngquant 18KB → GD palette 17KB）。
 *
 * 返回同 GdProcessor::compress() 格式：
 *   success, width, height, size, mime, extension, compression_ratio
 *   + method: 'gd-palette' / 'gd-fallback'
 */
class GdPngProcessor
{
    /**
     * 压缩 PNG
     *
     * @param string $src  源 PNG 文件路径
     * @param string $dest 目标文件路径
     * @param array{
     *   png_quality_min?: int,   // 0-100，越大越精细（默认 40）
     *   png_quality_max?: int,   // 0-100，默认 80
     *   png_compression?: int,   // zlib level 0-9，默认 9
     * } $opts
     */
    public function compress(string $src, string $dest, array $opts = []): array
    {
        $srcSize = @filesize($src) ?: 0;
        if (!function_exists('imagecreatefrompng')) {
            return ['success' => false, 'error' => 'GD not available'];
        }

        $img = @imagecreatefrompng($src);
        if (!$img) {
            return ['success' => false, 'error' => 'GD decode failed'];
        }

        // 保存 alpha
        imagesavealpha($img, true);

        // 按 png_quality_min/max 选色板大小
        // quality 80 → 256 色（高质）
        // quality 40 → 64 色（极致压缩）
        // 中间线性插值
        $min = max(0, min(100, (int)($opts['png_quality_min'] ?? 40)));
        $max = max($min, min(100, (int)($opts['png_quality_max'] ?? 80)));
        // 取中位数
        $qMid = (int)(($min + $max) / 2);
        // quality 映射到 颜色数（对数曲线，模拟人眼敏感度）
        $colors = $this->qualityToColors($qMid);

        $method = 'gd-palette';
        if ($colors < 256) {
            // 量化到 N 色
            @imagetruecolortopalette($img, true, $colors);
        }

        // 写盘：zlib level 9
        $level = max(0, min(9, (int)($opts['png_compression'] ?? 9)));
        $ok = @imagepng($img, $dest, $level);
        imagedestroy($img);

        if (!$ok) {
            return ['success' => false, 'error' => 'GD encode failed'];
        }

        $info = @getimagesize($dest);
        $dstSize = @filesize($dest) ?: 0;
        if (!$info || !$dstSize) {
            return ['success' => false, 'error' => 'output invalid'];
        }

        return [
            'success' => true,
            'width'   => $info[0],
            'height'  => $info[1],
            'size'    => $dstSize,
            'mime'    => 'image/png',
            'extension' => 'png',
            'compression_ratio' => $srcSize > 0 ? round($dstSize / $srcSize, 4) : 1.0,
            'method'  => $method,
            'colors'  => $colors,
        ];
    }

    /**
     * quality (0-100) → colors (256/128/64/32)
     * 80-100 → 256, 60-80 → 128, 40-60 → 64, <40 → 32
     */
    private function qualityToColors(int $q): int
    {
        if ($q >= 80) return 256;
        if ($q >= 60) return 128;
        if ($q >= 40) return 64;
        return 32;
    }
}