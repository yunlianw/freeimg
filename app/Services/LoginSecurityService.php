<?php
namespace App\Services;

use App\Core\Db;

/**
 * 登录安全服务：失败计数、IP 锁定、登录日志
 */
class LoginSecurityService
{
    const MAX_FAILED_DEFAULT = 5;       // 默认 5 次失败锁
    const LOCK_MINUTES_DEFAULT = 15;    // 默认锁 15 分钟

    /**
     * 检查用户是否被锁定
     */
    public static function isLocked(int $userId): bool
    {
        $row = Db::fetchOne(
            'SELECT locked_until, failed_login_count FROM users WHERE id = :id',
            ['id' => $userId]
        );
        if (!$row) return false;
        if (empty($row['locked_until'])) return false;
        return strtotime($row['locked_until']) > time();
    }

    /**
     * 检查用户名/邮箱是否存在（不告诉用户是否正确 — 防探测）
     */
    public static function userExists(string $username): bool
    {
        $row = Db::fetchOne(
            'SELECT id FROM users WHERE (username = :uname OR email = :uemail) AND deleted_at IS NULL',
            ['uname' => $username, 'uemail' => $username]
        );
        return $row !== null;
    }

    /**
     * 获取用户（用于登录）
     */
    public static function findUser(string $username): ?array
    {
        return Db::fetchOne(
            'SELECT * FROM users WHERE (username = :uname OR email = :uemail) AND deleted_at IS NULL AND status = 1',
            ['uname' => $username, 'uemail' => $username]
        );
    }

    /**
     * 按 ID 获取用户
     */
    public static function findUserById(int $userId): ?array
    {
        return Db::fetchOne(
            'SELECT * FROM users WHERE id = :id AND deleted_at IS NULL AND status = 1',
            ['id' => $userId]
        );
    }

    /**
     * 记录一次失败登录（并按策略决定是否锁定）
     */
    public static function recordFailure(int $userId): bool
    {
        $max = (int)config('settings.login_max_failed') ?: self::MAX_FAILED_DEFAULT;
        $lockMinutes = (int)config('settings.login_lock_minutes') ?: self::LOCK_MINUTES_DEFAULT;

        $row = Db::fetchOne(
            'SELECT failed_login_count FROM users WHERE id = :id',
            ['id' => $userId]
        );
        $count = ((int)($row['failed_login_count'] ?? 0)) + 1;
        $lockedUntil = null;
        if ($count >= $max) {
            $lockedUntil = date('Y-m-d H:i:s', time() + $lockMinutes * 60);
            $count = 0; // 重置计数（解锁后从0开始）
        }

        Db::update('users',
            [
                'failed_login_count' => $count,
                'locked_until'       => $lockedUntil,
                'last_failed_at'     => date('Y-m-d H:i:s'),
            ],
            'id = :id',
            ['id' => $userId]
        );
        return $lockedUntil !== null;
    }

    /**
     * 记录成功登录（重置计数 + 更新 last_login）
     */
    public static function recordSuccess(int $userId, string $ip): void
    {
        Db::update('users',
            [
                'failed_login_count' => 0,
                'locked_until'       => null,
                'last_login_at'      => date('Y-m-d H:i:s'),
                'last_login_ip'      => substr($ip, 0, 64),
            ],
            'id = :id',
            ['id' => $userId]
        );
    }

    /**
     * 仅清零失败计数（不动 last_login，用于 2FA 中间态：密码对但 2FA 还没过）
     */
    public static function resetFailedCount(int $userId): void
    {
        Db::update('users',
            [
                'failed_login_count' => 0,
                'locked_until'       => null,
            ],
            'id = :id',
            ['id' => $userId]
        );
    }

    /**
     * 写入登录日志
     */
    public static function log(?int $userId, ?string $username, string $ip, ?string $ua, string $status, ?string $reason = null): void
    {
        Db::insert('login_logs', [
            'user_id'    => $userId,
            'username'   => $username ? substr($username, 0, 64) : null,
            'ip'         => substr($ip, 0, 64),
            'user_agent' => $ua ? substr($ua, 0, 255) : null,
            'status'     => $status,
            'reason'     => $reason ? substr($reason, 0, 128) : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 清理 30 天前的登录日志
     */
    public static function cleanupOldLogs(int $days = 30): int
    {
        $threshold = date('Y-m-d H:i:s', time() - $days * 86400);
        return Db::execute(
            'DELETE FROM login_logs WHERE created_at < :t',
            ['t' => $threshold]
        )->rowCount();
    }

    /**
     * 获取最近登录日志（管理员面板用）
     */
    public static function recentLogs(int $limit = 50): array
    {
        return Db::fetchAll(
            'SELECT * FROM login_logs ORDER BY id DESC LIMIT ' . max(1, min(500, $limit))
        );
    }
}