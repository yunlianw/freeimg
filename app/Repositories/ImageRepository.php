<?php
namespace App\Repositories;

use App\Core\Db;

class ImageRepository
{
    /**
     * 按 ID 查
     */
    public function find(int $id): ?array
    {
        return Db::fetchOne('SELECT * FROM images WHERE id = :id', ['id' => $id]);
    }

    /**
     * 按 UUID 查
     */
    public function findByUuid(string $uuid): ?array
    {
        return Db::fetchOne('SELECT * FROM images WHERE uuid = :u', ['u' => $uuid]);
    }

    /**
     * 按 sha256 + user 查（去重）
     */
    public function findBySha256(string $sha256, int $userId): ?array
    {
        return Db::fetchOne(
            'SELECT * FROM images WHERE sha256 = :s AND user_id = :uid AND status = "active"',
            ['s' => $sha256, 'uid' => $userId]
        );
    }

    /**
     * 插入
     */
    public function create(array $data): int
    {
        try {
            return Db::insert('images', $data);
        } catch (\PDOException $e) {
            // 1062 Duplicate entry → 并发同 sha256 上传撞唯一键，重查返回已有记录
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), '1062')) {
                $sha256 = $data['sha256'] ?? null;
                $userId = $data['user_id'] ?? null;
                if ($sha256 && $userId) {
                    $existing = $this->findBySha256($sha256, (int)$userId);
                    if ($existing) {
                        return (int)$existing['id'];
                    }
                }
            }
            throw $e;
        }
    }

    /**
     * 加标签
     */
    public function addTags(int $imageId, array $tags): void
    {
        if (empty($tags) || !$imageId) return;
        $existing = Db::fetchValue('SELECT tags FROM images WHERE id = :id', ['id' => $imageId]);
        $old = array_filter(array_map('trim', explode(',', (string)$existing)));
        $all = array_values(array_unique(array_merge($old, $tags)));
        Db::update('images', ['tags' => implode(',', $all)], 'id = :id', ['id' => $imageId]);
    }

    /**
     * 软删除（移到回收站）
     */
    public function softDelete(int $id): int
    {
        return Db::update(
            'images',
            ['status' => 'recycle', 'deleted_at' => date('Y-m-d H:i:s')],
            'id = :id',
            ['id' => $id]
        );
    }

    /**
     * 恢复
     */
    public function restore(int $id): int
    {
        return Db::update(
            'images',
            ['status' => 'active', 'deleted_at' => null],
            'id = :id AND status = :s',
            ['id' => $id, 's' => 'recycle']
        );
    }

    /**
     * 真删（物理）
     */
    public function hardDelete(int $id): int
    {
        return Db::delete('images', 'id = :id', ['id' => $id]);
    }

    /**
     * 重命名
     */
    public function rename(int $id, string $name): int
    {
        return Db::update(
            'images',
            ['original_name' => $name, 'updated_at' => date('Y-m-d H:i:s')],
            'id = :id',
            ['id' => $id]
        );
    }

    /**
     * 移动到文件夹
     */
    public function move(int $id, ?int $folderId): int
    {
        return Db::update(
            'images',
            ['folder_id' => $folderId, 'updated_at' => date('Y-m-d H:i:s')],
            'id = :id',
            ['id' => $id]
        );
    }

    /**
     * 列表（带筛选 + 分页）
     */
    public function paginate(array $where, int $page = 1, int $pageSize = 20, string $orderBy = 'created_at DESC'): array
    {
        $sql = 'SELECT * FROM images';
        $countSql = 'SELECT COUNT(*) FROM images';
        $conds = [];
        $params = [];

        $conditions = [];
        if (isset($where['user_id'])) {
            $conditions[] = 'user_id = :user_id';
            $params['user_id'] = $where['user_id'];
        }
        if (isset($where['folder_id'])) {
            $conditions[] = 'folder_id = :folder_id';
            $params['folder_id'] = (int)$where['folder_id'];
        }
        if (isset($where['folder_path'])) {
            // folder_path 是物理路径字符串（如 'covers' 或 'covers/2024'）
            // 兼容 storage_path 带/不带 url_path_prefix 前缀的两种历史数据
            $prefix = trim((string)(\config('settings.url_path_prefix') ?? 'img'), '/');
            $conditions[] = '(
                storage_path = :folder_path
                OR storage_path LIKE :folder_path_slash
                OR storage_path = :folder_path_with_prefix
                OR storage_path LIKE :folder_path_with_prefix_slash
            )';
            $params['folder_path'] = $where['folder_path'];
            $params['folder_path_slash'] = $where['folder_path'] . '/%';
            $params['folder_path_with_prefix'] = $prefix . '/' . $where['folder_path'];
            $params['folder_path_with_prefix_slash'] = $prefix . '/' . $where['folder_path'] . '/%';
        }
        if (isset($where['status'])) {
            $conditions[] = 'status = :status';
            $params['status'] = $where['status'];
        }
        if (!empty($where['keyword'])) {
            $conditions[] = 'original_name LIKE :kw';
            $params['kw'] = '%' . $where['keyword'] . '%';
        }
        if (!empty($where['extension'])) {
            $conditions[] = 'extension = :ext';
            $params['ext'] = $where['extension'];
        }

        if ($conditions) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
            $countSql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $total = (int)Db::fetchValue($countSql, $params);

        // ORDER BY 白名单（防止 SQL 注入）
        $allowedOrder = [
            'created_at DESC' => 'created_at DESC',
            'created_at ASC'  => 'created_at ASC',
            'id DESC'         => 'id DESC',
            'id ASC'          => 'id ASC',
            'size DESC'       => 'final_size DESC',
            'size ASC'        => 'final_size ASC',
            'name ASC'        => 'original_name ASC',
        ];
        $orderSql = $allowedOrder[$orderBy] ?? 'created_at DESC';

        $offset = max(0, ($page - 1) * $pageSize);
        $sql .= " ORDER BY {$orderSql} LIMIT {$pageSize} OFFSET {$offset}";
        $rows = Db::fetchAll($sql, $params);

        return [
            'items' => $rows,
            'total' => $total,
            'page'  => $page,
            'page_size' => $pageSize,
            'total_pages' => (int)ceil($total / max(1, $pageSize)),
        ];
    }

    /**
     * 统计用户用量
     */
    public function userUsage(int $userId): int
    {
        return (int)Db::fetchValue(
            'SELECT COALESCE(SUM(final_size), 0) FROM images WHERE user_id = :uid AND deleted_at IS NULL',
            ['uid' => $userId]
        );
    }

    /**
     * 30 天前进入回收站的图片（待清理）
     */
    public function findExpiredRecycle(int $beforeDays = 30): array
    {
        $threshold = date('Y-m-d H:i:s', strtotime("-{$beforeDays} days"));
        return Db::fetchAll(
            'SELECT * FROM images WHERE status = :s AND deleted_at < :t',
            ['s' => 'recycle', 't' => $threshold]
        );
    }

    /**
     * 批量把图片移入相册（同 user_id 校验）
     * 返回成功更新的条数
     */
    public function attachToFolder(array $imageIds, int $folderId, int $userId): int
    {
        if (empty($imageIds)) return 0;
        // 数字白名单（防 SQL 注入）
        $ids = array_values(array_filter(array_map('intval', $imageIds), fn($v) => $v > 0));
        if (empty($ids)) return 0;

        $placeholders = [];
        $params = ['fid' => $folderId, 'uid' => $userId];
        foreach ($ids as $i => $id) {
            $key = "id_$i";
            $placeholders[] = ":$key";
            $params[$key] = $id;
        }
        $sql = sprintf(
            'UPDATE images SET folder_id = :fid, updated_at = :upd WHERE user_id = :uid AND id IN (%s)',
            implode(',', $placeholders)
        );
        $params['upd'] = date('Y-m-d H:i:s');
        return Db::execute($sql, $params)->rowCount();
    }
}