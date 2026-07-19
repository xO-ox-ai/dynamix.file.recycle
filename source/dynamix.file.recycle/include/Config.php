<?php
/**
 * Config.php — read / write the plugin .cfg file (INI format).
 *
 * The cfg lives at CFG_FILE (under /boot, persistent). If a key is missing or
 * the file does not exist, the value falls back to the shipped default cfg.
 * Mutations go through save() which atomically rewrites the file.
 */

declare(strict_types=1);

namespace DynamixFileRecycle;

final class Config
{
    private string $file;
    private array $values = [];
    private array $defaults = [];

    public function __construct(string $file, string $defaultFile)
    {
        $this->defaults = $this->parse($defaultFile);
        $user = is_file($file) ? $this->parse($file) : [];
        // Deep merge: user overrides defaults.
        $this->values = array_replace_recursive($this->defaults, $user);
        $this->file = $file;
    }

    public function getEnabled(): bool
    {
        return $this->getBool('global', 'enabled', true);
    }

    public function setEnabled(bool $v): void
    {
        $this->set('global', 'enabled', $v ? '1' : '0');
    }

    public function getLanguage(): string
    {
        $v = $this->getString('global', 'language', 'auto');
        return in_array($v, ['auto', 'en_US', 'zh_CN'], true) ? $v : 'auto';
    }

    public function getLogLevel(): string
    {
        $v = strtoupper($this->getString('global', 'log_level', 'INFO'));
        return in_array($v, ['ERROR', 'WARN', 'INFO', 'DEBUG'], true) ? $v : 'INFO';
    }

    public function setLogLevel(string $v): void
    {
        $this->set('global', 'log_level', strtoupper($v));
    }

    public function getLogRetentionDays(): int
    {
        return $this->getInt('global', 'log_retention_days', 30);
    }

    public function getLogMaxMib(): int
    {
        return $this->getInt('global', 'log_max_size_mib', 5);
    }

    public function getHistoryEnabled(): bool
    {
        return $this->getBool('history', 'enabled', true);
    }

    public function getHistoryRetentionDays(): int
    {
        return $this->getInt('history', 'retention_days', 365);
    }

    public function getAgeDays(): int
    {
        return max(0, $this->getInt('maintenance', 'age_days', 30));
    }

    public function getCapacityMode(): string
    {
        $v = strtolower($this->getString('maintenance', 'capacity_mode', 'percent'));
        return $v === 'absolute' ? 'absolute' : 'percent';
    }

    public function getCapacityPercent(): int
    {
        return max(0, min(100, $this->getInt('maintenance', 'capacity_percent', 10)));
    }

    public function getCapacityAbsoluteGb(): int
    {
        return max(0, $this->getInt('maintenance', 'capacity_absolute_gb', 0));
    }

    public function getAutoEmptyCron(): string
    {
        return trim($this->getString('maintenance', 'auto_empty_cron', ''));
    }

    public function getVacuumSqlite(): bool
    {
        return $this->getBool('maintenance', 'vacuum_sqlite', true);
    }

    public function getPreserveMetadata(): bool
    {
        return $this->getBool('security', 'preserve_metadata', true);
    }

    /**
     * Null means the initial "all currently supported volumes" policy.
     * Once settings are saved, the value is an explicit fail-closed list.
     *
     * @return list<string>|null
     */
    public function getAllowedVolumes(): ?array
    {
        $raw = $this->getString('volumes', 'allowed', '*');
        if ($raw === '*') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $volumes = [];
        foreach ($decoded as $volume) {
            if (is_string($volume) && str_starts_with($volume, '/')) {
                $volumes[$volume] = true;
            }
        }
        return array_keys($volumes);
    }

    public function isVolumeAllowed(string $volume): bool
    {
        $allowed = $this->getAllowedVolumes();
        return $allowed === null || in_array($volume, $allowed, true);
    }

    /**
     * Persist all values back to disk as INI. Section order is preserved.
     */
    public function save(): void
    {
        $out = "; Dynamix File Recycle Bin configuration. Edited via the\n"
             . "; Settings UI. Comments are NOT preserved on save.\n\n";
        foreach ($this->values as $section => $kv) {
            $out .= "[$section]\n";
            foreach ($kv as $k => $v) {
                $out .= "$k = " . $this->encodeValue($v) . "\n";
            }
            $out .= "\n";
        }
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        // Atomic write: tmp + rename.
        $tmp = $this->file . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $out) === false) {
            throw new \RuntimeException("Failed to write config: $tmp");
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $this->file)) {
            @unlink($tmp);
            throw new \RuntimeException("Failed to commit config: $this->file");
        }
    }

    // ----- internal helpers -------------------------------------------------

    private function parse(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $parsed = @parse_ini_string(file_get_contents($file), true, INI_SCANNER_RAW);
        if (!is_array($parsed)) {
            return [];
        }
        foreach ($parsed as &$section) {
            if (!is_array($section)) continue;
            foreach ($section as &$value) {
                if (is_string($value)) {
                    $value = $this->decodeValue($value);
                }
            }
            unset($value);
        }
        unset($section);
        return $parsed;
    }

    private function getBool(string $section, string $key, bool $fallback): bool
    {
        $v = $this->values[$section][$key] ?? null;
        if ($v === null) {
            return $fallback;
        }
        if (is_bool($v)) {
            return $v;
        }
        $v = trim((string) $v);
        return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    private function getInt(string $section, string $key, int $fallback): int
    {
        $v = $this->values[$section][$key] ?? null;
        if ($v === null || $v === '') {
            return $fallback;
        }
        return (int) $v;
    }

    private function getString(string $section, string $key, string $fallback): string
    {
        $v = $this->values[$section][$key] ?? null;
        return $v === null ? $fallback : trim((string) $v);
    }

    private function set(string $section, string $key, string $value): void
    {
        $this->values[$section][$key] = $value;
    }

    private function encodeValue(string $v): string
    {
        // Always quote: simplest and safest for round-trip.
        $esc = str_replace(['\\', '"'], ['\\\\', '\\"'], $v);
        return '"' . $esc . '"';
    }

    /** Reverse the escaping performed by encodeValue(). INI_SCANNER_RAW
     * strips the surrounding quotes but deliberately preserves backslashes. */
    private function decodeValue(string $v): string
    {
        return str_replace(['\\"', '\\\\'], ['"', '\\'], $v);
    }

    /** @internal Used by the settings page. */
    public function raw(): array
    {
        return $this->values;
    }

    /** @internal Update many values at once then save. */
    public function mergeAndSave(array $patch): void
    {
        $this->values = array_replace_recursive($this->values, $patch);
        $this->save();
    }
}
