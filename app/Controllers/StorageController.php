<?php
namespace App\Controllers;

use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Drivers\StorageManager;

/**
 * 存储驱动管理（后台）
 * 支持：列表 / 新增 / 编辑 / 启用禁用 / 设默认 / 删除 / 测试连接
 */
class StorageController
{
    /** 驱动定义（来自 app/Config/StorageDrivers.php） */
    private function drivers(): array
    {
        $file = FREEIMG_ROOT . '/app/Config/StorageDrivers.php';
        return file_exists($file) ? (require $file) : [];
    }

    /** 列表页 */
    public function index(): void
    {
        $rows = Db::fetchAll('SELECT * FROM storages ORDER BY is_default DESC, id ASC');
        $drivers = $this->drivers();

        $list = [];
        foreach ($rows as $r) {
            $cfg = json_decode(decrypt_secret($r['config']), true) ?: [];
            $driver = $drivers[$r['driver']] ?? ['label' => $r['driver'], 'icon' => '📦'];
            $capacityMb = (int)($r['max_capacity_mb'] ?? 0);
            $usedMb = (int)$r['current_usage_mb'];
            $isFull = StorageManager::isFull($r);
            $list[] = [
                'id'         => (int)$r['id'],
                'name'       => $r['name'],
                'driver'     => $r['driver'],
                'driver_label' => ($driver['icon'] ?? '') . ' ' . ($driver['label'] ?? $r['driver']),
                'is_default' => (int)$r['is_default'],
                'status'     => (int)$r['status'],
                'priority'   => (int)$r['priority'],
                'visible_in_upload' => (int)$r['visible_in_upload'],
                'max_capacity_mb' => $capacityMb,
                'current_usage_mb' => $usedMb,
                'capacity_pct' => $capacityMb > 0 ? min(100, (int)($usedMb / $capacityMb * 100)) : 0,
                'is_full'    => $isFull,
                'summary'    => $this->summary($r['driver'], $cfg),
                'created_at' => $r['created_at'],
            ];
        }

        Response::view('storages/index', [
            'list'    => $list,
            'drivers' => $drivers,
            'csrf'    => csrf_token(),
        ], 'main');
    }

    /** 新增/编辑表单页：?driver=sftp 预选 / ?id=5 编辑 */
    public function form(Request $request): void
    {
        $id = (int)$request->query('id', 0);
        $driver = (string)$request->query('driver', 'local');
        $drivers = $this->drivers();

        $item = ['id' => 0, 'name' => '', 'driver' => $driver, 'config' => [], 'status' => 1, 'is_default' => 0, 'visible_in_upload' => 1, 'priority' => 0, 'max_capacity_mb' => null];
        if ($id > 0) {
            $row = Db::fetchOne('SELECT * FROM storages WHERE id = :id', ['id' => $id]);
            if (!$row) {
                flash('error', '存储不存在');
                Response::redirect(base_url('storages'));
            }
            $item = [
                'id'         => (int)$row['id'],
                'name'       => $row['name'],
                'driver'     => $row['driver'],
                'config'     => json_decode(decrypt_secret($row['config']), true) ?: [],
                'status'     => (int)$row['status'],
                'is_default' => (int)$row['is_default'],
            ];
            $driver = $row['driver'];
        }

        if (!isset($drivers[$driver])) {
            flash('error', '不支持的驱动类型');
            Response::redirect(base_url('storages'));
        }

        Response::view('storages/form', [
            'item'    => $item,
            'driver'  => $driver,
            'drivers' => $drivers,
            'def'     => $drivers[$driver],
            'csrf'    => csrf_token(),
        ], 'main');
    }

