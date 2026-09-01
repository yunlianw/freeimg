<?php
namespace App\Controllers;

use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuthService;

class SettingController
{
    public function index(): void
    {
        $settings = $this->all();
        Response::view('settings/index', [
            'settings' => $settings,
            'csrf'     => csrf_token(),
        ], 'main');
    }

    public function save(Request $request): void
    {
        $token = $request->post('csrf_token', '');
        if (!csrf_check($token)) {
            flash('error', '会话已过期');
            Response::redirect(base_url('settings'));
        }

        // 判断提交的是哪个区块（不同按钮 → 不同 allowed 列表）
        $submitSection = (string)$request->post('submit_section', 'all');

        // 基础设置（站点名称、主域名、分享域名、API域名） — 独立保存
        $basicOnly = ['site_name', 'site_url', 'share_url', 'api_url'];

        $allowed = $submitSection === 'basic' ? $basicOnly : [
            'site_name',
            'site_url',                              // 主域名（后台可改）
            'share_url',                             // 分享域名（留空跟随主域名）
            'api_url',                               // API 域名（留空跟随主域名）
            'url_follow_host',                       // 多域名模式开关（checkbox）
            'allowed_hosts',                         // Host 白名单（textarea，每行一个域名）
            'upload_max_size', 'upload_allowed_types',
            'default_compression',
            'strip_exif',
            'url_path_prefix',
            // 重命名规则
            'rename_rule', 'rename_custom_format',
            // 目录规则
            'dir_rule', 'dir_custom_format',
            // 文字水印
            'watermark_enabled', 'watermark_text', 'watermark_font_size',
            'watermark_color', 'watermark_opacity', 'watermark_angle',
            'watermark_position', 'watermark_margin',
            // 图片水印
            'image_watermark_enabled', 'image_watermark_size',
            'image_watermark_opacity', 'image_watermark_position', 'image_watermark_margin',
        ];

        // 图片水印图上传（覆盖旧图；与删除同时勾选时以新图为准）
        if (!empty($_FILES['watermark_image']['tmp_name']) && is_uploaded_file($_FILES['watermark_image']['tmp_name'])) {
            // 用 getimagesize 同时拿 mime + 尺寸（不依赖 fileinfo 扩展，避免服务器差异）
            $info = @getimagesize($_FILES['watermark_image']['tmp_name']);
            $mime = $info['mime'] ?? '';
            if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
                flash('error', '水印图只支持 PNG / JPG / WebP');
                Response::redirect(base_url('settings'));
            }
            $size = (int)$_FILES['watermark_image']['size'];
            $longSide = $info ? max($info[0], $info[1]) : 0;
            if ($size > 5 * 1024 * 1024 || $longSide > 2000) {
                flash('error', '水印图过大：文件 ≤5MB 且长边 ≤2000px');
                Response::redirect(base_url('settings'));
            }
            $wmDir = FREEIMG_ROOT . '/public/storage/watermark';
            if (!is_dir($wmDir)) @mkdir($wmDir, 0755, true);
            if (!move_uploaded_file($_FILES['watermark_image']['tmp_name'], $wmDir . '/logo.png')) {
                flash('error', '水印图保存失败，请重试');
                Response::redirect(base_url('settings'));
            }
        } elseif ($request->post('watermark_image_remove') === '1') {
            @unlink(FREEIMG_ROOT . '/public/storage/watermark/logo.png');
            $this->set('image_watermark_enabled', '0');
        }

