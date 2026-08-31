<?php
namespace App\Controllers;

use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\CompressionProfileRepository;

/**
 * 压缩配置管理（picx 对标）
 *
 * 内置预设（is_builtin=1）启用中只读，仅可切换启用；
 * 已禁用的内置档可编辑/删除（清理用）；
 * 自定义预设（is_builtin=0）可编辑/删除；
 * web/API 默认档位存 settings 键。
 */
class CompressionController
{
    private CompressionProfileRepository $profiles;

    public function __construct()
    {
        $this->profiles = new CompressionProfileRepository();
    }

    /** 列表 + 默认档位设置 */
    public function index(): void
    {
        $all = Db::fetchAll('SELECT * FROM compression_profiles ORDER BY sort_order ASC, id ASC');
        $webDefault  = (int)(config('settings.web_compression_profile_id') ?? 0);
        $apiDefault  = (int)(config('settings.api_compression_profile_id') ?? 0);
        // Phase 9.3: 浏览器上传压缩模式（默认 browser=仅浏览器压缩）
        $browserMode = (string)(config('settings.browser_upload_mode') ?? 'browser');
        if (!in_array($browserMode, ['double', 'browser', 'backend'], true)) {
            $browserMode = 'browser';
        }

        Response::view('compression/index', [
            'profiles'   => $all,
            'webDefault' => $webDefault,
            'apiDefault' => $apiDefault,
            'browserMode' => $browserMode,
            'csrf'       => csrf_token(),
        ], 'main');
    }

    /** 新建自定义预设 */
    public function create(Request $request): void
    {
        if (!csrf_check($request->post('csrf_token', ''))) {
            flash('error', '会话已过期');
            Response::redirect(base_url('compression'));
        }

        $name = trim((string)$request->post('name', ''));
        if ($name === '' || mb_strlen($name, 'UTF-8') > 64) {
            flash('error', '预设名称不能为空且不超过 64 字符');
            Response::redirect(base_url('compression'));
        }

        $code = 'c' . substr(md5(uniqid((string)mt_rand(), true)), 0, 10);
        $data = $this->collect($request);
        $data['name'] = $name;
        $data['code'] = $code;
        $data['is_builtin'] = 0;
        $data['enabled'] = $request->post('enabled') === '1' ? 1 : 0;

        $this->profiles->create($data);
        flash('success', '预设「' . $name . '」已创建');
        Response::redirect(base_url('compression'));
    }

    /** 更新自定义预设 */
    public function update(Request $request): void
    {
        if (!csrf_check($request->post('csrf_token', ''))) {
            flash('error', '会话已过期');
            Response::redirect(base_url('compression'));
        }

        $id = (int)$request->post('id', 0);
        $row = $this->profiles->find($id);
        if (!$row) {
            flash('error', '预设不存在');
            Response::redirect(base_url('compression'));
        }
        // 已禁用的内置档允许编辑（清理用）
        if ($row['is_builtin'] && $row['enabled']) {
            flash('error', '启用的内置预设不可编辑（请先禁用）');
            Response::redirect(base_url('compression'));
        }

        $data = $this->collect($request);
        $name = trim((string)$request->post('name', ''));
        if ($name === '' || mb_strlen($name, 'UTF-8') > 64) {
            flash('error', '预设名称不能为空且不超过 64 字符');
            Response::redirect(base_url('compression'));
        }
        $data['name'] = $name;
        $this->profiles->update($id, $data);
        flash('success', '预设已更新');
        Response::redirect(base_url('compression'));
    }

    /** 启用/禁用（内置也可切换，resolve 有回退） */
    public function toggle(Request $request): void
    {
        if (!csrf_check($request->post('csrf_token', ''))) {
            flash('error', '会话已过期');
            Response::redirect(base_url('compression'));
        }

        $id = (int)$request->post('id', 0);
        $row = $this->profiles->find($id);
        if (!$row) {
            flash('error', '预设不存在');
            Response::redirect(base_url('compression'));
        }

        // 被设为默认时禁止禁用（与 delete 一致，避免"禁用默认"语义歧义）
        $webId = (int)(config('settings.web_compression_profile_id') ?? 0);
        $apiId = (int)(config('settings.api_compression_profile_id') ?? 0);
        if ($row['enabled'] && ($id === $webId || $id === $apiId)) {
            flash('error', '该预设正被设为默认，请先更换默认档位再禁用');
            Response::redirect(base_url('compression'));
        }

        Db::update('compression_profiles', [
            'enabled'    => $row['enabled'] ? 0 : 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $id]);

        flash('success', ($row['enabled'] ? '已禁用' : '已启用') . '「' . $row['name'] . '」');
        Response::redirect(base_url('compression'));
    }

