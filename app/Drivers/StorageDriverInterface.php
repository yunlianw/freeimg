<?php
namespace App\Drivers;

/**
 * 存储驱动接口
 *
 * 所有存储驱动（Local / S3 / R2 / COS / OSS / OBS / SFTP）
 * 必须实现这个接口，业务层只依赖此接口。
 */
interface StorageDriverInterface
{
    /**
     * 存储文件
     * @param string $path  目标路径（不含 base 目录，如 "2026/08/abc.jpg"）
     * @param string $content 文件内容（二进制）
     * @return bool
     */
    public function put(string $path, string $content): bool;

    /**
     * 读取文件内容
     */
    public function get(string $path): ?string;

    /**
     * 删除文件
     */
    public function delete(string $path): bool;

    /**
     * 移动/重命名
     */
    public function move(string $from, string $to): bool;

    /**
     * 复制
     */
    public function copy(string $from, string $to): bool;

    /**
     * 文件是否存在
     */
    public function exists(string $path): bool;

    /**
     * 获取文件信息
     * @return array|null ['size'=>int, 'mtime'=>int, 'mime'=>string]
     */
    public function stat(string $path): ?array;

    /**
     * 公共访问 URL（用于前端显示/直链）
     */
    public function url(string $path): string;

    /**
     * 测试连接
     */
    public function testConnection(): bool;

    /**
     * 用量统计（字节）
     */
    public function usage(): int;

    /**
     * 驱动标识
     */
    public function driverName(): string;
}