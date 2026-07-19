<?php
/**
 * Logger.php — file-based logger with level filtering and size-based rotation.
 *
 * Log lines:
 *   2026-07-19T12:34:56+00:00 [INFO] recycle path=/mnt/disk1/x message=ok
 *
 * Rotation: when the log file exceeds logMaxMib, the current file is renamed
 * to .1 (overwriting any previous .1) and a new file is started. Date-based
 * persistent operation events are recorded in each volume's SQLite shard.
 */

declare(strict_types=1);

namespace DynamixFileRecycle;

final class Logger
{
    private const LEVELS = ['ERROR' => 0, 'WARN' => 1, 'INFO' => 2, 'DEBUG' => 3];
    private string $file;
    private string $auditFile;
    private int $level;
    private int $maxBytes;

    public function __construct(string $file, string $auditFile, string $level, int $logMaxMib)
    {
        $this->file = $file;
        $this->auditFile = $auditFile;
        $this->level = self::LEVELS[strtoupper($level)] ?? self::LEVELS['INFO'];
        $this->maxBytes = max(1, $logMaxMib) * 1024 * 1024;
    }

    public function error(string $action, string $path, string $message): void
    {
        $this->write('ERROR', $action, $path, $message);
        $this->writeAudit('ERROR', $action, $path, $message);
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

    public function audit(string $action, string $path, string $message): void
    {
        $this->writeAudit('AUDIT', $action, $path, $message);
    }

    /** Remove volatile and persistent file logs, including rotated copies. */
    public function clear(): int
    {
        $removed = 0;
        foreach ([$this->file, $this->file . '.1', $this->auditFile, $this->auditFile . '.1'] as $file) {
            if (!is_file($file)) continue;
            if (!@unlink($file)) {
                throw new \RuntimeException('Unable to remove log file: ' . $file, 500);
            }
            $removed++;
        }
        return $removed;
    }

    /** Combined size of this plugin's file logs, including one rotation. */
    public function sizeBytes(): int
    {
        $bytes = 0;
        foreach ([$this->file, $this->file . '.1', $this->auditFile, $this->auditFile . '.1'] as $file) {
            if (!is_file($file) || is_link($file)) continue;
            $size = @filesize($file);
            if ($size !== false) $bytes += (int) $size;
        }
        return $bytes;
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
     * (.1). Errors are also copied into the bounded persistent audit log.
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

    private function writeAudit(string $level, string $action, string $path, string $message): void
    {
        $dir = dirname($this->auditFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $this->rotateFile($this->auditFile, 1024 * 1024);
        $line = sprintf(
            "%s [%s] %s path=%s msg=%s\n",
            gmdate('c'),
            $level,
            $action,
            $this->tokenize($path),
            $this->sanitize($message)
        );
        @file_put_contents($this->auditFile, $line, FILE_APPEND | LOCK_EX);
        @chmod($this->auditFile, 0600);
    }

    private function rotateFile(string $file, int $maxBytes): void
    {
        if (!is_file($file) || (int) @filesize($file) < $maxBytes) {
            return;
        }
        $backup = $file . '.1';
        if (is_file($backup)) {
            @unlink($backup);
        }
        @rename($file, $backup);
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
