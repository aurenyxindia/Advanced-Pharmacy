<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class User extends Model
{
    protected string $connection = 'ho';
    protected string $table = 'users';

    public function findByEmail(string $email): ?array
    {
        return $this->firstWhere(['email' => strtolower(trim($email))]);
    }

    public function findActiveById(int $id): ?array
    {
        return $this->firstWhere(['id' => $id, 'status' => 'active']);
    }

    public function touchLogin(int $id): void
    {
        $this->update($id, ['last_login_at' => now()]);
    }
}
