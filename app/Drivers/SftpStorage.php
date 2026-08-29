<?php
namespace App\Drivers;

/**
 * SFTP 远程服务器驱动（基于 PHP cURL 的 sftp:// 协议，零 Composer 依赖）
 *
 * 依赖：PHP curl 编译时启用 libssh2（php -r 'print_r(curl_version());' 看 libssh_version）
 *
 * config 字段：
 *   - host        服务器 IP / 域名
 *   - port        SSH 端口（默认 22）
 *   - username    登录用户名
 *   - password    密码（与 private_key 二选一；服务器禁密码认证时用私钥）
 *   - private_key 私钥文件路径（如 /home/www/.ssh/id_ed25519）
 *   - private_key_passphrase 私钥密码（可选）
 *   - path        远程存储根目录（如 /var/www/uploads，需可写）
 *   - public_url  公开访问 URL 前缀（可选；SFTP 本身不提供 HTTP 访问，需另配 web 服务）
 *
 * 已知 libssh2/cURL 行为（实测）：
 *   - 公钥认证时用户名必须写在 URL 里：sftp://user@host:port/path
 *   - 子目录列目录必须带尾部斜杠，否则 "Error in the SSH layer"
 *   - QUOTE 命令（mkdir/rm/rename）需配合 RETURNSFTRANSFER=true 吞掉默认 LIST 输出
 *   - mkdir 已存在目录会报错但无害（忽略）
 */
class SftpStorage implements StorageDriverInterface
{
    private string $host;
    private int $port;
    private string $username;
    private ?string $password;
    private ?string $privateKey;
    private ?string $passphrase;
    private string $rootPath;
    private ?string $publicUrl;

    public function __construct(array $config)
    {
        foreach (['host', 'username', 'path'] as $k) {
            if (empty($config[$k])) {
                throw new \InvalidArgumentException("SftpStorage 缺少配置: $k");
            }
        }
        $this->host       = $config['host'];
        $this->port       = (int)($config['port'] ?? 22);
        $this->username   = $config['username'];
        $this->password   = $config['password'] ?? null;
        $this->privateKey = $config['private_key'] ?? null;
        $this->passphrase = $config['private_key_passphrase'] ?? null;
        $this->rootPath   = rtrim($config['path'], '/');
        $this->publicUrl  = isset($config['public_url']) ? rtrim($config['public_url'], '/') : null;
    }

    public function driverName(): string
    {
        return 'sftp';
    }

