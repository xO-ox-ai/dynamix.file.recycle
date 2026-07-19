<?php
/** In-memory result returned by the bounded sqlite3 CLI adapter. */

declare(strict_types=1);

namespace DynamixFileRecycle;

final class SqliteResult
{
    private int $cursor = 0;

    /** @param list<array<string,mixed>> $rows */
    public function __construct(private array $rows) {}

    /** @return list<array<string,mixed>> */
    public function fetchAll(): array
    {
        return $this->rows;
    }

    /** @return array<string,mixed>|false */
    public function fetch(): array|false
    {
        if (!isset($this->rows[$this->cursor])) return false;
        return $this->rows[$this->cursor++];
    }

    public function fetchColumn(int $column = 0): mixed
    {
        if (!isset($this->rows[0])) return false;
        $values = array_values($this->rows[0]);
        return $values[$column] ?? false;
    }
}
