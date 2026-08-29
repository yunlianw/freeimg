<?php
namespace App\Repositories;

use App\Core\Db;

class CompressionProfileRepository
{
    public function listEnabled(): array
    {
        return Db::fetchAll(
            'SELECT * FROM compression_profiles WHERE enabled = 1 ORDER BY sort_order ASC, id ASC'
        );
    }

    public function find(int $id): ?array
    {
        return Db::fetchOne('SELECT * FROM compression_profiles WHERE id = :id', ['id' => $id]);
    }

    public function findByCode(string $code): ?array
    {
        return Db::fetchOne('SELECT * FROM compression_profiles WHERE code = :c', ['c' => $code]);
    }

    public function create(array $data): int
    {
        $data['is_builtin'] = 0;
        $data['enabled'] = $data['enabled'] ?? 1;
        $data['created_at'] = date('Y-m-d H:i:s');
        return Db::insert('compression_profiles', $data);
    }

    public function update(int $id, array $data): int
    {
        $row = $this->find($id);
        if (!$row || $row['is_builtin']) {
            return 0;
        }
        $data['updated_at'] = date('Y-m-d H:i:s');
        unset($data['is_builtin']);
        return Db::update('compression_profiles', $data, 'id = :id', ['id' => $id]);
    }

    public function delete(int $id): int
    {
        $row = $this->find($id);
        if (!$row || $row['is_builtin']) {
            return 0;
        }
        return Db::delete('compression_profiles', 'id = :id', ['id' => $id]);
    }

    /**
     * 解析压缩配置：按优先级查找
     * 优先级：opts 显式 > apiKey 关联 > settings.api > settings.web > balanced
     */
    /**
     * 解析压缩配置：按优先级查找
     * 优先级：opts 显式 > apiKey 关联 > settings.api > settings.web > balanced
     */
    public function resolve(array $opts = [], ?array $apiKey = null): array
    {
        if (!empty($opts['compression_profile_id'])) {
            $p = $this->find((int)$opts['compression_profile_id']);
            if ($p) return $p;
        }
        // opts.compression（如 PicGo 选 balanced）优先于 apiKey 关联
        // 这样调用方可临时覆盖 apiKey 预设
        if (!empty($opts['compression'])) {
            $p = $this->findByCode((string)$opts['compression']);
            if ($p) return $p;
        }

        // apiKey 关联 profile（如 PicGo 默认绑定 extreme）
        if ($apiKey && !empty($apiKey['compression_profile_id'])) {
            $p = $this->find((int)$apiKey['compression_profile_id']);
            if ($p) return $p;
        }

        $apiId = (int)(config('settings.api_compression_profile_id') ?? 0);
        if ($apiId) {
            $p = $this->find($apiId);
            if ($p) return $p;
        }

        $webId = (int)(config('settings.web_compression_profile_id') ?? 0);
        if ($webId) {
            $p = $this->find($webId);
            if ($p) return $p;
        }

        return $this->findByCode('balanced') ?? [];
    }
}