    /** 删除自定义预设 */
    public function delete(Request $request): void
    {
        if (!csrf_check($request->post('csrf_token', ''))) {
            flash('error', '会话已过期');
            Response::redirect(base_url('compression'));
        }

        $id = (int)$request->post('id', 0);
        $row = $this->profiles->find($id);
        if (!$row) {
            flash('error', '预设不存在');
            Response::redirect(base_url('compression'));
        }
        // 已禁用的预设可以删除（反正没人用）
        // 启用的内置档不可删（用户怕误删）
        if ($row['is_builtin'] && $row['enabled']) {
            flash('error', '启用的内置预设不可删除（请先禁用）');
            Response::redirect(base_url('compression'));
        }

        // 被设为默认时禁止删除
        $webId = (int)(config('settings.web_compression_profile_id') ?? 0);
        $apiId = (int)(config('settings.api_compression_profile_id') ?? 0);
        if ($id === $webId || $id === $apiId) {
            flash('error', '该预设正被设为默认，请先更换默认档位');
            Response::redirect(base_url('compression'));
        }

        $deleted = $this->profiles->delete($id);
        if ($deleted > 0) {
            flash('success', '预设已删除');
        } else {
            // P2 修复：删除失败（被拦截或行不存在）→ 提示明确错误，不误导用户
            flash('error', '预设删除失败：可能被引用或权限不足');
        }
        Response::redirect(base_url('compression'));
    }

    /** 设置 web/API 默认档位 + 浏览器上传压缩模式 */
    public function defaults(Request $request): void
    {
        if (!csrf_check($request->post('csrf_token', ''))) {
            flash('error', '会话已过期');
            Response::redirect(base_url('compression'));
        }

        $webId = (int)$request->post('web_default', 0);
        $apiId = (int)$request->post('api_default', 0);
        // Phase 9.3: 浏览器上传压缩模式（double=双重 / browser=仅浏览器 / backend=仅后端）
        $browserMode = (string)$request->post('browser_mode', 'browser');
        if (!in_array($browserMode, ['double', 'browser', 'backend'], true)) {
            $browserMode = 'browser';
        }

        if ($webId) {
            $webRow = $this->profiles->find($webId);
            if (!$webRow) {
                flash('error', 'Web 默认档位不存在');
                Response::redirect(base_url('compression'));
            }
            if (!$webRow['enabled']) {
                flash('error', 'Web 默认档位已禁用，请选启用档');
                Response::redirect(base_url('compression'));
            }
        }
        if ($apiId) {
            $apiRow = $this->profiles->find($apiId);
            if (!$apiRow) {
                flash('error', 'API 默认档位不存在');
                Response::redirect(base_url('compression'));
            }
            if (!$apiRow['enabled']) {
                flash('error', 'API 默认档位已禁用，请选启用档');
                Response::redirect(base_url('compression'));
            }
        }

        $this->setSetting('web_compression_profile_id', $webId);
        $this->setSetting('api_compression_profile_id', $apiId);
        $this->setSetting('browser_upload_mode', $browserMode);
        flash('success', '默认档位已保存');
        Response::redirect(base_url('compression'));
    }

    /** 收集表单参数并收敛范围 */
    private function collect(Request $request): array
    {
        $format = (string)$request->post('output_format', 'auto');
        if (!in_array($format, ['auto', 'jpg', 'webp', 'png'], true)) $format = 'auto';
        return [
            'description'     => mb_substr(trim((string)$request->post('description', '')), 0, 255, 'UTF-8'),
            'max_dimension'   => max(100, min(10000, (int)$request->post('max_dimension', 1600))),
            'jpeg_quality'    => max(1, min(100, (int)$request->post('jpeg_quality', 70))),
            'webp_quality'    => max(1, min(100, (int)$request->post('webp_quality', 70))),
            'png_compression' => max(0, min(9, (int)$request->post('png_compression', 6))),
            'target_size_kb'  => max(0, min(10240, (int)$request->post('target_size_kb', 0))),
            'minimum_quality' => max(1, min(100, (int)$request->post('minimum_quality', 40))),
            'strip_metadata'  => $request->post('strip_metadata') === '1' ? 1 : 0,
            'sort_order'      => max(0, min(9999, (int)$request->post('sort_order', 0))),
            'output_format'   => $format,
        ];
    }

    private function setSetting(string $key, string|int $value): void
    {
        $exists = Db::fetchValue('SELECT COUNT(*) FROM settings WHERE `key` = ?', [$key]);
        if ($exists) {
            Db::update('settings', [
                'value'      => (string)$value,
                'updated_at' => date('Y-m-d H:i:s'),
            ], '`key` = :k', ['k' => $key]);
        } else {
            Db::insert('settings', [
                'key'        => $key,
                'value'      => (string)$value,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }
}
