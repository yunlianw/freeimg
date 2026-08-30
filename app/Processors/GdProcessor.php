<?php
namespace App\Processors;

use App\Core\Db;

/**
 * GD 图片处理器
 * 支持 JPEG / PNG / GIF / WebP / BMP
 *
 * opts 参数：
 *   - max_width   最大宽度（0=不缩放）
 *   - max_height  最大高度
 *   - quality     JPEG/WebP 质量 1-100
 *   - format      输出格式（jpeg/png/webp/gif，默认按扩展名）
 *   - strip_metadata  1=去除 EXIF 等元数据
 *   - target_size_kb  目标文件大小（会二分查找合适质量）
 *   - min_quality      最低质量（防止压太多）
 */
class GdProcessor implements ImageProcessorInterface
{
    public function driverName(): string
    {
        return 'gd';
    }

    public function info(string $file): ?array
    {
        if (!file_exists($file)) return null;
        $info = @getimagesize($file);
        if ($info === false) return null;
        return [
            'width'     => $info[0],
            'height'    => $info[1],
            'mime'      => $info['mime'],
            'extension' => $this->extFromMime($info['mime']),
        ];
    }

    public function compress(string $source, string $dest, array $opts = []): array
    {
        $info = $this->info($source);
        if (!$info) {
            return ['success' => false, 'error' => '无法读取图片'];
        }

        $srcW = $info['width'];
        $srcH = $info['height'];
        $srcMime = $info['mime'];

        // 1. 加载原图
        $srcImg = $this->loadImage($source, $srcMime);
        if (!$srcImg) {
            return ['success' => false, 'error' => '图片格式不支持'];
        }

        // 2. 计算目标尺寸（按比例缩放）
        $maxW = (int)($opts['max_width']  ?? 0);
        $maxH = (int)($opts['max_height'] ?? 0);
        [$dstW, $dstH] = $this->fitSize($srcW, $srcH, $maxW, $maxH);

        // 3. 创建画布
        $dstImg = imagecreatetruecolor($dstW, $dstH);
        if ($srcMime === 'image/png' || $srcMime === 'image/gif') {
            imagealphablending($dstImg, false);
            imagesavealpha($dstImg, true);
            $transparent = imagecolorallocatealpha($dstImg, 0, 0, 0, 127);
            imagefilledrectangle($dstImg, 0, 0, $dstW, $dstH, $transparent);
        }

        // 4. 重采样
        imagecopyresampled(
            $dstImg, $srcImg,
            0, 0, 0, 0,
            $dstW, $dstH, $srcW, $srcH
        );

        // 4.5 文字水印（在压缩后的画布上直接绘制，避免二次编码）
        if (!empty($opts['watermark']) && is_array($opts['watermark'])) {
            if (($opts['watermark']['type'] ?? 'text') === 'image') {
                $this->applyImageWatermark($dstImg, $dstW, $dstH, $opts['watermark']);
            } else {
                $this->applyWatermark($dstImg, $dstW, $dstH, $opts['watermark']);
            }
        }

        // 5. 确定输出格式 + 质量
        $ext = $opts['format'] ?? $info['extension'];
        $mime = $this->mimeFromExt($ext);
        $quality = max(1, min(100, (int)($opts['quality'] ?? 85)));
        $minQuality = max(1, min(100, (int)($opts['min_quality'] ?? 40)));

        // 6. 计算输出尺寸（实际二进制）
        // 写到临时文件后读 size，再决定要不要删
        // 注：GD 重编码本身就不写 EXIF/IPTC/XMP；strip_metadata 是死代码已删除。
        $tmpDest = $this->makeTemp($dest);
        $result = $this->saveImage($dstImg, $tmpDest, $mime, $quality);
        if (!$result) {
            return ['success' => false, 'error' => '编码失败'];
        }

        // 7. target_size_kb：二分查找合适质量（支持 JPEG + WebP + PNG）
        if (!empty($opts['target_size_kb']) && in_array($mime, ['image/jpeg', 'image/webp'], true)) {
            $targetBytes = (int)$opts['target_size_kb'] * 1024;
            $currentSize = filesize($tmpDest);
            // 只有当当前太大时才压
            if ($currentSize > $targetBytes && $quality > $minQuality) {
                $lo = $minQuality;
                $hi = $quality;
                for ($i = 0; $i < 6; $i++) {
                    $mid = (int)(($lo + $hi) / 2);
                    $this->saveImage($dstImg, $tmpDest, $mime, $mid);
                    $sz = filesize($tmpDest);
                    if ($sz <= $targetBytes) {
                        $lo = $mid;
                    } else {
                        $hi = $mid;
                    }
                    if ($hi - $lo <= 2) break;
                }
                // 用最终 lo（保证 <= target）
                $this->saveImage($dstImg, $tmpDest, $mime, $lo);
            }
        } elseif (!empty($opts['target_size_kb']) && $mime === 'image/png') {
            // PNG 特殊路径：尝试降色到 256 色 palette（损失 alpha 但压缩比暴涨）
            $targetBytes = (int)$opts['target_size_kb'] * 1024;
            $currentSize = filesize($tmpDest);
            if ($currentSize > $targetBytes) {
                // 复制图用于 palette 化（不动原图，因为可能要降级回原）
                $paletteImg = $this->cloneImage($dstImg);
                if ($paletteImg && function_exists('imagetruecolortopalette')) {
                    // 6 = 高质量 palette
                    @imagetruecolortopalette($paletteImg, true, 256);
                    // 重新存为 PNG
                    $this->saveImage($paletteImg, $tmpDest, 'image/png', 9); // zlib 9
                    $newSize = filesize($tmpDest);
                    // 如果还不够狠，扔 alpha 量化（PNG 8-bit indexed）
                    if ($newSize > $targetBytes) {
                        @imagetruecolortopalette($paletteImg, true, 64);
                        $this->saveImage($paletteImg, $tmpDest, 'image/png', 9);
                    }
                    imagedestroy($paletteImg);
                }
                // 最后兜底：zlib level 9
                $this->saveImage($dstImg, $tmpDest, 'image/png', 9);
            }
        }

        // 8. 移动到目标
        if (!@rename($tmpDest, $dest)) {
            @copy($tmpDest, $dest);
            @unlink($tmpDest);
        }

        $finalSize = filesize($dest) ?: 0;

        return [
            'success'            => true,
            'width'              => $dstW,
            'height'             => $dstH,
            'size'               => $finalSize,
            'mime'               => $mime,
            'extension'          => $ext,
            'compression_ratio'  => $srcW > 0 ? round($finalSize / max(1, filesize($source)), 4) : 1.0,
        ];
    }

