<?php
/**
 * I18n.php — minimalist language file loader.
 *
 * Language files live at:
 *   languages/en_US.txt
 *   languages/zh_CN.txt
 *
 * Format: plain "key=value" lines. Lines starting with ; or # are comments.
 * Keys are dotted identifiers, values are UTF-8 strings.
 *
 * Language resolution order:
 *   1. Configured language in the plugin cfg (auto | en_US | zh_CN).
 *   2. "auto" -> read Unraid system language from /usr/local/emhttp/state/var.ini
 *      (var.ini key `locale` or `lang`).
 *   3. Fallback to en_US, and any missing key falls back to en_US as well.
 */

declare(strict_types=1);

namespace DynamixFileRecycle;

final class I18n
{
    private string $resolved;
    /** @var array<string,string> */
    private array $dict = [];
    /** @var array<string,string> */
    private array $fallback = [];

    public function __construct(string $langDir, string $configured)
    {
        $this->resolved = $configured === 'auto' ? $this->detectSystem() : $configured;
        if (!in_array($this->resolved, ['en_US', 'zh_CN'], true)) {
            $this->resolved = 'en_US';
        }
        $this->fallback = $this->load($langDir . '/en_US.txt');
        if ($this->resolved !== 'en_US') {
            $d = $this->load($langDir . '/' . $this->resolved . '.txt');
            $this->dict = $d;
        } else {
            $this->dict = $this->fallback;
        }
    }

    public function lang(): string
    {
        return $this->resolved;
    }

    /**
     * Translate a key. Supports printf-style positional args.
     *   t('btn.recycle', 'Movies', 3)
     * returns e.g. "Move 'Movies' (3) to Recycle Bin" when the value is
     *   "Move '%s' (%d) to Recycle Bin".
     */
    public function t(string $key, mixed ...$args): string
    {
        $v = $this->dict[$key] ?? $this->fallback[$key] ?? $key;
        if ($args === []) {
            return $v;
        }
        return vsprintf($v, $args);
    }

    /**
     * Detect Unraid system language. Reads var.ini (parse_ini_string safe).
     */
    private function detectSystem(): string
    {
        $varIni = '/usr/local/emhttp/state/var.ini';
        if (!is_file($varIni)) {
            return 'en_US';
        }
        $parsed = @parse_ini_string((string) file_get_contents($varIni));
        $lang = $parsed['locale'] ?? $parsed['lang'] ?? $parsed['language'] ?? '';
        $lang = strtolower(trim((string) $lang));
        if (str_starts_with($lang, 'zh')) {
            return 'zh_CN';
        }
        return 'en_US';
    }

    /** @return array<string,string> */
    private function load(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $out = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = ltrim($line);
            if ($line === '' || $line[0] === ';' || $line[0] === '#') {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $k = trim(substr($line, 0, $eq));
            $v = trim(substr($line, $eq + 1));
            // Strip optional surrounding quotes.
            if (strlen($v) >= 2 && $v[0] === '"' && substr($v, -1) === '"') {
                $v = substr($v, 1, -1);
            }
            if ($k !== '') {
                $out[$k] = stripcslashes($v);
            }
        }
        return $out;
    }
}
