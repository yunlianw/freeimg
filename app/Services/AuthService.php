<?php
namespace App\Services;

use App\Repositories\UserRepository;

/**
 * 认证服务
 */
class AuthService
{
    public function __construct(private UserRepository $users) {}

    /**
     * 登录
     */
    public function login(string $usernameOrEmail, string $password): ?array
    {
        $user = filter_var($usernameOrEmail, FILTER_VALIDATE_EMAIL)
            ? $this->users->findByEmail($usernameOrEmail)
            : $this->users->findByUsername($usernameOrEmail);

        if (!$user || (int)$user['status'] !== 1) {
            return null;
        }

        if (!password_verify($password, $user['password'])) {
            return null;
        }

        $this->users->touchLogin((int)$user['id']);
        return $user;
    }

    /**
     * 创建用户
     */
    public function register(string $username, string $email, string $password, string $role = 'user'): array
    {
        $errors = [];

        if (strlen($username) < 3 || strlen($username) > 32) {
            $errors[] = '用户名长度必须在 3-32 字符之间';
        }
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $username)) {
            $errors[] = '用户名只能包含字母、数字、下划线和短横线';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = '邮箱格式不正确';
        }
        if (strlen($password) < 8) {
            $errors[] = '密码长度至少 8 位';
        }
        if (!in_array($role, ['admin', 'user'], true)) {
            $errors[] = '角色无效';
        }
        if ($this->users->findByUsername($username)) {
            $errors[] = '用户名已存在';
        }
        if ($this->users->findByEmail($email)) {
            $errors[] = '邮箱已存在';
        }

        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $id = $this->users->create([
            'username'   => $username,
            'email'      => $email,
            'password'   => password_hash($password, PASSWORD_BCRYPT),
            'role'       => $role,
            'status'     => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return ['ok' => true, 'id' => $id];
    }

    /**
     * 修改密码
     */
    public function changePassword(int $userId, string $oldPassword, string $newPassword): bool
    {
        $user = $this->users->find($userId);
        if (!$user || !password_verify($oldPassword, $user['password'])) {
            return false;
        }
        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        $this->users->updatePassword($userId, $hash);
        return true;
    }

    /**
     * 当前登录用户（不含 password）
     * 兼容两种 session key：旧 $_SESSION['user']、新 $_SESSION['user_id']
     */
    public static function user(): ?array
    {
        if (!empty($_SESSION['user'])) return $_SESSION['user'];
        if (!empty($_SESSION['user_id'])) {
            $repo = new UserRepository();
            $user = $repo->find((int)$_SESSION['user_id']);
            if ($user) {
                unset($user['password']);
                $_SESSION['user'] = $user;
                return $user;
            }
        }
        return null;
    }

    /**
     * 当前登录用户（带 password hash，仅用于改密/解绑 2FA 等需要校验旧密码的场景）
     * 不缓存到 $_SESSION，避免泄露到其他上下文
     */
    public static function userWithPassword(): ?array
    {
        if (empty($_SESSION['user_id'])) return null;
        $repo = new UserRepository();
        $user = $repo->find((int)$_SESSION['user_id']);
        return $user ?: null;
    }

    /**
     * 是否已登录
     */
    public static function check(): bool
    {
        return !empty($_SESSION['user']) || !empty($_SESSION['user_id']);
    }

    /**
     * 是否管理员
     */
    public static function admin(): bool
    {
        $u = self::user();
        return $u && ($u['role'] ?? '') === 'admin';
    }

    /**
     * 写入 session
     */
    public static function setUser(array $user): void
    {
        unset($user['password']);
        $_SESSION['user'] = $user;
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'] ?? 'user';
        session_regenerate_id(true);
    }

    /**
     * 退出
     */
    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
    }
}