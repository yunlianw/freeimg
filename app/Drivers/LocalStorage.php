<?php
namespace App\Drivers;

/**
 * 本地存储驱动
 *
 * config 字段：
 *   - path:  物理目录（绝对路径）
 *   - url:   公共访问 URL 前缀（如 https://yourdomain.com/uploads）
 *   - mode:  public / private
 */
class LocalStorage implements StorageDriverInterface
{
    private string $basePath;
    private string $baseUrl;
    private string $prefix;

    public function __construct(array $config)
    {
        $path = $config['path'] ?? '';
        // 把相对路径转成绝对路径（PHP-FPM CWD 不一定是项目根）
        if ($path !== '' && $path[0] !== '/') {
            if (defined('FREEIMG_ROOT')) {
                $path = FREEIMG_ROOT . '/' . $path;
            }
        }
        $this->basePath = rtrim($path, '/');
        $this->baseUrl  = rtrim($config['url'] ?? '', '/');
        // 子目录前缀（由调用方通过 setPrefix 设置，默认空）
        $this->prefix = '';

        if (empty($this->basePath)) {
            throw new \InvalidArgumentException('LocalStorage 需要 path 配置');
        }
    }

    /**
     * 设置 URL 一级目录（如 'img' / 'rest' / 'cdn'）
     * 所有路径自动拼上 prefix
     */
    public function setPrefix(string $prefix): void
    {
        // 支持多级目录（如 img/tu）
        $prefix = preg_replace('/[^a-zA-Z0-9\/_-]/', '', $prefix);
        $this->prefix = $prefix ?? '';
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function driverName(): string
    {
        return 'local';
    }

    public function put(string $path, string $content): bool
    {
        $full = $this->resolve($path);
        $dir = dirname($full);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return false;
        }
        return file_put_contents($full, $content) !== false;
    }

    public function get(string $path): ?string
    {
        $full = $this->resolve($path);
        if (!file_exists($full)) return null;
        return file_get_contents($full) ?: null;
    }

    public function delete(string $path): bool
    {
        $full = $this->resolve($path);
        if (!file_exists($full)) return true; // 已不存在视为成功
        return @unlink($full);
    }

    public function move(string $from, string $to): bool
    {
        $src = $this->resolve($from);
        $dst = $this->resolve($to);
        $dir = dirname($dst);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return false;
        }
        return @rename($src, $dst);
    }

    public function copy(string $from, string $to): bool
    {
        $src = $this->resolve($from);
        $dst = $this->resolve($to);
        $dir = dirname($dst);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return false;
        }
        return @copy($src, $dst);
    }

    public function exists(string $path): bool
    {
        return file_exists($this->resolve($path));
    }

    public function stat(string $path): ?array
    {
        $full = $this->resolve($path);
        if (!file_exists($full)) return null;
        $size = @filesize($full);
        $mtime = @filemtime($full);
        $mime = $this->guessMime($full);
        return [
            'size'  => $size === false ? 0 : $size,
            'mtime' => $mtime === false ? 0 : $mtime,
            'mime'  => $mime,
        ];
    }

    public function url(string $path): string
    {
        // path 是 storage_path（已含 prefix，如 'img/kRLoN9k.jpg'）
        // 不要重复加 prefix，直接拼 baseUrl
        return $this->baseUrl . '/' . ltrim($path, '/');
    }

    public function testConnection(): bool
    {
        return is_dir($this->basePath) && is_writable($this->basePath);
    }

    public function usage(): int
    {
        if (!is_dir($this->basePath)) return 0;
        $total = 0;
        try {
            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->basePath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            ) as $f) {
                if ($f->isFile()) $total += $f->getSize();
            }
        } catch (\Exception $e) {
            return 0;
        }
        return $total;
    }

    private function resolve(string $path): string
    {
        // 防路径穿越
        $path = ltrim($path, '/');
        if (strpos($path, '..') !== false) {
            $path = str_replace('..', '', $path);
        }
        // path 已经包含 prefix（如 'img/xxx.jpg'），不要重复拼
        return $this->basePath . '/' . $path;
    }

    private function guessMime(string $file): string
    {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? @finfo_file($finfo, $file) : false;
        if ($finfo) @finfo_close($finfo);
        return $mime ?: 'application/octet-stream';
    }
}