    // ============= 私有方法 =============

    /**
     * 文字水印绘制
     * cfg: text/font/size/color(#hex)/opacity(1-100)/angle/position(9宫格)/margin
     * 位置: tl/tc/tr/ml/mc/mr/bl/bc/br
     */
    private function applyWatermark($img, int $w, int $h, array $cfg): void
    {
        $text = trim((string)($cfg['text'] ?? ''));
        $font = (string)($cfg['font'] ?? '');
        if ($text === '' || !file_exists($font)) return;

        $size   = max(8, min(200, (int)($cfg['size'] ?? 28)));
        $angle  = (int)($cfg['angle'] ?? 0);
        $margin = max(0, min(200, (int)($cfg['margin'] ?? 20)));
        $opacity = max(1, min(100, (int)($cfg['opacity'] ?? 50)));
        $position = (string)($cfg['position'] ?? 'br');
        $positions = ['tl','tc','tr','ml','mc','mr','bl','bc','br'];
        if (!in_array($position, $positions, true)) $position = 'br';

        // 颜色 hex → rgb
        $hex = ltrim((string)($cfg['color'] ?? '#ffffff'), '#');
        if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        // opacity 100 → alpha 0（不透明），1 → alpha 127（接近透明）
        $alpha = (int)round((100 - $opacity) * 1.27);
        $alpha = max(0, min(127, $alpha));

        // 文字包围盒（旋转后）——imagettfbbox 返回扁平 [x1,y1,x2,y2,x3,y3,x4,y4]
        $box = imagettfbbox($size, $angle, $font, $text);
        if ($box === false) return;
        $xs = [$box[0], $box[2], $box[4], $box[6]];
        $ys = [$box[1], $box[3], $box[5], $box[7]];
        $tw = (int)(max($xs) - min($xs));
        $th = (int)(max($ys) - min($ys));

        // 直接在目标图上绘制（不用透明 layer + imagecopy）
        // ⚠️ 原因：PNG/GIF 分支 dstImg 关闭了 imagealphablending，
        //    imagecopy() 会做裸像素拷贝，全画布透明背景会把整张图擦成透明
        $color = imagecolorallocatealpha($img, $r, $g, $b, $alpha);

        // 位置计算（9 宫格）
        $cols = ['l' => 0, 'c' => 1, 'r' => 2];
        $rows = ['t' => 0, 'm' => 1, 'b' => 2];
        $cx = [0 => $margin, 1 => (int)(($w - $tw) / 2), 2 => $w - $tw - $margin];
        $cy = [0 => $margin, 1 => (int)(($h - $th) / 2), 2 => $h - $th - $margin];
        $x = $cx[$cols[$position[1] ?? 'r']] ?? $cx[2];
        $y = $cy[$rows[$position[0] ?? 'b']] ?? $cy[2];

        // 基准点 = 包围盒左上角（旋转后）
        imagettftext($img, $size, $angle, $x - min($xs), $y - min($ys), $color, $font, $text);
    }

