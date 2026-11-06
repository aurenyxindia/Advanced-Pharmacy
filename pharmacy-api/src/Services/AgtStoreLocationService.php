<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use PDO;

/**
 * Fixed pharmacy coordinates for AuraGharTak (Advanced + Retail merged list).
 * Retail is never modified in Retail ERP — only rows here in ops DB.
 */
final class AgtStoreLocationService
{
    public function ensureSchema(): void
    {
        Connection::ops()->exec(
            'CREATE TABLE IF NOT EXISTS agt_store_locations (
                source ENUM(\'advanced\',\'retail\') NOT NULL,
                store_key VARCHAR(32) NOT NULL,
                latitude DECIMAL(10,7) NULL,
                longitude DECIMAL(10,7) NULL,
                geocode_source ENUM(\'manual\',\'geocoded\') NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (source, store_key),
                KEY idx_agt_store_loc_coords (latitude, longitude)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** Ensure every active Advanced branch has a row (coords filled later). */
    public function syncAdvancedBranchKeys(): void
    {
        $this->ensureSchema();
        $rows = Connection::ho()->query(
            "SELECT b.id
             FROM branches b
             JOIN clients c ON c.id = b.client_id
             WHERE b.status = 'active'
               AND c.subscription_status IN ('trial','active')"
        )->fetchAll(PDO::FETCH_ASSOC);
        $ins = Connection::ops()->prepare(
            'INSERT IGNORE INTO agt_store_locations
                (source, store_key, latitude, longitude, geocode_source, created_at, updated_at)
             VALUES (\'advanced\', :k, NULL, NULL, NULL, NOW(), NOW())'
        );
        foreach ($rows as $row) {
            $ins->execute(['k' => (string) (int) $row['id']]);
        }
    }

    /**
     * @param list<array{source: string, store_key: string}> $stores
     */
    public function syncRetailStubs(array $stores): int
    {
        $this->ensureSchema();
        $ins = Connection::ops()->prepare(
            'INSERT IGNORE INTO agt_store_locations
                (source, store_key, latitude, longitude, geocode_source, created_at, updated_at)
             VALUES (\'retail\', :k, NULL, NULL, NULL, NOW(), NOW())'
        );
        $added = 0;
        foreach ($stores as $store) {
            $source = strtolower(trim((string) ($store['source'] ?? '')));
            $key = trim((string) ($store['store_key'] ?? ''));
            if ($source !== 'retail' || $key === '' || strlen($key) > 32) {
                continue;
            }
            $ins->execute(['k' => $key]);
            if ($ins->rowCount() > 0) {
                $added++;
            }
        }
        return $added;
    }

    /** @return list<array<string, mixed>> */
    public function listForPublic(): array
    {
        $this->syncAdvancedBranchKeys();
        $stmt = Connection::ops()->query(
            'SELECT source, store_key, latitude, longitude, geocode_source
             FROM agt_store_locations
             WHERE latitude IS NOT NULL AND longitude IS NOT NULL
             ORDER BY source, store_key'
        );
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'source' => (string) $row['source'],
                'store_key' => (string) $row['store_key'],
                'latitude' => (float) $row['latitude'],
                'longitude' => (float) $row['longitude'],
                'geocode_source' => $row['geocode_source'] !== null ? (string) $row['geocode_source'] : null,
            ];
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    public function getAdvancedBranchLocation(int $branchId): ?array
    {
        $this->ensureSchema();
        $stmt = Connection::ops()->prepare(
            'SELECT latitude, longitude, geocode_source
             FROM agt_store_locations
             WHERE source = \'advanced\' AND store_key = :k LIMIT 1'
        );
        $stmt->execute(['k' => (string) $branchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'latitude' => $row['latitude'] !== null ? (float) $row['latitude'] : null,
            'longitude' => $row['longitude'] !== null ? (float) $row['longitude'] : null,
            'geocode_source' => $row['geocode_source'] !== null ? (string) $row['geocode_source'] : null,
        ];
    }

    public function saveAdvancedBranchLocation(int $branchId, float $latitude, float $longitude, string $geocodeSource = 'manual'): array
    {
        $this->ensureSchema();
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw new \InvalidArgumentException('Invalid coordinates.');
        }
        $geo = in_array($geocodeSource, ['manual', 'geocoded'], true) ? $geocodeSource : 'manual';
        $db = Connection::ops();
        $db->prepare(
            'INSERT INTO agt_store_locations
                (source, store_key, latitude, longitude, geocode_source, created_at, updated_at)
             VALUES (\'advanced\', :k, :lat, :lng, :geo, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                latitude = VALUES(latitude),
                longitude = VALUES(longitude),
                geocode_source = VALUES(geocode_source),
                updated_at = NOW()'
        )->execute([
            'k' => (string) $branchId,
            'lat' => $latitude,
            'lng' => $longitude,
            'geo' => $geo,
        ]);
        return [
            'source' => 'advanced',
            'store_key' => (string) $branchId,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'geocode_source' => $geo,
        ];
    }
}
