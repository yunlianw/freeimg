<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\AuthService;
use App\Services\LoginSecurityService;
use App\Services\PasswordService;
use App\Services\SessionService;
use App\Services\TotpService;
use App\Repositories\UserRepository;

class AuthController
{
    public function showLogin(): void
    {
        if (AuthService::check()) {
            Response::redirect(base_url('dashboard'));
        }
        Response::view('auth/login', ['csrf' => csrf_token()], 'blank');
    }

    /**
     * 第一步：用户名 + 密码
     * - 校验失败 → flash 通用错误 + 记录失败日志
     * - 用户被锁 → flash "账户暂时锁定，X 分钟后再试"
     * - 校验成功 + 启用了 2FA → 把 user_id 存 session 'pending_2fa_uid'，跳到 2FA 页
     * - 校验成功 + 未启用 2FA → 直接登录
     */
    public function login(Request $request): void
    {
        $token = $request->post('csrf_token', '');
        if (!csrf_check($token)) {
            flash('error', '会话已过期，请重新提交');
            Response::redirect(base_url('login'));
        }

        $username = trim((string)$request->post('username', ''));
        $password = (string)$request->post('password', '');

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

        // 用户存在性检查（不告诉用户用户名是否存在 — 防探测）
        $userRow = LoginSecurityService::findUser($username);
        if (!$userRow) {
            LoginSecurityService::log(null, $username, $ip, $ua, 'fail', '用户不存在');
            flash('error', '用户名或密码错误');
            Response::redirect(base_url('login'));
        }

        $userId = (int)$userRow['id'];

        // 检查是否锁定
        if (LoginSecurityService::isLocked($userId)) {
            $row = \App\Core\Db::fetchOne('SELECT locked_until FROM users WHERE id = :id', ['id' => $userId]);
            $minutes = max(1, (int)ceil((strtotime($row['locked_until']) - time()) / 60));
            LoginSecurityService::log($userId, $username, $ip, $ua, 'locked', '账户锁定中');
            flash('error', '登录失败次数过多，请 ' . $minutes . ' 分钟后再试');
            Response::redirect(base_url('login'));
        }

        // 密码验证
        if (!PasswordService::verify($password, $userRow['password'])) {
            $locked = LoginSecurityService::recordFailure($userId);
            LoginSecurityService::log($userId, $username, $ip, $ua, 'fail', $locked ? '密码错误+锁定' : '密码错误');
            flash('error', $locked ? '密码错误次数过多，账户已锁定' : '用户名或密码错误');
            Response::redirect(base_url('login'));
        }

        // 密码正确：记成功（清零失败计数 + 更新 last_login）但 NOT 进入 dashboard
        // 注意：recordSuccess 必须移到 completeLogin 之后，避免 2FA 失败时清零失败计数
        // 拆分：先清零失败计数（不更新 last_login），等全部认证通过后再更新
        LoginSecurityService::resetFailedCount($userId);

        if ((int)($userRow['totp_enabled'] ?? 0) === 1) {
            // 进入 2FA 验证
            $_SESSION['pending_2fa_uid'] = $userId;
            $_SESSION['pending_2fa_time'] = time();
            flash('info', '请输入两步验证代码');
            Response::redirect(base_url('login/2fa'));
        }

        // 无 2FA，直接登录
        LoginSecurityService::log($userId, $username, $ip, $ua, 'success', '登录（无 2FA）');
        $this->completeLogin($userRow, $ip, $ua);
    }

    /**
     * 第二步：2FA 验证
     */
    public function show2fa(): void
    {
        if (!isset($_SESSION['pending_2fa_uid'])) {
            Response::redirect(base_url('login'));
        }
        // 10 分钟过期
        if (time() - ($_SESSION['pending_2fa_time'] ?? 0) > 600) {
            unset($_SESSION['pending_2fa_uid'], $_SESSION['pending_2fa_time']);
            flash('error', '两步验证已过期，请重新登录');
            Response::redirect(base_url('login'));
        }
        Response::view('auth/login_2fa', ['csrf' => csrf_token()], 'blank');
    }