    /**
     * 图片水印绘制（透明 PNG 推荐）
     * cfg: path/size(占目标图宽度%, 5-100)/opacity(1-100)/position(9宫格)/margin
     * 位置: tl/tc/tr/ml/mc/mr/bl/bc/br
     */
    private function applyImageWatermark($img, int $w, int $h, array $cfg): void
    {
        $path = (string)($cfg['path'] ?? '');
        if ($path === '' || !file_exists($path)) return;

        $wmSrc = @imagecreatefrompng($path);
        if ($wmSrc === false) $wmSrc = @imagecreatefromjpeg($path);
        if ($wmSrc === false) $wmSrc = @imagecreatefromwebp($path);
        if ($wmSrc === false) return;

        $wmW = imagesx($wmSrc);
        $wmH = imagesy($wmSrc);

        // 水印宽度 = 目标图宽度 × size%，等比缩放
        $size = max(5, min(100, (int)($cfg['size'] ?? 20)));
        $dstWmW = (int)max(8, $w * $size / 100);
        $dstWmH = (int)max(1, $wmH * $dstWmW / $wmW);

        $wmLayer = imagecreatetruecolor($dstWmW, $dstWmH);
        imagealphablending($wmLayer, false);
        imagesavealpha($wmLayer, true);
        $transparent = imagecolorallocatealpha($wmLayer, 0, 0, 0, 127);
        imagefilledrectangle($wmLayer, 0, 0, $dstWmW, $dstWmH, $transparent);
        imagecopyresampled($wmLayer, $wmSrc, 0, 0, 0, 0, $dstWmW, $dstWmH, $wmW, $wmH);

        // 透明度调整（非 100 时对整个图层调 alpha）
        $opacity = max(1, min(100, (int)($cfg['opacity'] ?? 80)));
        if ($opacity < 100) {
            imagefilter($wmLayer, IMG_FILTER_COLORIZE, 0, 0, 0, (int)round((100 - $opacity) * 1.27));
        }

        // 9 宫格定位（与文字水印一致）
        $margin = max(0, min(200, (int)($cfg['margin'] ?? 15)));
        $position = (string)($cfg['position'] ?? 'br');
        $positions = ['tl','tc','tr','ml','mc','mr','bl','bc','br'];
        if (!in_array($position, $positions, true)) $position = 'br';
        $cols = ['l' => 0, 'c' => 1, 'r' => 2];
        $rows = ['t' => 0, 'm' => 1, 'b' => 2];
        $cx = [0 => $margin, 1 => (int)(($w - $dstWmW) / 2), 2 => $w - $dstWmW - $margin];
        $cy = [0 => $margin, 1 => (int)(($h - $dstWmH) / 2), 2 => $h - $dstWmH - $margin];
        $x = max(0, $cx[$cols[$position[1] ?? 'r']] ?? $cx[2]);
        $y = max(0, $cy[$rows[$position[0] ?? 'b']] ?? $cy[2]);

        // 合并到目标图：PNG/GIF 分支 dstImg 关闭了 imagealphablending，
        // imagecopy 裸像素拷贝会把水印层透明像素直接替换目标背景 → 先临时开 blending
        $wasBlending = imagealphablending($img, true);
        imagecopy($img, $wmLayer, $x, $y, 0, 0, $dstWmW, $dstWmH);
        imagealphablending($img, $wasBlending);
    }