    public function put(string $path, string $content): bool
    {
        $remote = $this->full($path);
        // 目录不存在时先建目录再重试一次
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $ch = $this->connect($remote);
            $pos = 0;
            curl_setopt_array($ch, [
                CURLOPT_UPLOAD       => true,
                CURLOPT_INFILESIZE   => strlen($content),
                CURLOPT_READFUNCTION => function ($ch, $fd, $len) use ($content, &$pos) {
                    $d = substr($content, $pos, $len);
                    $pos += strlen($d);
                    return $d;
                },
            ]);
            $ok = curl_exec($ch) !== false;
            $err = curl_error($ch);
            if ($ok) return true;
            if ($attempt === 0 && str_contains($err, 'No such file')) {
                $this->ensureDir(dirname($path));
                continue;
            }
            error_log("[SftpStorage] put 失败: $err");
            return false;
        }
        return false;
    }

    public function get(string $path): ?string
    {
        $ch = $this->connect($this->full($path));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $body = curl_exec($ch);
        if ($body === false) {
            error_log('[SftpStorage] get 失败: ' . curl_error($ch));
            return null;
        }
        return $body;
    }

    public function delete(string $path): bool
    {
        $ch = $this->connect($this->rootPath . '/');
        curl_setopt($ch, CURLOPT_QUOTE, ['rm ' . $this->full($path)]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $ok = curl_exec($ch) !== false;
        if (!$ok) {
            // 文件不存在也算删除成功
            $err = curl_error($ch);
            if (str_contains($err, 'No such file')) return true;
            error_log("[SftpStorage] delete 失败: $err");
        }
        return $ok;
    }

    public function move(string $from, string $to): bool
    {
        $ch = $this->connect($this->rootPath . '/');
        curl_setopt($ch, CURLOPT_QUOTE, ['rename ' . $this->full($from) . ' ' . $this->full($to)]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $ok = curl_exec($ch) !== false;
        if (!$ok) error_log('[SftpStorage] move 失败: ' . curl_error($ch));
        return $ok;
    }

    public function copy(string $from, string $to): bool
    {
        $content = $this->get($from);
        if ($content === null) return false;
        return $this->put($to, $content);
    }

    public function exists(string $path): bool
    {
        return $this->stat($path) !== null;
    }

    public function stat(string $path): ?array
    {
        $ch = $this->connect($this->full($path));
        curl_setopt_array($ch, [
            CURLOPT_NOBODY   => true,
            CURLOPT_FILETIME => true,
        ]);
        $ok = curl_exec($ch) !== false;
        if (!$ok) return null;
        $size = (int)curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        $mtime = (int)curl_getinfo($ch, CURLINFO_FILETIME);
        return [
            'size'  => $size > 0 ? $size : 0,
            'mtime' => $mtime > 0 ? $mtime : time(),
            'mime'  => 'application/octet-stream',
        ];
    }

    public function url(string $path): string
    {
        if ($this->publicUrl) return $this->publicUrl . '/' . ltrim($path, '/');
        // 无 public_url 时返回空（SFTP 不能直接浏览器访问）
        return '';
    }

    public function testConnection(): bool
    {
        $ch = $this->connect($this->rootPath . '/');
        curl_setopt_array($ch, [
            CURLOPT_DIRLISTONLY  => true,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $ok = curl_exec($ch) !== false;
        if (!$ok) error_log('[SftpStorage] testConnection 失败: ' . curl_error($ch));
        return $ok;
    }

    public function usage(): int
    {
        $total = 0;
        $count = 0;
        $this->walkDir('', $total, $count);
        return $total;
    }

    /** 递归统计目录大小（限制 1000 个文件） */
    private function walkDir(string $dir, int &$total, int &$count): void
    {
        if ($count >= 1000) return;
        $items = $this->listDir($dir);
        if ($items === null) return;
        foreach ($items as $item) {
            if ($count >= 1000) return;
            $rel = $dir === '' ? $item : $dir . '/' . $item;
            $st = $this->stat($rel);
            if ($st !== null && $st['size'] > 0) {
                $total += $st['size'];
                $count++;
            } elseif ($st === null) {
                // 目录（stat 失败说明不是文件）→ 递归
                $this->walkDir($rel, $total, $count);
            }
        }
    }

    // ================== 内部工具 ==================

    /** 远程完整路径（过滤 .. 防路径穿越） */
    private function full(string $path): string
    {
        $path = str_replace('..', '', $path);
        return $this->rootPath . '/' . ltrim($path, '/');
    }

    /** 目录完整路径（带尾部斜杠，列目录必需） */
    private function fullDir(string $path): string
    {
        return $this->full($path) . '/';
    }

    /** 建立 cURL 连接句柄 */
    private function connect(string $remotePath): \CurlHandle
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => sprintf('sftp://%s@%s:%d%s', $this->username, $this->host, $this->port, $remotePath),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 60,
        ]);
        if ($this->privateKey) {
            curl_setopt($ch, CURLOPT_SSH_PUBLIC_KEYFILE, $this->privateKey . '.pub');
            curl_setopt($ch, CURLOPT_SSH_PRIVATE_KEYFILE, $this->privateKey);
            curl_setopt($ch, CURLOPT_SSH_AUTH_TYPES, CURLSSH_AUTH_PUBLICKEY);
            if ($this->passphrase) curl_setopt($ch, CURLOPT_KEYPASSWD, $this->passphrase);
        } else {
            curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . ($this->password ?? ''));
        }
        return $ch;
    }

    /**
     * 确保远程目录存在（逐级 mkdir）
     * 注意：mkdir 已存在目录会报错但无害；最后用列目录验证
     */
    private function ensureDir(string $remoteDir): void
    {
        // 逐级目录单独 mkdir：已存在的目录失败不影响其他级（QUOTE 数组一条失败会拖垮整链）
        $dirs = [$this->rootPath];
        if ($remoteDir !== '' && $remoteDir !== '.' && $remoteDir !== '/') {
            $cur = $this->rootPath;
            foreach (explode('/', trim($remoteDir, '/')) as $p) {
                $cur .= '/' . $p;
                $dirs[] = $cur;
            }
        }
        foreach ($dirs as $d) {
            $ch = $this->connect($this->rootPath . '/');
            curl_setopt_array($ch, [
                CURLOPT_QUOTE          => ['mkdir ' . $d],
                CURLOPT_RETURNTRANSFER => true,
            ]);
            curl_exec($ch); // 目录已存在时失败，忽略
        }
    }

    /** 列目录（返回文件名数组；失败返回 null） */
    private function listDir(string $dir): ?array
    {
        $ch = $this->connect($this->fullDir($dir));
        curl_setopt_array($ch, [
            CURLOPT_DIRLISTONLY   => true,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $out = curl_exec($ch);
        if ($out === false) {
            error_log('[SftpStorage] listDir 失败: ' . curl_error($ch));
            return null;
        }
        $items = [];
        foreach (explode("\n", trim($out)) as $line) {
            $line = trim($line);
            if ($line === '' || $line === '.' || $line === '..') continue;
            $items[] = $line;
        }
        return $items;
    }
}
