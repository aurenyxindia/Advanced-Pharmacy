<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class Client extends Model
{
    protected string $connection = 'ho';
    protected string $table = 'clients';

    public function findByEmail(string $email): ?array
    {
        return $this->firstWhere(['email' => strtolower(trim($email))]);
    }

    public function findByDrugLicense(string $number): ?array
    {
        return $this->firstWhere(['drug_license_no' => $number]);
    }
}
