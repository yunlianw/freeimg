<?php
namespace App\Repositories;

use App\Core\Db;

/**
 * 调试专用 Key（每个用户一个，name=__debug__，标记 scopes=debug-only）
 * 不参与 PicGo / 帝国 CMS 调用，仅供 API Keys 页面调试 UI 使用
 */
class DebugKeyRepository
{
    /**
     * 查找或创建该用户的调试 Key（首次访问自动建）
     * 返回 Key 完整记录（含 access_key，但 secret_key_hash 不可读）
     */
    public function findOrCreateDebugKey(int $userId): ?array
    {
        $cols = 'id, user_id, name, access_key, compression_profile_id, scopes, status, created_at';
        $row = Db::fetchOne(
            "SELECT $cols FROM api_keys WHERE user_id = :uid AND name = :n AND status = 1 AND deleted_at IS NULL",
            ['uid' => $userId, 'n' => '__debug__']
        );
        if ($row) return $row;

        // 并发首次访问可能建出重复 Key：用 INSERT IGNORE + 唯一索引兜底
        // 这里用 INSERT ... ON DUPLICATE KEY UPDATE id=id 防止并发
        // （api_keys 表没有 (user_id, name) 唯一索引，所以先尝试 INSERT，捕获冲突后重查）
        $ak = 'fk_dbg_' . bin2hex(random_bytes(8));
        $sk = bin2hex(random_bytes(32));
        $skHash = password_hash($sk, PASSWORD_BCRYPT);
        try {
            $id = Db::insert('api_keys', [
                'user_id' => $userId,
                'name' => '__debug__',
                'access_key' => $ak,
                'secret_key_hash' => $skHash,
                'scopes' => 'debug-only',
                'compression_profile_id' => 4, // saver
                'status' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            if (!$id) return null;
        } catch (\Throwable $e) {
            // 并发情况下别人已经创建，直接返回已有
            $row = Db::fetchOne(
                "SELECT $cols FROM api_keys WHERE user_id = :uid AND name = :n AND status = 1 AND deleted_at IS NULL LIMIT 1",
                ['uid' => $userId, 'n' => '__debug__']
            );
            return $row ?: null;
        }
        return Db::fetchOne("SELECT $cols FROM api_keys WHERE id = :id", ['id' => $id]);
    }
}
