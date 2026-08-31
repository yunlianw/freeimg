<?php
namespace App\Repositories;

use App\Core\Db;

class ApiKeyRepository
{
    public function findById(int $id): ?array
    {
        return Db::fetchOne('SELECT * FROM api_keys WHERE id = :id', ['id' => $id]);
    }

    public function findByAccessKey(string $accessKey): ?array
    {
        return Db::fetchOne(
            'SELECT * FROM api_keys WHERE access_key = :ak AND status = 1 AND deleted_at IS NULL',
            ['ak' => $accessKey]
        );
    }

    public function listByUser(int $userId): array
    {
        return Db::fetchAll(
            'SELECT id, name, access_key, scopes, compression_profile_id, last_used_at, expires_at, status, created_at
             FROM api_keys WHERE user_id = :uid AND deleted_at IS NULL ORDER BY id DESC',
            ['uid' => $userId]
        );
    }

    public function generateAccessKey(): string
    {
        return 'fk_' . bin2hex(random_bytes(8));
    }

    public function generateSecretKey(): string
    {
        return 'sk_' . bin2hex(random_bytes(24));
    }

    public function create(int $userId, string $name, ?int $profileId = null, ?string $expiresAt = null, ?string $scopes = null): array
    {
        $accessKey = $this->generateAccessKey();
        $secretKey = $this->generateSecretKey();
        $secretHash = password_hash($secretKey, PASSWORD_BCRYPT);

        $id = Db::insert('api_keys', [
            'user_id'                 => $userId,
            'name'                    => $name,
            'access_key'              => $accessKey,
            'secret_key_hash'         => $secretHash,
            'scopes'                  => $scopes,
            'compression_profile_id'  => $profileId,
            'last_used_at'            => null,
            'expires_at'              => $expiresAt,
            'status'                  => 1,
            'created_at'              => date('Y-m-d H:i:s'),
            'updated_at'              => date('Y-m-d H:i:s'),
        ]);

        return [
            'id'          => $id,
            'access_key'  => $accessKey,
            'secret_key'  => $secretKey,
        ];
    }

    public function verifySecret(array $apiKey, string $secretKey): bool
    {
        return password_verify($secretKey, $apiKey['secret_key_hash']);
    }

    public function verify(string $accessKey, string $secretKey): ?array
    {
        $row = $this->findByAccessKey($accessKey);
        if (!$row) return null;
        if (!$this->verifySecret($row, $secretKey)) return null;
        if ($row['expires_at'] && strtotime($row['expires_at']) < time()) {
            return null;
        }
        Db::execute('UPDATE api_keys SET last_used_at = NOW() WHERE id = :id', ['id' => $row['id']]);
        return $row;
    }

    public function update(int $id, array $data): int
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        return Db::update('api_keys', $data, 'id = :id', ['id' => $id]);
    }

    public function revoke(int $id): int
    {
        return $this->update($id, ['status' => 0]);
    }

    public function activate(int $id): int
    {
        return $this->update($id, ['status' => 1]);
    }

    public function delete(int $id): int
    {
        return Db::delete('api_keys', 'id = :id', ['id' => $id]);
}

}
