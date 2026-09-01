<?php
/**
 * FreeImg 安装向导 - 业务逻辑类
 */

class Installer
{
    private PDO $pdo;

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function __construct(array $dbConfig)
    {
        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $dbConfig['host'], $dbConfig['port']);
        $this->pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    /**
     * 创建数据库（如不存在）
     */
    public function ensureDatabase(string $dbname): void
    {
        // 库名只允许 [a-zA-Z0-9_]，防止反引号注入
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $dbname)) {
            throw new RuntimeException('数据库名仅允许字母、数字和下划线');
        }
        $this->pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbname}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        $this->pdo->exec("USE `{$dbname}`");
    }

    /**
     * 执行 SQL 文件
     */
    public function runSqlFile(string $sqlFile): int
    {
        $sql = file_get_contents($sqlFile);
        if ($sql === false) {
            throw new RuntimeException("无法读取 SQL 文件: $sqlFile");
        }

        // 按行解析（兼容字符串内的换行）
        $statements = [];
        $buffer = '';
        foreach (explode("\n", $sql) as $line) {
            $trim = trim($line);
            if ($trim === '' || str_starts_with($trim, '--')) {
                continue;
            }
            $buffer .= $line . "\n";
            if (str_ends_with($trim, ';')) {
                $statements[] = $buffer;
                $buffer = '';
            }
        }

        $count = 0;
        foreach ($statements as $stmt) {
            $this->pdo->exec($stmt);
            $count++;
        }
        return $count;
    }

    /**
     * 创建管理员
     */
    public function createAdmin(string $username, string $email, string $password): int
    {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $this->pdo->prepare("INSERT INTO users (username, email, password, role, status, created_at) VALUES (?, ?, ?, 'admin', 1, NOW())");
        $stmt->execute([$username, $email, $hash]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * 创建默认 Local 存储
     */
    public function createDefaultStorage(int $adminId, string $publicHost): void
    {
        $localConfig = json_encode([
            'path' => 'storage/images',
            'url'  => 'https://' . $publicHost . '/uploads',
            'mode' => 'public',
        ]);
        $stmt = $this->pdo->prepare("INSERT INTO storages (user_id, name, driver, config, is_default, status, created_at) VALUES (?, '本地存储', 'local', ?, 1, 1, NOW())");
        $stmt->execute([$adminId, $localConfig]);
    }

    /**
     * 写入默认设置
     */
    public function seedSettings(?string $publicHost = null): void
    {
        $defaults = [
            ['site_name', 'FreeImg 自由图床', 'general'],
            // site_url：自动用访问域名（HTTP_HOST），管理员可在后台修改
            $publicHost ? ['site_url', 'https://' . $publicHost, 'general'] : ['site_url', '', 'general'],
            ['upload_max_size', '10', 'upload'],
            ['upload_allowed_types', 'jpg,jpeg,png,gif,webp,bmp', 'upload'],
            ['default_compression', 'balanced', 'image'],
            // Phase 9.3: 浏览器上传压缩模式（double=双重 / browser=仅浏览器 / backend=仅后端）
            ['browser_upload_mode', 'browser', 'image'],
            // Phase 9.5: 隐私安全 - 默认开启 EXIF/IPTC/XMP 剥离（手机拍图含 GPS）
            ['strip_exif', '1', 'image'],
        ];
        $stmt = $this->pdo->prepare("INSERT IGNORE INTO settings (`key`, `value`, `group`, created_at) VALUES (?, ?, ?, NOW())");
        foreach ($defaults as $s) {
            $stmt->execute($s);
        }
    }

    /**
     * 初始化 6 个内置压缩预设
     */
    public function seedCompressionProfiles(): void
    {
        $profiles = [
            // [name, code, description, max_dim, jpeg_q, webp_q, png_zip, target_kb, min_q, strip, builtin, sort, png_qmin, png_qmax, output_format]
            ['原图', 'original', '不缩放不压缩', 0, 100, 100, 6, 0, 40, 1, 1, 1, 95, 100, 'auto'],
            ['高清', 'high', '2048px / JPEG 85 / 适合原画质', 2048, 85, 85, 6, 0, 60, 1, 1, 2, 80, 95, 'auto'],
            ['均衡', 'balanced', '1600px / JPEG 70 / 约 50-100KB', 1600, 70, 70, 6, 100, 40, 1, 1, 3, 65, 85, 'auto'],
            ['省流', 'saver', '1100px / JPEG 45 / 约 50-80KB', 1100, 45, 45, 6, 80, 30, 1, 1, 4, 55, 75, 'auto'],
            ['极限压缩', 'mega', '400px / WebP 15 / 目标 ≤ 20KB', 400, 15, 15, 6, 20, 10, 1, 1, 5, 30, 50, 'webp'],
            ['自定义', 'custom', '默认参数，可在后台修改', 1600, 70, 70, 6, 0, 40, 1, 0, 99, 50, 70, 'auto'],
        ];
        $stmt = $this->pdo->prepare(
            "INSERT IGNORE INTO compression_profiles (name, code, description, max_dimension, jpeg_quality, webp_quality, png_compression, target_size_kb, minimum_quality, strip_metadata, is_builtin, sort_order, png_quality_min, png_quality_max, output_format, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        foreach ($profiles as $p) {
            $stmt->execute($p);
        }

        // Phase 9.3: profiles 插入后（id 1-6 稳定）再设 Web/API 默认档
        // 均衡(id=3) 为 Web 默认，极限省流(id=5) 为 API 默认
        $stmt2 = $this->pdo->prepare("INSERT IGNORE INTO settings (`key`, `value`, `group`, created_at) VALUES (?, ?, ?, NOW())");
        $stmt2->execute(['web_compression_profile_id', '3', 'image']);
        $stmt2->execute(['api_compression_profile_id', '5', 'image']);
    }

    /**
     * 创建必要目录
     */
    public function ensureDirectories(string $root): void
    {
        $dirs = [
            $root . '/storage/images',
            $root . '/storage/cache',
            $root . '/storage/logs',
            $root . '/public/storage/images',
            $root . '/public/storage/watermark',
        ];
        foreach ($dirs as $d) {
            if (!is_dir($d)) {
                @mkdir($d, 0755, true);
            }
            if (!file_exists($d . '/.gitkeep')) {
                @file_put_contents($d . '/.gitkeep', '');
            }
        }

        // uploads 软链接（兼容 /uploads/ 旧访问路径）
        // 注：宝塔/部分 PHP 配置禁用了 symlink() 函数，检测后跳过
        $uploadsLink = $root . '/public/uploads';
        if (!file_exists($uploadsLink) && !is_link($uploadsLink)) {
            if (function_exists('symlink')) {
                @symlink($root . '/storage/images', $uploadsLink);
            } else {
                // symlink 不可用：跳过（不影响安装，后续可在 nginx 加 alias 兼容 /uploads/）
            }
        }
    }

    /**
     * 写入 config.php
     */
    public function writeConfig(string $configFile, array $dbCfg, string $siteUrl): string
    {
        $example = $rootPath = dirname(__DIR__) . '/config/config.example.php';
        if (!file_exists($example)) {
            throw new RuntimeException('config.example.php 不存在');
        }

        $encKey = bin2hex(random_bytes(32));
        $content = file_get_contents($example);

        $replacements = [
            '__DB_HOST__'  => $dbCfg['host'],
            '__DB_PORT__'  => $dbCfg['port'],
            '__DB_NAME__'  => $dbCfg['dbname'],
            '__DB_USER__'  => $dbCfg['username'],
            '__DB_PASS__'  => addslashes($dbCfg['password']),
            '__ENC_KEY__'  => $encKey,
            '__SITE_URL__' => $siteUrl,
        ];
        $content = strtr($content, $replacements);
        file_put_contents($configFile, $content);

        return $encKey;
    }

    /**
     * 创建 install.lock
     */
    public function createLock(string $lockFile, int $adminId): void
    {
        file_put_contents($lockFile, date('Y-m-d H:i:s') . " | admin_id=$adminId\n");

        // v1.1.4+: 触发升级脚本（清理孤儿 settings 行等）
        $upgradeFile = __DIR__ . '/upgrade.php';
        if (file_exists($upgradeFile)) {
            require_once $upgradeFile;
        }
    }

    /**
     * 环境检测
     */
    public static function checkEnvironment(string $root, string $lock): array
    {
        return [
            ['PHP 版本 >= 8.0', version_compare(PHP_VERSION, '8.0.0', '>='), PHP_VERSION],
            ['PDO 扩展', extension_loaded('pdo')],
            ['PDO MySQL 驱动', extension_loaded('pdo_mysql')],
            ['GD 扩展（图片处理）', extension_loaded('gd')],
            ['JSON 扩展', extension_loaded('json')],
            ['mbstring 扩展', extension_loaded('mbstring')],
            ['OpenSSL 扩展（加密）', extension_loaded('openssl')],
            ['fileinfo 扩展（MIME 检测）', extension_loaded('fileinfo')],
            ['config 目录可写', is_writable($root . '/config')],
            ['storage 目录可写', is_writable($root . '/storage') || @mkdir($root . '/storage', 0755, true)],
            ['install 目录可写', is_writable(__DIR__)],
            ['未安装（lock 不存在）', !file_exists($lock)],
        ];
    }
}