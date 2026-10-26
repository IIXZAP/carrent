<?php
namespace App\Models;

use App\Core\Database;

final class User
{
    public static function findByEmail(string $email): ?array
    {
        return Database::one(
            'SELECT * FROM users WHERE email = :email AND is_active = 1',
            ['email' => strtolower($email)]
        );
    }

    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM users WHERE id = :id AND is_active = 1', ['id' => $id]);
    }

    public static function create(string $name, string $email, string $passwordHash, string $role): int
    {
        return Database::insert(
            'INSERT INTO users (name, email, password_hash, role) VALUES (:name, :email, :hash, :role)',
            ['name' => $name, 'email' => strtolower($email), 'hash' => $passwordHash, 'role' => $role]
        );
    }

    public static function touchLogin(int $id): void
    {
        Database::run('UPDATE users SET last_login_at = NOW() WHERE id = :id', ['id' => $id]);
    }
}
