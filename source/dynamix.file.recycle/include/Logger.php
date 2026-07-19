<?php
/**
 * Logger.php — file-based logger with level filtering and size-based rotation.
 *
 * Log lines:
 *   2026-07-19T12:34:56+00:00 [INFO] recycle path=/mnt/disk1/x message=ok
 *
 * Rotation: when the log file exceeds logMaxMib, the current file is renamed
 * to .1 (overwriting any previous .1) and a new file is started. Date-based
 * purging of log ROWS in the SQLite `log` table happens in Maintenance.
 */

declare(strict_types=1);

namespace DynamixFileRecycle;

final class Logger
{
    private const LEVELS = ['ERROR' => 0, 'WARN' => 1, 'INFO' => 2, 'DEBUG' => 3];
    private string $file;
    private int $level;
    private int $retentionDays;
    private int $maxBytes;

    public function __construct(string $file, string $level, int $retentionDays, int $logMaxMib)
    {
        $this->file = $file;
        $this->level = self::LEVELS[strtoupper($level)] ?? self::LEVELS['INFO'];
        $this->retentionDays = max(0, $retentionDays);
        $this->maxBytes = max(1, $logMaxMib) * 1024 * 1024;
    }

    public function error(string $action, string $path, string $message): void
    {
        $this->write('ERROR', $action, $path, $message);
    }

    public function warn(string $action, string $path, string $message): void
    {
        $this->write('WARN', $action, $path, $message);
    }

    public function info(string $action, string $path, string $message): void
    {
        $this->write('INFO', $action, $path, $message);
    }

    public function debug(string $action, string $path, string $message): void
    {
        $this->write('DEBUG', $action, $path, $message);
    }

    private function write(string $level, string $action, string $path, string $message): void
    {
        if (self::LEVELS[$level] > $this->level) {
            return;
        }
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $this->maybeRotate();
        $ts = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->format(\DateTimeInterface::ATOM);
        $line = sprintf(
            "%s [%s] %s path=%s msg=%s\n",
            $ts,
            $level,
            $action,
            $this->tokenize($path),
            $this->sanitize($message)
        );
        @file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Rotate the file when it grows past maxBytes. We keep a single backup
     * (.1) — that plus the SQLite `log` table is enough for most audits.
     */
    private function maybeRotate(): void
    {
        if (!is_file($this->file)) {
            return;
        }
        if (@filesize($this->file) < $this->maxBytes) {
            return;
        }
        $backup = $this->file . '.1';
        if (is_file($backup)) {
            @unlink($backup);
        }
        @rename($this->file, $backup);
    }

    /**
     * Replace any whitespace/control chars in path so the log line stays a
     * single line.
     */
    private function tokenize(string $s): string
    {
        $s = str_replace(["\r", "\n", "\t"], ' ', $s);
        return preg_replace('/\s+/', ' ', $s) ?? $s;
    }

    private function sanitize(string $s): string
    {
        return addcslashes($this->tokenize($s), '"\\');
    }
}
