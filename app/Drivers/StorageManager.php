<?php
namespace App\Drivers;

use App\Core\Db;

/**
 * 存储驱动工厂 + 配置管理 + 智能选存储
 */
class StorageManager
{
    /**
     * 按 ID 获取驱动实例
     */
    public static function driver(int $storageId): StorageDriverInterface
    {
        $row = Db::fetchOne('SELECT * FROM storages WHERE id = :id AND status = 1', ['id' => $storageId]);
        if (!$row) {
            throw new \RuntimeException("存储驱动不存在或已禁用 (id={$storageId})");
        }
        return self::build($row);
    }

    /**
     * 取用户默认驱动
     */
    public static function defaultForUser(int $userId): StorageDriverInterface
    {
        $row = Db::fetchOne(
            'SELECT * FROM storages WHERE user_id = :uid AND is_default = 1 AND status = 1 LIMIT 1',
            ['uid' => $userId]
        );
        if (!$row) {
            $row = Db::fetchOne(
                'SELECT * FROM storages WHERE driver = "local" AND status = 1 ORDER BY id ASC LIMIT 1'
            );
            if (!$row) {
                throw new \RuntimeException("系统未配置任何存储");
            }
        }
        return self::buildWithPrefix($row);
    }

    /**
     * 智能选存储（Phase 8 增强）
     *
     * 规则：
     * 1. 如果用户指定了 storageId 且该存储可见且未满 → 用指定的
     * 2. 否则从可见存储中按 priority DESC 选第一个未满的
     * 3. 都满或无可见存储 → 抛错
     *
     * @param int      $userId       上传用户
     * @param int|null $userChoiceId 用户在前端选的存储 ID
     * @return array{0: StorageDriverInterface, 1: array}
     */
    public static function pickForUpload(int $userId, ?int $userChoiceId = null): array
    {
        $allEnabled = Db::fetchAll(
            'SELECT * FROM storages WHERE status = 1 ORDER BY priority DESC, id ASC'
        );

        if (empty($allEnabled)) {
            throw new \RuntimeException('系统未配置任何存储');
        }

        // 1. 用户指定优先
        if ($userChoiceId) {
            foreach ($allEnabled as $s) {
                if ((int)$s['id'] === (int)$userChoiceId) {
                    if (!self::isFull($s)) {
                        return [self::buildWithPrefix($s), $s];
                    }
                    // 用户选的已满：继续找 fallback
                    break;
                }
            }
        }

        // 2a. 优先 visible + 未满（让用户看得到的存储优先被用）
        foreach ($allEnabled as $s) {
            if ((int)$s['visible_in_upload'] !== 1) continue;
            if (self::isFull($s)) continue;
            return [self::buildWithPrefix($s), $s];
        }

        // 2b. fallback：可见存储全满时，尝试隐藏存储（后台 backup/归档用）
        foreach ($allEnabled as $s) {
            if ((int)$s['visible_in_upload'] === 1) continue;
            if (self::isFull($s)) continue;
            return [self::buildWithPrefix($s), $s];
        }

        throw new \RuntimeException('所有存储已满，请清理或扩容');
    }

    /**
     * 判断存储是否已满
     * - max_capacity_mb 为 NULL → 无限（不算满）
     * - current_usage_mb >= max_capacity_mb × 0.8 → 算满（80% 阈值，留 buffer）
     */
    public static function isFull(array $storage): bool
    {
        if (empty($storage['max_capacity_mb'])) return false;
        // current_usage_mb 实际存 KB，max_capacity_mb 是 MB，需 × 1024
        return (int)$storage['current_usage_mb'] >= (int)$storage['max_capacity_mb'] * 1024 * 0.8;
    }

