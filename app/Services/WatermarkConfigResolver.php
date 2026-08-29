<?php
namespace App\Services;

/**
 * 上传辅助：水印配置解析
 * 图片水印优先，其次文字水印；未启用 / 资源缺失返回 null
 */
class WatermarkConfigResolver
{
    public static function resolve(): ?array
    {
        $logo = FREEIMG_ROOT . '/public/storage/watermark/logo.png';
        if ((int)config('settings.image_watermark_enabled') && file_exists($logo)) {
            return [
                'type'     => 'image',
                'path'     => $logo,
                'size'     => max(5, min(100, (int)(config('settings.image_watermark_size') ?: 20))),
                'opacity'  => max(1, min(100, (int)(config('settings.image_watermark_opacity') ?: 80))),
                'position' => (string)(config('settings.image_watermark_position') ?: 'br'),
                'margin'   => (int)(config('settings.image_watermark_margin') ?: 15),
            ];
        }

        $font = FREEIMG_ROOT . '/assets/fonts/NotoSansSC-Regular.otf';
        if ((int)config('settings.watermark_enabled') && file_exists($font)) {
            return [
                'type'     => 'text',
                'text'     => trim((string)config('settings.watermark_text')) ?: (string)config('settings.site_name', 'FreeImg'),
                'font'     => $font,
                'size'     => (int)(config('settings.watermark_font_size') ?: 28),
                'color'    => (string)(config('settings.watermark_color') ?: '#ffffff'),
                'opacity'  => max(1, min(100, (int)(config('settings.watermark_opacity') ?: 50))),
                'angle'    => (int)(config('settings.watermark_angle') ?: 0),
                'position' => (string)(config('settings.watermark_position') ?: 'br'),
                'margin'   => (int)(config('settings.watermark_margin') ?: 20),
            ];
        }

        return null;
    }
}