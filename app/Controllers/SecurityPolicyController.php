<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Db;
use App\Services\AuthService;
use App\Middleware\AuthMiddleware;

/**
 * 安全策略配置（仅管理员）
 * - 会话超时、登录失败锁定、密码强度、2FA 颁发者
 */
class SecurityPolicyController
{
    public function policy(): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();
        if (($user['role'] ?? '') !== 'admin') {
            flash('error', '仅管理员可配置安全策略');
            Response::redirect(base_url('dashboard'));
        }
        $rows = Db::fetchAll('SELECT `key`, value FROM settings');
        $settings = [];
        foreach ($rows as $r) $settings[$r['key']] = $r['value'];
        Response::view('security/policy', [
            'user' => $user,
            'csrf' => csrf_token(),
            'settings' => $settings,
        ], 'main');
    }

    public function savePolicy(Request $request): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();
        if (($user['role'] ?? '') !== 'admin') {
            flash('error', '仅管理员可配置');
            Response::redirect(base_url('dashboard'));
        }
        if (!csrf_check($request->post('csrf_token', ''))) {
            flash('error', '会话已过期');
            Response::redirect(base_url('security/policy'));
        }

        $map = [
            'session_ttl_hours'      => [1, 8760, 24],
            'login_max_failed'       => [1, 100, 5],
            'login_lock_minutes'     => [1, 1440, 15],
            'password_min_length'    => [8, 64, 10],
            'password_history_count' => [0, 20, 5],
            'totp_issuer'            => 'string32',
        ];
        foreach ($map as $key => $rule) {
            $val = trim((string)$request->post($key, ''));
            if ($rule === 'string32') {
                $val = mb_substr($val, 0, 32, 'UTF-8');
                if ($val === '') $val = 'FreeImg';
            } else {
                [$min, $max, $default] = $rule;
                // 注意：不能用 $n ?: $default，否则 0 会被替换为默认值
                $n = (int)$val;
                if ($n < $min || $n > $max) $n = $default;
                $val = (string)$n;
            }
            self::setSetting($key, $val);
        }
        flash('success', '安全策略已保存');
        Response::redirect(base_url('security/policy'));
    }

    private static function setSetting(string $key, string $value): void
    {
        $exists = (int)Db::fetchValue('SELECT COUNT(*) FROM settings WHERE `key` = ?', [$key]);
        if ($exists) {
            Db::update('settings', ['value' => $value, 'updated_at' => date('Y-m-d H:i:s')], '`key` = :k', ['k' => $key]);
        } else {
            Db::insert('settings', ['key' => $key, 'value' => $value, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        }
    }
}