        foreach ($allowed as $key) {
            $value = trim((string)$request->post($key, ''));

            // checkbox 字段（不勾时 HTML 表单不提交，需要显式存 '0'）
            if (in_array($key, ['url_follow_host', 'strip_exif', 'watermark_enabled', 'image_watermark_enabled'], true)) {
                $value = $value === '1' ? '1' : '0';
            }

            // 站点 URL 校验：必须是合法 http(s) URL，host 字符白名单
            if ($key === 'site_url') {
                // 主域名必填，不允许空
                if ($value === '') {
                    flash('error', '主域名不能为空（安装时已自动写入，如要修改请填新域名）');
                    Response::redirect(base_url('settings'));
                }
                if (!preg_match('#^https?://#i', $value)) {
                    $value = 'https://' . $value;
                }
                $value = rtrim($value, '/');
                if (!preg_match('#^https?://[a-zA-Z0-9.\-_:]+(/.*)?$#', $value)) {
                    flash('error', '主域名格式不正确（例：https://img.example.com）');
                    Response::redirect(base_url('settings'));
                }
            }
            // 分享 URL / API URL：留空 = 跟随主域名
            if ($key === 'share_url' || $key === 'api_url') {
                if ($value !== '') {
                    if (!preg_match('#^https?://#i', $value)) {
                        $value = 'https://' . $value;
                    }
                    $value = rtrim($value, '/');
                    if (!preg_match('#^https?://[a-zA-Z0-9.\-_:]+(/.*)?$#', $value)) {
                        flash('error', ($key === 'share_url' ? '分享' : 'API') . '域名格式不正确（例：https://' . ($key === 'share_url' ? 'share' : 'api') . '.example.com，留空则跟随主域名）');
                        Response::redirect(base_url('settings'));
                    }
                }
            }
            // allowed_hosts：每行一个域名，必须合法
            if ($key === 'allowed_hosts') {
                $lines = preg_split('/[\r\n,]+/', $value);
                $clean = [];
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '') continue;
                    // 自动补 https://
                    if (!preg_match('#^https?://#i', $line)) {
                        $line = 'https://' . $line;
                    }
                    $line = rtrim($line, '/');
                    if (!preg_match('#^https?://[a-zA-Z0-9.\-_:]+$#', $line)) {
                        flash('error', 'Host 白名单格式不正确：每行一个域名，如 img.example.com 或 https://img.example.com');
                        Response::redirect(base_url('settings'));
                    }
                    $clean[] = $line;
                }
                // 存为换行分隔的字符串（request_origin 函数按行/逗号 split）
                $value = implode("\n", $clean);
            }

            // 路径前缀特殊校验：只允许字母/数字/斜杠/下划线/短横线
            if ($key === 'url_path_prefix') {
                $value = trim($value, '/');
                if (!preg_match('/^[a-zA-Z0-9\/_-]*$/', $value)) {
                    flash('error', 'URL 路径前缀格式不正确（只允许字母、数字、斜杠、下划线、短横线）');
                    Response::redirect(base_url('settings'));
                }
                if ($value === '') $value = 'rest/new';
            }

            // 重命名规则白名单 + 格式长度限制
            if ($key === 'rename_rule') {
                if (!in_array($value, ['short', 'timestamp', 'original', 'custom'], true)) $value = 'short';
            }
            if ($key === 'rename_custom_format') {
                $value = mb_substr($value, 0, 128, 'UTF-8');
            }
            // 目录规则白名单 + 格式长度限制
            if ($key === 'dir_rule') {
                if (!in_array($value, ['none', 'year', 'month', 'day', 'ymd', 'custom'], true)) $value = 'none';
            }
            if ($key === 'dir_custom_format') {
                $value = mb_substr($value, 0, 128, 'UTF-8');
            }

            // 水印数字范围收敛
            if ($key === 'watermark_font_size') $value = (string)max(8, min(200, (int)$value ?: 28));
            if ($key === 'watermark_opacity')  $value = (string)max(1, min(100, (int)$value ?: 50));
            if ($key === 'watermark_angle')    $value = (string)max(-180, min(180, (int)$value ?: 0));
            if ($key === 'watermark_margin')   $value = (string)max(0, min(200, (int)$value ?: 20));
            if ($key === 'watermark_position') {
                if (!in_array($value, ['tl','tc','tr','ml','mc','mr','bl','bc','br'], true)) $value = 'br';
            }
            if ($key === 'watermark_text') {
                $value = mb_substr($value, 0, 200, 'UTF-8'); // 限制 200 字符，防超长文本吃 CPU
            }
            if ($key === 'watermark_color') {
                if (!preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $value)) $value = '#ffffff';
            }
            // 图片水印数字范围
            if ($key === 'image_watermark_size')   $value = (string)max(5, min(100, (int)$value ?: 20));
            if ($key === 'image_watermark_opacity') $value = (string)max(1, min(100, (int)$value ?: 80));
            if ($key === 'image_watermark_margin')  $value = (string)max(0, min(200, (int)$value ?: 15));
            if ($key === 'image_watermark_position') {
                if (!in_array($value, ['tl','tc','tr','ml','mc','mr','bl','bc','br'], true)) $value = 'br';
            }
            // 安全设置已迁出到 SecurityPolicyController

            $this->set($key, $value);
        }

        flash('success', '设置已保存');
        Response::redirect(base_url('settings'));
    }

    private function all(): array
    {
        $rows = Db::fetchAll('SELECT `key`, `value` FROM settings');
        $map = [];
        foreach ($rows as $r) {
            $map[$r['key']] = $r['value'];
        }
        return $map;
    }

    private function set(string $key, string $value): void
    {
        $exists = Db::fetchValue('SELECT COUNT(*) FROM settings WHERE `key` = ?', [$key]);
        if ($exists) {
            Db::update('settings', [
                'value'      => $value,
                'updated_at' => date('Y-m-d H:i:s'),
            ], '`key` = :k', ['k' => $key]);
        } else {
            Db::insert('settings', [
                'key'        => $key,
                'value'      => $value,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        // 路径前缀变化时 → 同步软链接
        if ($key === 'url_path_prefix' && $value !== '') {
            $this->syncPrefixSymlink($value);
        }
    }

    /**
     * 同步 URL 路径前缀对应的真实目录
     *
     * 方案 A（默认）：只影响新上传，不动旧图
     *  - 旧 URL 继续可访问（图片还在 public/old_prefix/ 下）
     *  - 新图上传到 public/new_prefix/
     *  - 管理员需要手动迁移旧图（用 mv 命令）
     *
     * 不删旧目录，不批量更新数据库（避免破坏老链接）
     */
    private function syncPrefixSymlink(string $prefix): void
    {
        // 校验：只允许字母/数字/斜杠/下划线/短横线（支持多级前缀如 rest/new）
        $prefix = preg_replace('/[^a-zA-Z0-9\/_-]/', '', $prefix);
        if ($prefix === '') return;

        $publicDir = FREEIMG_ROOT . '/public';
        $target = $publicDir . '/' . $prefix;

        // 仅确保新 prefix 目录存在（不动旧目录）
        if (!is_dir($target)) {
            @mkdir($target, 0755, true);
        }

        // ⚠️ 管理员明确要求：不自动迁移、不删旧目录、不批量更新 DB
        // 改 prefix 后老图片仍在 public/{old_prefix}/，URL 还能用
        // 备份策略：管理员把整个 public/ 目录备份即可整体迁移
    }
}