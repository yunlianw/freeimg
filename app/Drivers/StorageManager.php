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
        // Phase 9.3: current_usage_mb 已是真实 MB（浮点），直接与 max_capacity_mb 比较
        return (float)$storage['current_usage_mb'] >= (float)$storage['max_capacity_mb'] * 0.8;
    }

    /**
     * 上传成功后累加容量
     */
    public static function addUsage(int $storageId, int $sizeBytes): void
    {
        if ($storageId <= 0 || $sizeBytes <= 0) return;
        // Phase 9.3: 真实字节 → MB 小数累积（不 ceil，避免小图虚增）
        $mb = round($sizeBytes / 1048576, 4);
        if ($mb <= 0) $mb = 0.0001;
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
        $mb = round($sizeBytes / 1048576, 4);
        if ($mb <= 0) $mb = 0.0001;
        Db::execute(
            'UPDATE storages SET current_usage_mb = GREATEST(0, current_usage_mb - ?) WHERE id = ?',
            [$mb, $storageId]
        );
    }

    /**
     * 重新计算某个存储的真实占用（从磁盘实际文件大小统计）
     * 用于后台"重新计算容量"按钮
     */
    public static function recalcUsage(int $storageId): int  // 返回 MB 数
    {
        $row = Db::fetchOne('SELECT * FROM storages WHERE id = :id', ['id' => $storageId]);
        if (!$row) return 0;

        $totalBytes = 0;
        // 优先用 images 表实际 final_size 统计（真实）
        $rows = Db::fetchAll(
            'SELECT final_size FROM images WHERE storage_id = :sid AND status = "active"',
            ['sid' => $storageId]
        );
        foreach ($rows as $r) {
            $totalBytes += (int)$r['final_size'];
        }

        // 真实字节 → MB（1MB = 1048576 字节），小数精度 4 位
        $mb = round($totalBytes / 1048576, 4);
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
        $rows = Db::fetchAll(
            'SELECT * FROM storages
             WHERE status = 1 AND visible_in_upload = 1
             ORDER BY priority DESC, id ASC'
        );
        // Phase 9.3: 附上 is_full 标记（容量保护用，真实 MB 比较）
        foreach ($rows as &$r) {
            $r['is_full'] = self::isFull($r) ? 1 : 0;
        }
        unset($r);
        return $rows;
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
                return new S3Storage($cfg);
            case 'obs':
                return new ObsStorage($cfg);
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
            $result = self::probeConfig($driver, $instance);
            return $result;
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => '❌ ' . $e->getMessage()];
        }
    }

    /**
     * 真实探测驱动：返回精确诊断
     * 优先用测试文件上传再删除，避免和 ListBucket 等无关 ACL 冲突
     */
    private static function probeConfig(string $driver, $instance): array
    {
        // 用一个 probe 测试：上传→读取→删除
        $probeKey = 'freeimg-probe-' . substr(md5(uniqid('', true)), 0, 8) . '.txt';
        $probeContent = 'freeimg-probe-' . time();

        try {
            // 1) 探测桶存在（testConnection 走 HEAD 根，多数驱动都是 GET 探测，绕开 ListBucket 限制）
            $connectOk = $instance->testConnection();
            if (!$connectOk) {
                return [
                    'ok' => false,
                    'message' => self::diagnose($driver, 'connect_fail', ''),
                ];
            }

            // 2) 真实上传（验证 PutObject 权限）
            $putOk = $instance->put($probeKey, $probeContent);
            if (!$putOk) {
                return [
                    'ok' => false,
                    'message' => self::diagnose($driver, 'put_fail', ''),
                ];
            }

            // 3) 读取验证
            $got = $instance->get($probeKey);
            if ($got === null || $got !== $probeContent) {
                $instance->delete($probeKey);
                return [
                    'ok' => false,
                    'message' => self::diagnose($driver, 'get_fail', ''),
                ];
            }

            // 4) 清理
            $instance->delete($probeKey);

            return ['ok' => true, 'message' => '✅ 连接成功（已验证上传/读取/删除）'];
        } catch (\Throwable $e) {
            // 兜底：捕获驱动内部异常
            try { $instance->delete($probeKey); } catch (\Throwable $ignored) {}
            return ['ok' => false, 'message' => '❌ ' . self::diagnose($driver, 'exception', $e->getMessage())];
        }
    }

    /**
     * 根据驱动 + 失败阶段给出精确诊断
     */
    private static function diagnose(string $driver, string $stage, string $detail): string
    {
        $driverName = [
            'local' => '本地存储',
            's3' => 'S3 兼容存储',
            'obs' => '华为云 OBS',
            'cos' => '腾讯云 COS',
            'oss' => '阿里云 OSS',
            'sftp' => 'SFTP',
        ][$driver] ?? $driver;

        $hints = [
            'local' => [
                'connect_fail' => '检查存储根目录路径是否存在且可写',
                'put_fail'      => '存储目录不可写：检查 owner/权限',
                'get_fail'      => '存储目录不可读',
            ],
            's3' => [
                'connect_fail' => '检查 Endpoint/Region/Bucket 是否正确，Access Key/Secret Key 是否有效',
                'put_fail'      => 'AWS/IAM 用户没有 PutObject 权限，或桶策略禁止写',
                'get_fail'      => 'AWS/IAM 用户没有 GetObject 权限，或对象不存在',
            ],
            'obs' => [
                'connect_fail' => '❌ 华为云 OBS：检查 Endpoint/Region/Bucket 是否一致、Access Key (AK) 是否正确',
                'put_fail'      => '❌ 华为云 OBS：AK/SK 没有 PutObject 权限 → 我的凭证 → IAM 用户 → 授权 OBS OperateAccess',
                'get_fail'      => '❌ 华为云 OBS：桶 ACL 没有公共读 → 桶策略加 GetObject 允许',
                'exception'     => '❌ 华为云 OBS 异常：' . $detail,
            ],
            'cos' => [
                'connect_fail' => '❌ 腾讯云 COS：检查 Endpoint/Region/Bucket/SecretId 是否正确',
                'put_fail'      => '❌ 腾讯云 COS：SecretId/SecretKey 没有 PutObject 权限 → CAM 策略授予 cos:PutObject，或检查桶 ACL 是「公有读私有写」',
                'get_fail'      => '❌ 腾讯云 COS：桶 ACL 没有公共读 → 权限管理 → 公有读私有写',
                'exception'     => '❌ 腾讯云 COS 异常：' . $detail,
            ],
            'oss' => [
                'connect_fail' => '检查 Endpoint/Bucket/AccessKeyID 是否正确',
                'put_fail'      => 'AccessKey 没有 PutObject 权限，或 RAM 策略禁止',
                'get_fail'      => '桶 ACL 没有公共读',
            ],
            'sftp' => [
                'connect_fail' => '检查主机/端口/用户名/密钥是否正确',
                'put_fail'      => '远程目录不可写：检查 owner/权限',
                'get_fail'      => '远程目录不可读',
            ],
        ];

        return $hints[$driver][$stage] ?? "❌ {$driverName}：{$stage} " . $detail;
    }
}
