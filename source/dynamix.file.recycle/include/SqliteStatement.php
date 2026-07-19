<?php
/** Prepared-statement facade for the sqlite3 CLI adapter. */

declare(strict_types=1);

namespace DynamixFileRecycle;

final class SqliteStatement
{
    public const PARAM_INT = 1;

    /** @var array<string,mixed> */
    private array $bound = [];
    private SqliteResult $result;
    private int $affected = 0;

    public function __construct(private SqliteConnection $connection, private string $sql)
    {
        $this->result = new SqliteResult([]);
    }

    public function bindValue(string $name, mixed $value, int $type = 0): bool
    {
        $this->bound[$this->normaliseName($name)] = $type === self::PARAM_INT ? (int) $value : $value;
        return true;
    }

    /** @param array<string,mixed>|null $parameters */
    public function execute(?array $parameters = null): bool
    {
        $values = $this->bound;
        foreach ($parameters ?? [] as $name => $value) {
            $values[$this->normaliseName((string) $name)] = $value;
        }
        [$this->result, $this->affected] = $this->connection->executePrepared($this->sql, $values);
        return true;
    }

    /** @return list<array<string,mixed>> */
    public function fetchAll(): array
    {
        return $this->result->fetchAll();
    }

    /** @return array<string,mixed>|false */
    public function fetch(): array|false
    {
        return $this->result->fetch();
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->result->fetchColumn($column);
    }

    public function rowCount(): int
    {
        return $this->affected;
    }

    private function normaliseName(string $name): string
    {
        return ltrim($name, ':');
    }
}
