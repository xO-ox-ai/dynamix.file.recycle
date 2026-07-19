<?php
/**
 * SQLite adapter using Unraid's bundled sqlite3 command-line client.
 *
 * Unraid 7.2/7.3 ships SQLite but its WebGUI PHP build has no PDO drivers.
 * Commands are started without a shell. Bound text values become hex SQL
 * literals, so file names and paths can never alter SQL syntax.
 */

declare(strict_types=1);

namespace DynamixFileRecycle;

final class SqliteConnection
{
    private const MAX_OUTPUT_BYTES = 33554432;

    public function __construct(private string $file, private ?string $binary = null)
    {
        $this->binary = $binary ?? self::findBinary();
        if ($this->binary === null) {
            throw new \RuntimeException('The sqlite3 command-line client is unavailable.', 503);
        }
    }

    public static function findBinary(): ?string
    {
        $override = getenv('DYNAMIX_RECYCLE_SQLITE');
        $candidates = is_string($override) && $override !== ''
            ? [$override]
            : ['/usr/bin/sqlite3', '/bin/sqlite3'];
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) return $candidate;
        }
        return null;
    }

    public function prepare(string $sql): SqliteStatement
    {
        return new SqliteStatement($this, $sql);
    }

    public function query(string $sql): SqliteResult
    {
        return new SqliteResult($this->runJson($sql));
    }

    public function exec(string $sql): int
    {
        if (preg_match('/\A\s*(?:DELETE|UPDATE|INSERT|REPLACE)\b/i', $sql) === 1) {
            [, $affected] = $this->executePrepared($sql, []);
            return $affected;
        }
        $this->run($sql, false);
        return 0;
    }

    /** @param array<string,mixed> $parameters
     * @return array{0:SqliteResult,1:int} */
    public function executePrepared(string $sql, array $parameters): array
    {
        $substituted = preg_replace_callback(
            '/(?<!:):([A-Za-z_][A-Za-z0-9_]*)/',
            function (array $match) use ($parameters): string {
                $name = $match[1];
                if (!array_key_exists($name, $parameters)) {
                    throw new \RuntimeException('A required SQLite parameter was not supplied: ' . $name, 500);
                }
                return $this->literal($parameters[$name]);
            },
            $sql
        );
        if (!is_string($substituted)) {
            throw new \RuntimeException('Unable to bind SQLite parameters.', 500);
        }
        if (preg_match('/\A\s*(?:SELECT|PRAGMA|WITH)\b/i', $substituted) === 1) {
            return [new SqliteResult($this->runJson($substituted)), 0];
        }
        $rows = $this->runJson(rtrim($substituted, "; \t\r\n") . '; SELECT changes() AS __affected');
        $affected = isset($rows[0]['__affected']) ? (int) $rows[0]['__affected'] : 0;
        return [new SqliteResult([]), $affected];
    }

    /** @return list<array<string,mixed>> */
    private function runJson(string $sql): array
    {
        $output = trim($this->run($sql, true));
        if ($output === '') return [];
        $decoded = json_decode($output, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('sqlite3 returned malformed JSON output.', 500);
        }
        return array_values(array_filter($decoded, 'is_array'));
    }

    private function run(string $sql, bool $json): string
    {
        $argv = [$this->binary, '-batch', '-bail'];
        if ($json) $argv[] = '-json';
        $argv[] = $this->file;
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($argv, $descriptors, $pipes, null, ['LC_ALL' => 'C.UTF-8']);
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start the sqlite3 command-line client.', 503);
        }
        fwrite(
            $pipes[0],
            ".timeout 5000\nPRAGMA foreign_keys=ON;\nPRAGMA synchronous=FULL;\n" . $sql . "\n"
        );
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1], self::MAX_OUTPUT_BYTES + 1);
        $stderr = stream_get_contents($pipes[2], 1048577);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if (strlen($stdout) > self::MAX_OUTPUT_BYTES || strlen($stderr) > 1048576) {
            throw new \RuntimeException('sqlite3 output exceeded the diagnostic safety limit.', 500);
        }
        if ($exit !== 0) {
            $message = trim($stderr) ?: 'unknown sqlite3 error';
            throw new \RuntimeException('SQLite operation failed: ' . $message, 500);
        }
        return $stdout;
    }

    private function literal(mixed $value): string
    {
        if ($value === null) return 'NULL';
        if (is_bool($value)) return $value ? '1' : '0';
        if (is_int($value)) return (string) $value;
        if (is_float($value) && is_finite($value)) return (string) $value;
        if (!is_string($value)) {
            throw new \RuntimeException('Unsupported SQLite parameter type.', 500);
        }
        return "CAST(X'" . bin2hex($value) . "' AS TEXT)";
    }
}
