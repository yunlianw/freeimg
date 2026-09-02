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
        // v1.3.8: 向后兼容别名映射
        // - small → saver（老档位名）
        // - extreme → mega（极限压缩档老名，跟前端 QUALITY_PRESETS/旧文档一致）
        $aliases = [
            'small'   => 'saver',
            'extreme' => 'mega',
        ];
        if (isset($aliases[$code])) {
            $code = $aliases[$code];
        }
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
        // 已禁用的内置档允许编辑（否则清理不掉）
        if (!$row || ($row['is_builtin'] && $row['enabled'])) {
            return 0;
        }
        $data['updated_at'] = date('Y-m-d H:i:s');
        unset($data['is_builtin']);
        return Db::update('compression_profiles', $data, 'id = :id', ['id' => $id]);
    }

    public function delete(int $id): int
    {
        $row = $this->find($id);
        // 已禁用的内置档允许删除（防止"死档"残留）
        if (!$row || ($row['is_builtin'] && $row['enabled'])) {
            return 0;
        }
        return Db::delete('compression_profiles', 'id = :id', ['id' => $id]);
    }

    /**
     * 解析压缩配置：按优先级查找（API 上下文）
     * 优先级：opts 显式 > apiKey 关联 > settings.api_compression_profile_id > balanced（显式兜底）
     * 注意：API 不静默回退到 Web 默认档（web_compression_profile_id），
     *       未配置时明确用 balanced，保证 API 行为可预期。
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

        // apiKey 关联 profile（如老季给 PicGo 绑 extreme）
        if ($apiKey && !empty($apiKey['compression_profile_id'])) {
            $p = $this->find((int)$apiKey['compression_profile_id']);
            if ($p) return $p;
        }

        // API 全局默认档（后台"压缩设置→API 默认压缩档"）
        $apiId = (int)(config('settings.api_compression_profile_id') ?? 0);
        if ($apiId) {
            $p = $this->find($apiId);
            if ($p) return $p;
        }

        // 显式兜底：balanced（不再静默回退到 Web 档）
        return $this->findByCode('balanced') ?? [];
    }
}
