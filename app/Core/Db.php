<?php
namespace App\Core;

use PDO;
use PDOException;

/**
 * 数据库连接（单例）
 */
class Db
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $cfg = config('database');
        if (empty($cfg)) {
            throw new \RuntimeException('Database config not found.');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'] ?? '127.0.0.1',
            $cfg['port'] ?? 3306,
            $cfg['dbname'] ?? '',
            $cfg['charset'] ?? 'utf8mb4'
        );

        try {
            self::$pdo = new PDO(
                $dsn,
                $cfg['username'] ?? 'root',
                $cfg['password'] ?? '',
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    Pdo\Mysql::ATTR_INIT_COMMAND => "SET NAMES " . ($cfg['charset'] ?? 'utf8mb4'),
                ]
            );
        } catch (PDOException $e) {
            throw new \RuntimeException('DB connect failed: ' . $e->getMessage());
        }

        return self::$pdo;
    }

    /**
     * 执行 SQL 并返回 PDOStatement
     */
    public static function execute(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * 查询多条
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::execute($sql, $params)->fetchAll();
    }

    /**
     * 查询单条
     */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $row = self::execute($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * 查询单值
     */
    public static function fetchValue(string $sql, array $params = [])
    {
        $stmt = self::execute($sql, $params);
        return $stmt->fetchColumn();
    }

    /**
     * INSERT
     */
    public static function insert(string $table, array $data): string
    {
        $cols = array_keys($data);
        $placeholders = array_map(fn($c) => ':' . $c, $cols);
        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $table,
            implode('`, `', $cols),
            implode(', ', $placeholders)
        );
        self::execute($sql, $data);
        return self::connection()->lastInsertId();
    }

    /**
     * UPDATE
     * 注意：所有占位符必须用命名占位符（`:name`），禁用 `?`。
     * 原因：PDO 原生预处理下，混用命名 + 位置占位符会触发 HY093。
     */
    public static function update(string $table, array $data, string $where, array $params = []): int
    {
        $sets = [];
        foreach ($data as $k => $v) {
            $sets[] = "`$k` = :set_$k";
            $params["set_$k"] = $v;
        }
        // 强制 WHERE 段里也只能用命名占位符（防呆）
        if (strpos($where, '?') !== false) {
            throw new \InvalidArgumentException(
                'Db::update WHERE clause must use named placeholders (e.g. id = :id), not ?'
            );
        }
        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(', ', $sets), $where);
        return self::execute($sql, $params)->rowCount();
    }

    /**
     * DELETE
     */
    public static function delete(string $table, string $where, array $params = []): int
    {
        $sql = sprintf('DELETE FROM `%s` WHERE %s', $table, $where);
        return self::execute($sql, $params)->rowCount();
    }

    /**
     * 启动事务
     */
    public static function beginTransaction(): void
    {
        self::connection()->beginTransaction();
    }

    public static function commit(): void
    {
        self::connection()->commit();
    }

    public static function rollBack(): void
    {
        if (self::connection()->inTransaction()) {
            self::connection()->rollBack();
        }
    }
}