<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use PDO;

/**
 * Client-level "Hide Master Catalogue" policy (My Profile).
 * When ON, list/search APIs return only that client's own products / distributors.
 *
 * Intentionally uncached across requests — PHP-FPM workers would otherwise keep
 * stale flags after an admin saves My Profile.
 */
final class ClientCatalogPolicy
{
    public function hideProducts(int $clientId): bool
    {
        return $this->flags($clientId)['products'];
    }

    public function hideDistributors(int $clientId): bool
    {
        return $this->flags($clientId)['distributors'];
    }

    /** @return array{products: bool, distributors: bool} */
    public function flags(int $clientId): array
    {
        if ($clientId <= 0) {
            return ['products' => false, 'distributors' => false];
        }

        (new ClientProfileService())->ensureProfileColumns();

        $stmt = Connection::ho()->prepare(
            'SELECT hide_master_products, hide_master_distributors FROM clients WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $clientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'products'     => !empty($row['hide_master_products']),
            'distributors' => !empty($row['hide_master_distributors']),
        ];
    }

    /** @deprecated No longer caches; kept for callers after profile update. */
    public static function forget(int $clientId): void
    {
        // no-op — flags are read fresh each request
    }
}
