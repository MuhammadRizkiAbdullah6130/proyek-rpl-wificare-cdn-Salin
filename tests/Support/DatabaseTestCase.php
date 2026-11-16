<?php

declare(strict_types=1);

namespace Tests\Support;

use Auth;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Dasar test integrasi database.
 *
 * Setiap test berjalan dalam satu transaksi dan selalu di-rollback. Tidak ada
 * perubahan permanen pada database uji, apalagi database aktif.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = \Database::pdo();
        $this->pdo->beginTransaction();

        $_SESSION = [];
        $_SERVER['REMOTE_ADDR'] = '127.0.0.' . random_int(1, 254);

        $this->resetAuthCache();
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }

        $_SESSION = [];
        $this->resetAuthCache();

        parent::tearDown();
    }

    /**
     * @param array{username?: string, password?: string, role?: string, active?: bool, nama?: string} $data
     * @return array{id: int, username: string, password: string, role: string}
     */
    protected function createUser(array $data = []): array
    {
        $username = $data['username'] ?? ('uji.' . bin2hex(random_bytes(6)));
        $password = $data['password'] ?? 'Test-Pass-123!';
        $role = $data['role'] ?? 'pelanggan';
        $active = $data['active'] ?? true;
        $nama = $data['nama'] ?? ('Pengguna Uji ' . $username);

        $statement = $this->pdo->prepare(
            'INSERT INTO users (username, password_hash, nama, role, is_active, session_version)
             VALUES (:username, :password_hash, :nama, :role, :is_active, 0)'
        );
        $statement->execute([
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'nama' => $nama,
            'role' => $role,
            'is_active' => $active ? 1 : 0,
        ]);

        return [
            'id' => (int) $this->pdo->lastInsertId(),
            'username' => $username,
            'password' => $password,
            'role' => $role,
        ];
    }

    /**
     * @return array<string, string>|null
     */
    protected function validationErrors(callable $callback): ?array
    {
        try {
            $callback();
        } catch (\ValidationException $exception) {
            return $exception->errors();
        }

        $this->fail('ValidationException tidak dilempar.');
    }

    private function resetAuthCache(): void
    {
        $reflection = new ReflectionClass(Auth::class);

        $loaded = $reflection->getProperty('loaded');
        $loaded->setAccessible(true);
        $loaded->setValue(null, false);

        $user = $reflection->getProperty('user');
        $user->setAccessible(true);
        $user->setValue(null, null);
    }
}