    private function cloneImage($img)
    {
        // 用 imagepalettetruecolor 之前需要创建副本
        $w = imagesx($img);
        $h = imagesy($img);
        $copy = imagecreatetruecolor($w, $h);
        if (!$copy) return null;
        imagealphablending($copy, false);
        imagesavealpha($copy, true);
        $transparent = imagecolorallocatealpha($copy, 0, 0, 0, 127);
        imagefilledrectangle($copy, 0, 0, $w, $h, $transparent);
        imagecopy($copy, $img, 0, 0, 0, 0, $w, $h);
        return $copy;
    }

    private function loadImage(string $file, string $mime)
    {
        switch ($mime) {
            case 'image/jpeg': return @imagecreatefromjpeg($file);
            case 'image/png':  return @imagecreatefrompng($file);
            case 'image/gif':  return @imagecreatefromgif($file);
            case 'image/webp': return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : false;
            case 'image/bmp':  return function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($file) : false;
        }
        return false;
    }

    private function saveImage($img, string $file, string $mime, int $quality): bool
    {
        switch ($mime) {
            case 'image/jpeg': return imagejpeg($img, $file, $quality);
            case 'image/png':
                $level = (int)round((100 - $quality) / 11);
                return imagepng($img, $file, max(0, min(9, $level)));
            case 'image/gif':  return imagegif($img, $file);
            case 'image/bmp':
                if (function_exists('imagebmp')) return imagebmp($img, $file);
                return imagejpeg($img, preg_replace('/\.bmp$/i', '.jpg', $file), $quality);
            case 'image/webp': return function_exists('imagewebp') ? imagewebp($img, $file, $quality) : imagejpeg($img, $file, $quality);
        }
        return false;
    }

    private function makeTemp(string $hint): string
    {
        $dir = dirname($hint);
        $name = '.tmp_' . bin2hex(random_bytes(8)) . '_' . time();
        return $dir . '/' . $name;
    }

    private function fitSize(int $srcW, int $srcH, int $maxW, int $maxH): array
    {
        if ($maxW <= 0 && $maxH <= 0) return [$srcW, $srcH];
        if ($maxW > 0 && $srcW <= $maxW && ($maxH <= 0 || $srcH <= $maxH)) {
            return [$srcW, $srcH];
        }
        $ratio = $srcW / $srcH;
        if ($maxW > 0 && $maxH > 0) {
            if ($maxW / $maxH > $ratio) {
                $dstH = $maxH;
                $dstW = (int)round($maxH * $ratio);
            } else {
                $dstW = $maxW;
                $dstH = (int)round($maxW / $ratio);
            }
        } elseif ($maxW > 0) {
            $dstW = $maxW;
            $dstH = (int)round($maxW / $ratio);
        } else {
            $dstH = $maxH;
            $dstW = (int)round($maxH * $ratio);
        }
        return [$dstW, $dstH];
    }

    private function extFromMime(string $mime): string
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

    private function mimeFromExt(string $ext): string
    {
        $map = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'bmp'  => 'image/bmp',
        ];
        return $map[strtolower($ext)] ?? 'image/jpeg';
    }
}
