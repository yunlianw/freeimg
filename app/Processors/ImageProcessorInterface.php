<?php
namespace App\Processors;

/**
 * 图片处理器接口
 */
interface ImageProcessorInterface
{
    /**
     * 压缩 + 转换
     * @param string $source   原文件绝对路径
     * @param string $dest     目标文件绝对路径
     * @param array $opts
     *   - max_width   最大宽度（默认不缩放）
     *   - max_height  最大高度
     *   - quality     JPEG/WebP 质量 1-100（默认 85）
     *   - format      强制输出格式（jpeg/png/webp/gif），空=自动按扩展名
     * @return array [
     *   'success'       => bool,
     *   'width'         => int,
     *   'height'        => int,
     *   'size'          => int,   // 输出文件大小
     *   'mime'          => string,
     *   'extension'     => string,
     *   'compression_ratio' => float, // 1.00 表示未压缩
     * ]
     */
    public function compress(string $source, string $dest, array $opts = []): array;

    /**
     * 获取图片信息
     */
    public function info(string $file): ?array;

    /**
     * 驱动名
     */
    public function driverName(): string;
}