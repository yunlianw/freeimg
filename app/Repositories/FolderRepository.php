<?php
namespace App\Repositories;

use App\Core\Db;

class FolderRepository
{
    public function find(int $id): ?array
    {
        return Db::fetchOne(
            'SELECT * FROM folders WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id]
        );
    }

    public function listByUser(int $userId): array
    {
        return Db::fetchAll(
            'SELECT * FROM folders WHERE user_id = :uid AND deleted_at IS NULL ORDER BY id ASC',
            ['uid' => $userId]
        );
    }

    /**
     * 相册列表（含图片数 + 封面缩略图）
     */
    public function listByUserWithStats(int $userId): array
    {
        return Db::fetchAll(
            'SELECT f.*,
                (SELECT COUNT(*) FROM images i WHERE i.folder_id = f.id AND i.status = "active" AND i.deleted_at IS NULL) AS image_count,
                (SELECT i2.public_url FROM images i2 WHERE i2.folder_id = f.id AND i2.status = "active" AND i2.deleted_at IS NULL ORDER BY i2.id DESC LIMIT 1) AS cover_url
             FROM folders f
             WHERE f.user_id = :uid AND f.deleted_at IS NULL
             ORDER BY f.id DESC',
            ['uid' => $userId]
        );
    }

    /**
     * 按分享 token 查相册（含未删除的）
     */
    public function findByShareToken(string $token): ?array
    {
        return Db::fetchOne(
            'SELECT * FROM folders WHERE share_token = :t AND deleted_at IS NULL',
            ['t' => $token]
        );
    }

    /**
     * 开启/更新分享
     */
    public function setShare(int $id, string $token, ?string $expiresAt, ?string $passwordHash): int
    {
        return Db::update(
            'folders',
            [
                'share_token'       => $token,
                'share_expires_at'  => $expiresAt,
                'share_password'    => $passwordHash,
                'updated_at'        => date('Y-m-d H:i:s'),
            ],
            'id = :id',
            ['id' => $id]
        );
    }

    /**
     * 关闭分享
     */
    public function unshare(int $id): int
    {
        return Db::update(
            'folders',
            [
                'share_token'      => null,
                'share_expires_at' => null,
                'share_password'   => null,
                'updated_at'       => date('Y-m-d H:i:s'),
            ],
            'id = :id',
            ['id' => $id]
        );
    }

    /**
     * 同名检查
     */
    public function nameExists(string $name, int $userId, ?int $parentId, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM folders WHERE user_id = :uid AND name = :n AND deleted_at IS NULL';
        $params = ['uid' => $userId, 'n' => $name];
        if ($parentId === null) {
            $sql .= ' AND parent_id IS NULL';
        } else {
            $sql .= ' AND parent_id = :pid';
            $params['pid'] = $parentId;
        }
        if ($exceptId !== null) {
            $sql .= ' AND id != :eid';
            $params['eid'] = $exceptId;
        }
        return (int)Db::fetchValue($sql, $params) > 0;
    }

    public function create(array $data): int
    {
        return Db::insert('folders', $data);
    }

    public function rename(int $id, string $name): int
    {
        return Db::update(
            'folders',
            ['name' => $name, 'updated_at' => date('Y-m-d H:i:s')],
            'id = :id',
            ['id' => $id]
        );
    }

    public function softDelete(int $id): int
    {
        return Db::update(
            'folders',
            ['deleted_at' => date('Y-m-d H:i:s')],
            'id = :id',
            ['id' => $id]
        );
    }

    /**
     * 相册删除时，把其中图片移出（folder_id 置空，图片保留）
     */
    public function detachImages(int $folderId, ?int $userId = null): int
    {
        $where = 'folder_id = :fid';
        $params = ['fid' => $folderId];
        if ($userId !== null) {
            $where .= ' AND user_id = :uid';
            $params['uid'] = $userId;
        }
        return Db::update(
            'images',
            ['folder_id' => null, 'updated_at' => date('Y-m-d H:i:s')],
            $where,
            $params
        );
    }
}