    public function verify2fa(Request $request): void
    {
        $token = $request->post('csrf_token', '');
        if (!csrf_check($token)) {
            flash('error', '会话已过期');
            Response::redirect(base_url('login'));
        }
        if (!isset($_SESSION['pending_2fa_uid'])) {
            Response::redirect(base_url('login'));
        }

        $userId = (int)$_SESSION['pending_2fa_uid'];
        $user = LoginSecurityService::findUserById($userId);
        if (!$user) {
            unset($_SESSION['pending_2fa_uid']);
            Response::redirect(base_url('login'));
        }

        $code = preg_replace('/\s+/', '', (string)$request->post('totp_code', ''));
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

        // 优先 TOTP，其次备份码
        $ok = false;
        if (preg_match('/^\d{6}$/', $code) && !empty($user['totp_secret'])) {
            $ok = TotpService::verify($user['totp_secret'], $code);
        }

        // 备份码（每个只能用一次，存 JSON 列表）
        if (!$ok && !empty($user['totp_backup_codes'])) {
            $backupCodes = json_decode($user['totp_backup_codes'], true) ?: [];
            // 归一化：去连字符 + 转大写
            $inputBackup = strtoupper(str_replace('-', '', $code));
            foreach ($backupCodes as $idx => $storedCode) {
                $storedNorm = strtoupper(str_replace('-', '', $storedCode));
                if (hash_equals($storedNorm, $inputBackup)) {
                    // 使用后移除
                    unset($backupCodes[$idx]);
                    \App\Core\Db::update('users',
                        ['totp_backup_codes' => json_encode(array_values($backupCodes))],
                        'id = :id',
                        ['id' => $userId]
                    );
                    $ok = true;
                    break;
                }
            }
        }

        if (!$ok) {
            // 2FA 失败也要计数 + 可能触发锁定（防爆破）
            $locked = LoginSecurityService::recordFailure($userId);
            LoginSecurityService::log($userId, $user['username'], $ip, $ua, 'fail', $locked ? '2FA 错误+锁定' : '2FA 错误');
            if ($locked) {
                // 锁定后清掉 pending session，要求重新走完整登录
                unset($_SESSION['pending_2fa_uid'], $_SESSION['pending_2fa_time']);
                flash('error', '两步验证失败次数过多，账户已锁定，请稍后再试');
                Response::redirect(base_url('login'));
            }
            flash('error', '两步验证代码错误');
            Response::redirect(base_url('login/2fa'));
        }

        // 2FA 通过 → 完成登录（此时才记录成功 + 清零计数）
        LoginSecurityService::recordSuccess($userId, $ip);
        LoginSecurityService::log($userId, $user['username'], $ip, $ua, 'success', '登录（含 2FA）');
        unset($_SESSION['pending_2fa_uid'], $_SESSION['pending_2fa_time']);
        $this->completeLogin($user, $ip, $ua);
    }

    /**
     * 完成登录：创建 DB 会话 + 写 PHP session
     */
    private function completeLogin(array $user, string $ip, ?string $ua): void
    {
        // 清掉该用户的所有旧 DB session，避免幽灵 session（用户多端/重复登录后，
        // 早期 PHPSESSID 仍指向 user_sessions 里旧记录，但 $_SESSION['user_id'] 已更新——
        // 旧 cookie 触发 AuthMiddleware::findValid() 会拿到别人或过期 token 导致 401）
        SessionService::destroyAllForUser((int)$user['id']);
        $sessionToken = SessionService::create((int)$user['id'], $ip, $ua);
        // 关键：session_regenerate_id 防止 session fixation 攻击 + 让浏览器拿到新 PHPSESSID cookie
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['session_token'] = $sessionToken;
        $_SESSION['role'] = $user['role'] ?? 'user';
        $_SESSION['username'] = $user['username'];
        $_SESSION['user'] = $user; // 兼容老代码
        unset($_SESSION['user']['password']);
        // 更新 last_login_at / last_login_ip（2FA 路径在 verify2fa 里已调过一次，这里重复无害）
        LoginSecurityService::recordSuccess((int)$user['id'], $ip);
        flash('success', '欢迎回来，' . $user['username']);
        Response::redirect(base_url('dashboard'));
    }

    public function logout(Request $request): void
    {
        if (!$request->isPost() || !csrf_check($request->post('csrf_token', ''))) {
            flash('error', '退出失败：非法请求');
            Response::redirect(base_url('dashboard'));
        }
        // 销毁 DB 会话
        if (!empty($_SESSION['session_token'])) {
            SessionService::destroy($_SESSION['session_token']);
        }
        AuthService::logout();
        Response::redirect(base_url('login'));
    }
}