<?php
namespace App\Services;

use App\Core\Db;

/**
 * 会话服务：DB-backed 会话，支持强制下线、超时配置
 */
class SessionService
{
    const DEFAULT_TTL_HOURS = 24; // 默认 24 小时

    /**
     * 获取当前会话 TTL（秒）
     */
    public static function ttlSeconds(): int
    {
        $hours = (int)config('settings.session_ttl_hours') ?: self::DEFAULT_TTL_HOURS;
        $hours = max(1, min(8760, $hours)); // 1h ~ 12月
        return $hours * 3600;
    }

    /**
     * 创建会话
     */
    public static function create(int $userId, string $ip, ?string $ua, array $payload = []): string
    {
        $token = bin2hex(random_bytes(32));
        $now = date('Y-m-d H:i:s');
        $expires = date('Y-m-d H:i:s', time() + self::ttlSeconds());
        Db::insert('user_sessions', [
            'user_id'          => $userId,
            'session_token'    => $token,
            'ip'               => substr($ip, 0, 64),
            'user_agent'       => $ua ? substr($ua, 0, 255) : null,
            'payload'          => json_encode($payload),
            'last_activity_at' => $now,
            'expires_at'       => $expires,
            'created_at'       => $now,
        ]);
        return $token;
    }

    /**
     * 查找有效会话
     */
    public static function findValid(string $token): ?array
    {
        $row = Db::fetchOne(
            'SELECT * FROM user_sessions WHERE session_token = :t AND expires_at > NOW()',
            ['t' => $token]
        );
        return $row;
    }

    /**
     * 刷新会话（更新 last_activity + 滑动过期）
     */
    public static function touch(string $token): bool
    {
        // 滑动过期：每次活动延长到完整 TTL（也可以固定过期，看产品选择）
        $newExpires = date('Y-m-d H:i:s', time() + self::ttlSeconds());
        return Db::update('user_sessions',
            [
                'last_activity_at' => date('Y-m-d H:i:s'),
                'expires_at'       => $newExpires,
            ],
            'session_token = :t',
            ['t' => $token]
        ) > 0;
    }

    /**
     * 销毁单个会话
     */
    public static function destroy(string $token): int
    {
        return Db::delete('user_sessions', 'session_token = :t', ['t' => $token]);
    }

    /**
     * 销毁某用户的所有会话（强制下线 / 改密后踢人）
     */
    public static function destroyAllForUser(int $userId, ?string $exceptToken = null): int
    {
        if ($exceptToken) {
            return Db::execute(
                'DELETE FROM user_sessions WHERE user_id = :uid AND session_token != :tk',
                ['uid' => $userId, 'tk' => $exceptToken]
            )->rowCount();
        }
        return Db::delete('user_sessions', 'user_id = :uid', ['uid' => $userId]);
    }

    /**
     * 列出用户的所有活跃会话（管理面板）
     */
    public static function listForUser(int $userId): array
    {
        return Db::fetchAll(
            'SELECT * FROM user_sessions
             WHERE user_id = :uid AND expires_at > NOW()
             ORDER BY last_activity_at DESC',
            ['uid' => $userId]
        );
    }

    /**
     * 清理过期会话
     */
    public static function cleanupExpired(): int
    {
        return Db::execute('DELETE FROM user_sessions WHERE expires_at < NOW()')->rowCount();
    }

    /**
     * 强制并发限制：超过上限时踢掉最老的（按 last_activity_at ASC）
     *
     * 调用场景：登录成功后，新会话即将 INSERT 之前调用
     *   - 已有活跃数 + 1（新会话） > limit 时才踢
     *   - 上限 1 时等价于"单点登录"（踢掉所有旧 session）
     *   - 过期 session 不占名额（先 cleanupExpired）
     *
     * 返回被踢的 session 数（供调用方日志/调试）
     */
    public static function enforceLimit(int $userId, int $limit): int
    {
        // 防御性钳制（控制器已有 1-20 校验，这是双保险；防 DB 字段被篡改越界）
        $limit = max(1, min(20, $limit));
        self::cleanupExpired();
        $active = (int)(Db::fetchOne(
            'SELECT COUNT(*) AS c FROM user_sessions WHERE user_id = :uid AND expires_at > NOW()',
            ['uid' => $userId]
        )['c'] ?? 0);
        // 新会话占 1 个名额，所以只需踢掉 ($active - ($limit - 1)) 个
        $excess = $active - ($limit - 1);
        if ($excess <= 0) return 0;
        return Db::execute(
            'DELETE FROM user_sessions
             WHERE user_id = :uid AND expires_at > NOW()
             ORDER BY last_activity_at ASC
             LIMIT :lim',
            ['uid' => $userId, 'lim' => $excess]
        )->rowCount();
    }
}