<?php

declare(strict_types=1);

namespace App\Core;

use App\Database\Connection;
use PDO;

/**
 * Lightweight base model wrapping PDO with prepared statements.
 *
 * Subclasses set:
 *   $connection -> 'master' | 'ho' | 'ops'
 *   $table      -> table name
 *   $primaryKey -> default 'id'
 */
abstract class Model
{
    protected string $connection = 'ho';
    protected string $table = '';
    protected string $primaryKey = 'id';

    protected function db(): PDO
    {
        return Connection::get($this->connection);
    }

    public function find(int|string $id): ?array
    {
        return $this->firstWhere([$this->primaryKey => $id]);
    }

    public function firstWhere(array $conditions): ?array
    {
        [$where, $bindings] = $this->buildWhere($conditions);
        $sql = "SELECT * FROM `{$this->table}` {$where} LIMIT 1";
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($bindings);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function where(array $conditions, ?string $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        [$where, $bindings] = $this->buildWhere($conditions);
        $sql = "SELECT * FROM `{$this->table}` {$where}";
        if ($orderBy !== null) {
            $sql .= " ORDER BY {$orderBy}";
        }
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int) $limit;
            if ($offset !== null) {
                $sql .= ' OFFSET ' . (int) $offset;
            }
        }
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($bindings);
        return $stmt->fetchAll();
    }

    public function all(?string $orderBy = null): array
    {
        $sql = "SELECT * FROM `{$this->table}`";
        if ($orderBy !== null) {
            $sql .= " ORDER BY {$orderBy}";
        }
        return $this->db()->query($sql)->fetchAll();
    }

    public function insert(array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn ($c) => ":{$c}", $columns);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $this->table,
            implode(', ', array_map(static fn ($c) => "`{$c}`", $columns)),
            implode(', ', $placeholders)
        );
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($data);
        return (int) $this->db()->lastInsertId();
    }

    public function update(int|string $id, array $data): bool
    {
        $set = implode(', ', array_map(static fn ($c) => "`{$c}` = :{$c}", array_keys($data)));
        $sql = "UPDATE `{$this->table}` SET {$set} WHERE `{$this->primaryKey}` = :__pk";
        $stmt = $this->db()->prepare($sql);
        $data['__pk'] = $id;
        return $stmt->execute($data);
    }

    public function delete(int|string $id): bool
    {
        $sql = "DELETE FROM `{$this->table}` WHERE `{$this->primaryKey}` = :id";
        $stmt = $this->db()->prepare($sql);
        return $stmt->execute(['id' => $id]);
    }

    public function table(): string
    {
        return $this->table;
    }

    public function connectionName(): string
    {
        return $this->connection;
    }

    /**
     * Paginated + searchable listing.
     *
     * @param array<string,mixed> $conditions Exact-match scope filters (e.g. tenant keys)
     * @param string[]            $searchColumns Columns to LIKE-match against $search
     * @return array{data: array, total: int, page: int, per_page: int}
     */
    public function paginate(
        array $conditions = [],
        ?string $search = null,
        array $searchColumns = [],
        int $page = 1,
        int $perPage = 25,
        string $orderBy = 'id DESC'
    ): array {
        $page    = max(1, $page);
        $perPage = max(1, min(5000, $perPage));
        $offset  = ($page - 1) * $perPage;

        $clauses  = [];
        $bindings = [];
        foreach ($conditions as $column => $value) {
            $param = 'c_' . str_replace('.', '_', $column);
            $clauses[] = "`{$column}` = :{$param}";
            $bindings[$param] = $value;
        }

        if ($search !== null && $search !== '' && $searchColumns !== []) {
            $likes = [];
            foreach ($searchColumns as $i => $column) {
                $param = "s_{$i}";
                $likes[] = "`{$column}` LIKE :{$param}";
                $bindings[$param] = '%' . $search . '%';
            }
            $clauses[] = '(' . implode(' OR ', $likes) . ')';
        }

        $where = $clauses !== [] ? 'WHERE ' . implode(' AND ', $clauses) : '';

        $countStmt = $this->db()->prepare("SELECT COUNT(*) FROM `{$this->table}` {$where}");
        $countStmt->execute($bindings);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT * FROM `{$this->table}` {$where} ORDER BY {$orderBy} LIMIT {$perPage} OFFSET {$offset}";
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($bindings);

        return [
            'data'     => $stmt->fetchAll(),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    public function rawOne(string $sql, array $bindings = []): ?array
    {
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($bindings);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function rawAll(string $sql, array $bindings = []): array
    {
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($bindings);
        return $stmt->fetchAll();
    }

    /** @return array{0:string,1:array} */
    private function buildWhere(array $conditions): array
    {
        if ($conditions === []) {
            return ['', []];
        }
        $clauses = [];
        $bindings = [];
        foreach ($conditions as $column => $value) {
            $param = 'w_' . str_replace('.', '_', $column);
            $clauses[] = "`{$column}` = :{$param}";
            $bindings[$param] = $value;
        }
        return ['WHERE ' . implode(' AND ', $clauses), $bindings];
    }
}
