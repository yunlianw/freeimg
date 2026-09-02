<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Db;
use App\Services\AuthService;
use App\Services\PasswordService;
use App\Services\SessionService;
use App\Services\TotpService;
use App\Services\LoginSecurityService;
use App\Middleware\AuthMiddleware;

/**
 * 安全中心：账户、2FA、改密、会话、日志、策略
 */
class SecurityController
{
    /** 账户资料（用户名/邮箱/改密统一入口） */
    public function profile(): void
    {
        AuthMiddleware::handle();
        Response::view('security/profile', [
            'user' => AuthService::user(),
            'csrf' => csrf_token(),
        ], 'main');
    }

    /** 更新账户基本信息 */
    public function updateProfile(Request $request): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();
        if (!csrf_check($request->post('csrf_token', ''))) {
            flash('error', '会话已过期');
            Response::redirect(base_url('security/profile'));
        }

        $username = trim((string)$request->post('username', ''));
        $email    = trim((string)$request->post('email', ''));

        $errors = [];
        if ($username === '' || mb_strlen($username) < 3 || mb_strlen($username) > 32) {
            $errors[] = '用户名长度需 3-32 字符';
        }
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $username)) {
            $errors[] = '用户名只能包含字母、数字、下划线、短横线';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = '邮箱格式不正确';
        }
        // 检查唯一性
        $exists = Db::fetchOne(
            'SELECT id FROM users WHERE (username = :u OR email = :e) AND id != :id AND deleted_at IS NULL',
            ['u' => $username, 'e' => $email, 'id' => $user['id']]
        );
        if ($exists) {
            $errors[] = '用户名或邮箱已被其他账号使用';
        }
        if ($errors) {
            flash('error', implode('；', $errors));
            Response::redirect(base_url('security/profile'));
        }

        Db::update('users',
            [
                'username'   => $username,
                'email'      => $email,
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            'id = :id',
            ['id' => $user['id']]
        );
        // 同步 session（避免 profile 页仍显示旧邮箱）
        $_SESSION['user']['username'] = $username;
        $_SESSION['user']['email'] = $email;
        $_SESSION['username'] = $username;
        flash('success', '账户资料已更新');
        Response::redirect(base_url('security/profile'));
    }

    /** 2FA 设置页 */
    public function twofa(): void
    {
        AuthMiddleware::handle();
        $user = self::refreshUserFromDb();
        Response::view('security/twofa', [
            'user'  => $user,
            'csrf'  => csrf_token(),
            'qrUrl' => null,
            'secret' => null,
            'backupCodes' => null,
        ], 'main');
    }

    /** 生成 TOTP secret + 二维码 */
    public function setupTotp(Request $request): void
    {
        AuthMiddleware::handle();
        $user = self::refreshUserFromDb();
        if (!csrf_check($request->post('csrf_token', ''))) {
            flash('error', '会话已过期');
            Response::redirect(base_url('security/2fa'));
        }
        $secret = TotpService::generateSecret();
        $issuer = (string)config('settings.totp_issuer') ?: 'FreeImg';
        $uri = TotpService::getUri($secret, $user['username'], $issuer);
        $qrUrl = TotpService::getQrUrl($uri);
        $_SESSION['pending_totp_secret'] = $secret;
        Response::view('security/twofa', [
            'user'  => $user,
            'csrf'  => csrf_token(),
            'qrUrl' => $qrUrl,
            'secret' => $secret,
            'issuer' => $issuer,
            'backupCodes' => null,
        ], 'main');
    }

    /** 验证 TOTP 确认开启 */
    public function enableTotp(Request $request): void
    {
        AuthMiddleware::handle();
        $user = self::refreshUserFromDb();
        if (!csrf_check($request->post('csrf_token', ''))) {
            flash('error', '会话已过期');
            Response::redirect(base_url('security/2fa'));
        }
        $secret = $_SESSION['pending_totp_secret'] ?? '';
        if (!$secret) {
            flash('error', '请先扫描二维码');
            Response::redirect(base_url('security/2fa'));
        }
        $code = preg_replace('/\s+/', '', (string)$request->post('totp_code', ''));
        if (!TotpService::verify($secret, $code)) {
            flash('error', '验证码错误，请重试');
            Response::redirect(base_url('security/2fa'));
        }
        $backupCodes = TotpService::generateBackupCodes(10);
        Db::update('users', [
            'totp_secret'      => $secret,
            'totp_enabled'     => 1,
            'totp_backup_codes' => json_encode($backupCodes),
        ], 'id = :id', ['id' => $user['id']]);
        unset($_SESSION['pending_totp_secret']);
        // 同步 session（让页面立即看到 ✅ 而非 ❌）
        $user = self::refreshUserFromDb();
        Response::view('security/twofa', [
            'user'        => $user,
            'csrf'        => csrf_token(),
            'qrUrl'       => null,
            'secret'      => null,
            'backupCodes' => $backupCodes,
            'showBackup'  => true,
        ], 'main');
    }

    /** 解绑 2FA */
    public function disableTotp(Request $request): void
    {
        AuthMiddleware::handle();
        $user = AuthService::userWithPassword();
        if (!csrf_check($request->post('csrf_token', ''))) {
            flash('error', '会话已过期');
            Response::redirect(base_url('security/2fa'));
        }
        if (!$user) {
            flash('error', '用户不存在');
            Response::redirect(base_url('login'));
        }
        $password = (string)$request->post('password', '');
        $code = preg_replace('/\s+/', '', (string)$request->post('totp_code', ''));
        if (!PasswordService::verify($password, $user['password'])) {
            flash('error', '密码错误');
            Response::redirect(base_url('security/2fa'));
        }
        if (empty($user['totp_secret']) || !TotpService::verify($user['totp_secret'], $code)) {
            flash('error', '两步验证代码错误');
            Response::redirect(base_url('security/2fa'));
        }
        Db::update('users', [
            'totp_secret'      => null,
            'totp_enabled'     => 0,
            'totp_backup_codes' => null,
        ], 'id = :id', ['id' => $user['id']]);
        // 同步 session（让页面立即看到 ❌ 而非 ✅）
        self::refreshUserFromDb();
        flash('success', '两步验证已关闭');
        Response::redirect(base_url('security/2fa'));
    }

    /**
     * 从 DB 重新读用户（含最新 totp_enabled 等）并同步到 $_SESSION
     * 防止页面显示过期的 session 快照
     */
    private static function refreshUserFromDb(): array
    {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if (!$userId) {
            return AuthService::user() ?? [];
        }
        $repo = new \App\Repositories\UserRepository();
        $user = $repo->find($userId);
        if (!$user) {
            return AuthService::user() ?? [];
        }
        // 同步 session（去掉敏感字段）
        unset($user['password']);
        $_SESSION['user'] = $user;
        $_SESSION['username'] = $user['username'];
        return $user;
    }

    /** 改密码 */
    public function password(): void
    {
        AuthMiddleware::handle();
        Response::view('security/password', [
            'user' => AuthService::user(),
            'csrf' => csrf_token(),
            // v1.3.8: 提示文案和客户端校验跟安全策略设置联动
            'password_min_length' => (int)config('settings.password_min_length', 10),
            'password_history_count' => (int)config('settings.password_history_count', 5),
        ], 'main');
    }

    public function changePassword(Request $request): void
    {
        AuthMiddleware::handle();
        $user = AuthService::userWithPassword();
        if (!csrf_check($request->post('csrf_token', ''))) {
            flash('error', '会话已过期');
            Response::redirect(base_url('security/password'));
        }
        if (!$user) {
            flash('error', '用户不存在');
            Response::redirect(base_url('login'));
        }
        $old = (string)$request->post('old_password', '');
        $new = (string)$request->post('new_password', '');
        $confirm = (string)$request->post('confirm_password', '');
        if (!PasswordService::verify($old, $user['password'])) {
            flash('error', '当前密码错误');
            Response::redirect(base_url('security/password'));
        }
        if ($new !== $confirm) {
            flash('error', '两次输入的新密码不一致');
            Response::redirect(base_url('security/password'));
        }
        $err = PasswordService::validate($new);
        if ($err) {
            flash('error', $err);
            Response::redirect(base_url('security/password'));
        }
        if (PasswordService::isInHistory((int)$user['id'], $new)) {
            flash('error', '不能使用最近使用过的密码');
            Response::redirect(base_url('security/password'));
        }
        $currentToken = $_SESSION['session_token'] ?? '';
        PasswordService::setPassword((int)$user['id'], $new);
        if ($currentToken) {
            SessionService::destroyAllForUser((int)$user['id'], $currentToken);
        } else {
            SessionService::destroyAllForUser((int)$user['id']);
        }
        flash('success', '密码已修改，其他设备的登录已被强制下线');
        Response::redirect(base_url('security/password'));
    }

    /** 会话列表 */
    public function sessions(): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();
        $sessions = SessionService::listForUser((int)$user['id']);
        $currentToken = $_SESSION['session_token'] ?? '';
        Response::view('security/sessions', [
            'user'         => $user,
            'csrf'         => csrf_token(),
            'sessions'     => $sessions,
            'currentToken' => $currentToken,
        ], 'main');
    }

    public function destroySession(Request $request, array $params): void
    {
        AuthMiddleware::handle();
        if (!csrf_check($request->post('csrf_token', ''))) {
            flash('error', '会话已过期');
            Response::redirect(base_url('security/sessions'));
        }
        $user = AuthService::user();
        $token = (string)($params['token'] ?? '');
        if ($token === '') {
            Response::redirect(base_url('security/sessions'));
        }
        $row = SessionService::findValid($token);
        if (!$row || (int)$row['user_id'] !== (int)$user['id']) {
            flash('error', '会话不存在或无权操作');
            Response::redirect(base_url('security/sessions'));
        }
        SessionService::destroy($token);
        if ($token === ($_SESSION['session_token'] ?? '')) {
            AuthService::logout();
            flash('info', '当前会话已退出');
            Response::redirect(base_url('login'));
        }
        flash('success', '会话已下线');
        Response::redirect(base_url('security/sessions'));
    }

    /** 登录日志（仅管理员） */
    public function loginLogs(): void
    {
        AuthMiddleware::handle();
        $user = AuthService::user();
        if (($user['role'] ?? '') !== 'admin') {
            flash('error', '仅管理员可查看');
            Response::redirect(base_url('dashboard'));
        }
        $logs = LoginSecurityService::recentLogs(100);
        Response::view('security/login_logs', [
            'user' => $user,
            'csrf' => csrf_token(),
            'logs' => $logs,
        ], 'main');
    }

    /** 安全策略已迁出到 SecurityPolicyController（保持 < 400 行红线） */
}
