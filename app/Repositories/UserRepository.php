<?php
namespace App\Repositories;

use App\Core\Db;

/**
 * 用户数据访问
 */
class UserRepository
{
    /**
     * 按 ID 查询
     */
    public function find(int $id): ?array
    {
        return Db::fetchOne('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /**
     * 按用户名查询
     */
    public function findByUsername(string $username): ?array
    {
        return Db::fetchOne('SELECT * FROM users WHERE username = ?', [$username]);
    }

    /**
     * 按邮箱查询
     */
    public function findByEmail(string $email): ?array
    {
        return Db::fetchOne('SELECT * FROM users WHERE email = ?', [$email]);
    }

    /**
     * 创建用户
     */
    public function create(array $data): int
    {
        return Db::insert('users', $data);
    }

    /**
     * 更新最后登录
     */
    public function touchLogin(int $id): void
    {
        Db::update('users', ['last_login_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
    }

    /**
     * 更新密码
     */
    public function updatePassword(int $id, string $hash): void
    {
        Db::update('users', ['password' => $hash, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
    }

    /**
     * 用户总数
     */
    public function count(): int
    {
        return (int)Db::fetchValue('SELECT COUNT(*) FROM users');
    }
}