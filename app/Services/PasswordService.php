<?php
namespace App\Services;

use App\Core\Db;

/**
 * 密码服务：强度校验、bcrypt、历史检查
 */
class PasswordService
{
    const MIN_LENGTH_DEFAULT = 10;
    const HISTORY_DEFAULT = 5; // 禁止重用最近 N 个密码

    /**
     * 评估密码强度（返回 0-4 分）
     * 0 = 弱, 1 = 一般, 2 = 中, 3 = 强, 4 = 极强
     */
    public static function strength(string $password): int
    {
        if (strlen($password) < 8) return 0;
        $score = 0;
        if (strlen($password) >= 10) $score++;
        if (strlen($password) >= 14) $score++;
        if (preg_match('/[a-z]/', $password) && preg_match('/[A-Z]/', $password)) $score++;
        if (preg_match('/\d/', $password) && preg_match('/[^a-zA-Z0-9]/', $password)) $score++;
        if (strlen($password) >= 18) $score++; // 长密码加成
        return min(4, $score);
    }

    /**
     * 校验密码强度（返回错误信息或 null）
     */
    public static function validate(string $password, ?int $minLength = null): ?string
    {
        $min = $minLength ?? ((int)config('settings.password_min_length') ?: self::MIN_LENGTH_DEFAULT);
        if (strlen($password) < $min) {
            return '密码长度至少 ' . $min . ' 位';
        }
        $strength = self::strength($password);
        if ($strength < 2) {
            return '密码强度不足：建议包含大小写字母、数字、特殊符号';
        }
        return null;
    }

    /**
     * 设置密码（bcrypt + 更新历史）
     */
    public static function setPassword(int $userId, string $newPassword): bool
    {
        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        if ($hash === false) return false;

        // 保留最近 N 个密码 hash（防重用）
        $history = (int)config('settings.password_history_count') ?: self::HISTORY_DEFAULT;
        $row = Db::fetchOne('SELECT password_history FROM users WHERE id = :id', ['id' => $userId]);
        $oldHistory = $row['password_history'] ? json_decode($row['password_history'], true) : [];
        // 旧的密码 hash 也加入历史（防回滚到旧密码）
        $oldCurrent = Db::fetchValue('SELECT password FROM users WHERE id = :id', ['id' => $userId]);
        if ($oldCurrent) array_unshift($oldHistory, $oldCurrent);
        $oldHistory = array_slice($oldHistory, 0, $history);

        Db::update('users',
            [
                'password'            => $hash,
                'password_changed_at' => date('Y-m-d H:i:s'),
                'password_history'    => json_encode($oldHistory),
            ],
            'id = :id',
            ['id' => $userId]
        );
        return true;
    }

    /**
     * 检查密码是否在历史中（防重用）
     */
    public static function isInHistory(int $userId, string $password): bool
    {
        $row = Db::fetchOne('SELECT password_history FROM users WHERE id = :id', ['id' => $userId]);
        if (!$row || empty($row['password_history'])) return false;
        $history = json_decode($row['password_history'], true);
        if (!is_array($history)) return false;
        foreach ($history as $oldHash) {
            if (password_verify($password, $oldHash)) return true;
        }
        return false;
    }

    /**
     * 验证密码（用于登录）
     */
    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * 强度文字描述
     */
    public static function strengthLabel(int $score): string
    {
        return ['极弱', '弱', '一般', '强', '极强'][$score] ?? '未知';
    }
}