    /**
     * 上传成功后累加容量
     */
    public static function addUsage(int $storageId, int $sizeBytes): void
    {
        if ($storageId <= 0 || $sizeBytes <= 0) return;
        $mb = (int)ceil($sizeBytes / 1024);
        if ($mb <= 0) $mb = 1;  // < 1MB 也算 1MB
        Db::execute(
            'UPDATE storages SET current_usage_mb = current_usage_mb + ? WHERE id = ?',
            [$mb, $storageId]
        );
    }

    /**
     * 删除图片后减少容量
     */
    public static function subUsage(int $storageId, int $sizeBytes): void
    {
        if ($storageId <= 0 || $sizeBytes <= 0) return;
        $mb = (int)ceil($sizeBytes / 1024);
        if ($mb <= 0) $mb = 1;
        Db::execute(
            'UPDATE storages SET current_usage_mb = GREATEST(0, current_usage_mb - ?) WHERE id = ?',
            [$mb, $storageId]
        );
    }

    /**
     * 重新计算某个存储的真实占用（从磁盘实际文件大小统计）
     * 用于后台"重新计算容量"按钮
     */
    public static function recalcUsage(int $storageId): int  // 返回 KB 数
    {
        $row = Db::fetchOne('SELECT * FROM storages WHERE id = :id', ['id' => $storageId]);
        if (!$row) return 0;

        $totalBytes = 0;
        // 用 storage_id 找 images，累加 final_size
        $rows = Db::fetchAll(
            'SELECT final_size FROM images WHERE storage_id = :sid AND status = "active"',
            ['sid' => $storageId]
        );
        foreach ($rows as $r) {
            $totalBytes += (int)$r['final_size'];
        }
        $mb = (int)($totalBytes / 1024);
        Db::execute(
            'UPDATE storages SET current_usage_mb = ? WHERE id = ?',
            [$mb, $storageId]
        );
        return $mb;
    }

    /**
     * 取所有可见存储（给上传页下拉用）
     * @return array
     */
    public static function listVisible(): array
    {
        return Db::fetchAll(
            'SELECT * FROM storages
             WHERE status = 1 AND visible_in_upload = 1
             ORDER BY priority DESC, id ASC'
        );
    }

    /**
     * 取所有存储（后台管理用）
     */
    public static function listAll(): array
    {
        return Db::fetchAll('SELECT * FROM storages ORDER BY priority DESC, id ASC');
    }

    private static function buildWithPrefix(array $row): StorageDriverInterface
    {
        $driver = self::build($row);
        if ($driver instanceof \App\Drivers\LocalStorage) {
            $prefix = trim((string)(\config('settings.url_path_prefix') ?: 'img'), '/');
            $prefix = preg_replace('/[^a-zA-Z0-9_-]/', '', $prefix);
            if ($prefix !== '') $driver->setPrefix($prefix);
        }
        return $driver;
    }

    private static function build(array $row): StorageDriverInterface
    {
        $cfg = json_decode(decrypt_secret($row['config']), true) ?: [];
        $cfg['__name__'] = $row['name'];
        return self::buildFromConfig($row['driver'], $cfg);
    }

    public static function buildFromConfig(string $driver, array $cfg): StorageDriverInterface
    {
        switch ($driver) {
            case 'local':
                return new LocalStorage($cfg);
            case 's3':
            case 'r2':
            case 'minio':
            case 'obs':
                return new S3Storage($cfg);
            case 'cos':
                return new CosStorage($cfg);
            case 'oss':
                return new OssStorage($cfg);
            case 'sftp':
                return new SftpStorage($cfg);
            default:
                throw new \RuntimeException("不支持的驱动: {$driver}");
        }
    }

    public static function testConfig(string $driver, array $cfg): array
    {
        try {
            $instance = self::buildFromConfig($driver, $cfg);
            $ok = $instance->testConnection();
            return $ok
                ? ['ok' => true, 'message' => '✅ 连接成功']
                : ['ok' => false, 'message' => '❌ 连接失败：请检查配置（地址/端口/账号/密钥）'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => '❌ ' . $e->getMessage()];
        }
    }
}
