<?php

declare(strict_types=1);

namespace App\Database;

use App\Core\Config;
use PDO;
use RuntimeException;

/**
 * Multi-database connection manager.
 *
 * Exposes three logical connections:
 *   - "master" => pharma_master (global product/generic catalog)
 *   - "ho"     => pharma_ho     (clients, branches, users, dashboard)
 *   - "ops"    => pharma_ops    (purchase, sales, inventory, accounts)
 *
 * Connections are lazily created and cached per request.
 */
final class Connection
{
    /** @var array<string, PDO> */
    private static array $pool = [];

    public static function get(?string $name = null): PDO
    {
        $name ??= (string) Config::get('database.default', 'ho');

        if (isset(self::$pool[$name])) {
            return self::$pool[$name];
        }

        $config = Config::get("database.connections.{$name}");
        if (!is_array($config)) {
            throw new RuntimeException("Database connection [{$name}] is not configured.");
        }

        $pdo = new PDO(
            $config['dsn'],
            $config['username'],
            $config['password'],
            $config['options'] ?? []
        );

        return self::$pool[$name] = $pdo;
    }

    public static function master(): PDO
    {
        return self::get('master');
    }

    public static function ho(): PDO
    {
        return self::get('ho');
    }

    public static function ops(): PDO
    {
        return self::get('ops');
    }

    /** Run a callback within a transaction on the given connection. */
    public static function transaction(string $name, callable $callback): mixed
    {
        $pdo = self::get($name);
        $pdo->beginTransaction();
        try {
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