    /** 保存（新增/编辑） */
    public function save(Request $request): void
    {
        $token = $request->post('csrf_token', '');
        if (!csrf_check($token)) {
            flash('error', '会话已过期');
            Response::redirect(base_url('storages'));
        }

        $id = (int)$request->post('id', 0);
        $driver = (string)$request->post('driver', '');
        $name = trim((string)$request->post('name', ''));
        $status = $request->post('status') ? 1 : 0;
        $drivers = $this->drivers();

        if ($name === '') {
            flash('error', '请填写存储名称');
            Response::redirect(base_url('storages/form?driver=' . urlencode($driver) . ($id ? "&id=$id" : '')));
        }
        if (!isset($drivers[$driver])) {
            flash('error', '不支持的驱动类型');
            Response::redirect(base_url('storages'));
        }

        // 收集该驱动的配置字段（跳过空密码 = 不修改）
        $cfg = [];
        $fields = $drivers[$driver]['fields'];
        // 敏感字段：编辑时留空则保留原值（动态生成，避免遗漏）
        $secretFields = [];
        foreach ($fields as $fk => $fv) {
            if (($fv['type'] ?? '') === 'password') $secretFields[] = $fk;
        }
        foreach ($fields as $key => $f) {
            $val = trim((string)$request->post('cfg_' . $key, ''));
            // 编辑时密码留空 → 保留原值
            if ($id > 0 && in_array($key, $secretFields, true) && $val === '') {
                continue;
            }
            if ($f['type'] === 'number') {
                $val = $val === '' ? ($f['default'] ?? '') : (string)(int)$val;
            } elseif ($val === '' && isset($f['default'])) {
                $val = (string)$f['default'];
            }
            // 必填校验
            if (!empty($f['required']) && $val === '') {
                flash('error', '请填写：' . $f['label']);
                Response::redirect(base_url('storages/form?driver=' . urlencode($driver) . ($id ? "&id=$id" : '')));
            }
            $cfg[$key] = $val;
        }

        // 编辑时保留原密码字段
        if ($id > 0) {
            $row = Db::fetchOne('SELECT config FROM storages WHERE id = :id', ['id' => $id]);
            if ($row) {
                $old = json_decode(decrypt_secret($row['config']), true) ?: [];
                foreach ($secretFields as $p) {
                    if (!array_key_exists($p, $cfg) && isset($old[$p])) $cfg[$p] = $old[$p];
                }
            }
        }

        $encrypted = encrypt_secret(json_encode($cfg, JSON_UNESCAPED_UNICODE));
        $now = date('Y-m-d H:i:s');

        // 新增字段
        $priority = (int)$request->post('priority', 0);
        $visible = $request->post('visible_in_upload') ? 1 : 0;
        $maxMb = trim((string)$request->post('max_capacity_mb', ''));
        $maxMb = $maxMb === '' ? null : max(0, (int)$maxMb);

        if ($id > 0) {
            Db::update('storages', [
                'name'       => $name,
                'config'     => $encrypted,
                'status'     => $status,
                'priority'   => $priority,
                'visible_in_upload' => $visible,
                'max_capacity_mb'   => $maxMb,
                'updated_at' => $now,
            ], 'id = :id', ['id' => $id]);
            flash('success', '存储已更新');
        } else {
            $user = \App\Services\AuthService::user();
            Db::insert('storages', [
                'user_id'    => (int)($user['id'] ?? 0),
                'name'       => $name,
                'driver'     => $driver,
                'config'     => $encrypted,
                'is_default' => 0,
                'status'     => $status,
                'priority'   => $priority,
                'visible_in_upload' => $visible,
                'max_capacity_mb'   => $maxMb,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            flash('success', '存储已添加');
        }

        Response::redirect(base_url('storages'));
    }

    /** 启用/禁用 */
    public function toggle(Request $request): void
    {
        $this->guard($request);
        $id = (int)$request->post('id', 0);
        $row = Db::fetchOne('SELECT * FROM storages WHERE id = :id', ['id' => $id]);
        if (!$row) {
            flash('error', '存储不存在');
            Response::redirect(base_url('storages'));
        }
        $new = $row['status'] ? 0 : 1;
        Db::update('storages', ['status' => $new, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
        flash('success', $new ? '已启用' : '已禁用');
        Response::redirect(base_url('storages'));
    }

    /** 设为默认 */
    public function setDefault(Request $request): void
    {
        $this->guard($request);
        $id = (int)$request->post('id', 0);
        $row = Db::fetchOne('SELECT * FROM storages WHERE id = :id', ['id' => $id]);
        if (!$row) {
            flash('error', '存储不存在');
            Response::redirect(base_url('storages'));
        }
        // 单条 SQL 原子切换：目标 id 为 1，其余全 0（避免中间态丢默认）
        Db::execute('UPDATE storages SET is_default = (id = :id), updated_at = :now', [
            'id'  => $id,
            'now' => date('Y-m-d H:i:s'),
        ]);
        flash('success', '已设为默认存储');
        Response::redirect(base_url('storages'));
    }

    /** 删除（默认存储不可删） */
    public function delete(Request $request): void
    {
        $this->guard($request);
        $id = (int)$request->post('id', 0);
        $row = Db::fetchOne('SELECT * FROM storages WHERE id = :id', ['id' => $id]);
        if (!$row) {
            flash('error', '存储不存在');
            Response::redirect(base_url('storages'));
        }
        if ((int)$row['is_default'] === 1) {
            flash('error', '默认存储不能删除，请先切换默认');
            Response::redirect(base_url('storages'));
        }
        Db::execute('DELETE FROM storages WHERE id = :id', ['id' => $id]);
        flash('success', '已删除');
        Response::redirect(base_url('storages'));
    }

    /** 测试连接（AJAX） */
    public function test(Request $request): void
    {
        $token = $request->post('csrf_token', '');
        if (!csrf_check($token)) {
            Response::json(['ok' => false, 'message' => '会话已过期']);
        }

        $driver = (string)$request->post('driver', '');
        $drivers = $this->drivers();
        if (!isset($drivers[$driver])) {
            Response::json(['ok' => false, 'message' => '不支持的驱动类型']);
        }

        $cfg = [];
        foreach ($drivers[$driver]['fields'] as $key => $f) {
            $cfg[$key] = trim((string)$request->post('cfg_' . $key, ''));
        }

        $result = StorageManager::testConfig($driver, $cfg);
        Response::json($result);
    }

    /** Phase 8: 重新计算存储容量（从 images 表实际 final_size 统计） */
    public function recalc(Request $request): void
    {
        $token = $request->post('csrf_token', '');
        if (!csrf_check($token)) {
            flash('error', '会话已过期');
            Response::redirect(base_url('storages'));
        }
        $id = (int)$request->post('id', 0);
        if ($id <= 0) {
            flash('error', '参数错误');
            Response::redirect(base_url('storages'));
        }
        $mb = StorageManager::recalcUsage($id);
        flash('success', "已重新计算容量：" . number_format($mb / 1024 / 1024, 2) . ' GB');
        Response::redirect(base_url('storages'));
    }

    /** 列表摘要（展示用，脱敏） */
    private function summary(string $driver, array $cfg): string
    {
        switch ($driver) {
            case 'sftp':
                return ($cfg['host'] ?? '?') . ':' . ($cfg['port'] ?? '22') . ' → ' . ($cfg['path'] ?? '?');
            case 's3':
            case 'obs':
                return ($cfg['bucket'] ?? '?') . ' @ ' . ($cfg['endpoint'] ?? '?');
            case 'cos':
                return ($cfg['bucket'] ?? '?') . ' @ ' . ($cfg['region'] ?? '?');
            case 'oss':
                return ($cfg['bucket'] ?? '?') . ' @ ' . ($cfg['endpoint'] ?? '?');
            default:
                return $cfg['path'] ?? $cfg['url'] ?? '';
        }
    }

    private function guard(Request $request): void
    {
        $token = $request->post('csrf_token', '');
        if (!csrf_check($token)) {
            flash('error', '会话已过期');
            Response::redirect(base_url('storages'));
        }
    }
}
