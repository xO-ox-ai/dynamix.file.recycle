<?php
/**
 * Security.php — auth and path safety helpers.
 *
 *   - assertAdmin()    require an authenticated admin session (Unraid)
 *   - assertEnabled()  require the plugin master switch to be on
 *   - assertPathInScope($absPath)   require a path within a v1 volume
 *   - itemToken($absPath)           stable opaque ID for the front-end
 *   - decodeItemToken($token)       reverse of itemToken()
 *
 * Tokens are signed with HMAC-SHA256 over the absolute path using a per-host
 * secret stored under STATE_DIR. They are NOT security by themselves — the
 * server always re-validates the path on every call — but they prevent users
 * from casually forging arbitrary targets in the front-end and they keep the
 * front-end DOM stable across refreshes (the token depends only on the path).
 */

declare(strict_types=1);

namespace DynamixFileRecycle;

final class Security
{
    private FsInspector $fs;
    private History $history;
    private ?string $secret = null;

    public function __construct(FsInspector $fs, History $history)
    {
        $this->fs = $fs;
        $this->history = $history;
    }

    /**
     * Require an authenticated admin user. Unraid exposes this via the session
     * and the `is_user_admin()` helper in newer versions; we fall back to
     * the legacy `$_SESSION['gui']['user']` shape used by emhttp.
     *
     * @throws \RuntimeException when not admin
     */
    public function assertAdmin(): void
    {
        $ok = false;
        if (function_exists('is_user_admin')) {
            $ok = (bool) is_user_admin();
        }
        if (!$ok) {
            $user = $_SESSION['gui']['user'] ?? $_SESSION['user'] ?? '';
            $role = $_SESSION['gui']['role'] ?? '';
            $ok = ($user === 'root' || $role === 'admin' || $user === 'admin');
        }
        if (!$ok) {
            throw new \RuntimeException('Administrator privileges required.', 403);
        }
    }

    public function assertEnabled(Config $cfg): void
    {
        if (!$cfg->getEnabled()) {
            throw new \RuntimeException('Plugin is disabled.', 409);
        }
    }

    /**
     * Resolve and validate a path. Throws on out-of-scope or traversal escape.
     * Returns the canonical absolute path.
     */
    public function assertPathInScope(string $absPath): string
    {
        $canonical = $this->fs->normalise($absPath);
        if ($canonical === null) {
            throw new \RuntimeException('Invalid or escaping path.', 400);
        }
        if (!$this->fs->isInScope($canonical)) {
            throw new \RuntimeException(
                'Path is out of scope for v1 (only /mnt/disk* and ZFS datasets are supported).',
                400
            );
        }
        // Block attempts to operate on the recycle bin itself or its parents.
        if (str_contains($canonical, '/' . FsInspector::RECYCLE_NAME . '/')) {
            throw new \RuntimeException('Cannot operate inside the recycle bin.', 400);
        }
        if (str_ends_with($canonical, '/' . FsInspector::RECYCLE_NAME)) {
            throw new \RuntimeException('Cannot operate on the recycle bin itself.', 400);
        }
        return $canonical;
    }

    /**
     * Produce a stable opaque token for an absolute path. Same path => same
     * token across requests, regardless of the file existing or not.
     */
    public function itemToken(string $absPath): string
    {
        $canonical = $this->fs->normalise($absPath) ?? $absPath;
        $sig = hash_hmac('sha256', $canonical, $this->secret());
        // Trim to keep DOM attribute values short; 16 hex bytes = 64 bits,
        // collisions astronomically unlikely per server.
        return substr($sig, 0, 32) . '.' . substr(bin2hex($canonical), 0, 0);
    }

    /**
     * Decode a token back to a path. Because the token is HMAC'd, decoding is
     * really "find the path whose token matches this" — but the canonical path
     * itself is sent along with the token from the front-end, so we just
     * re-sign the supplied path and compare. Returns the canonical path on
     * match, throws otherwise.
     */
    public function decodeItemToken(string $token, string $claimedPath): string
    {
        $canonical = $this->fs->normalise($claimedPath);
        if ($canonical === null) {
            throw new \RuntimeException('Invalid path supplied with token.', 400);
        }
        $expected = $this->itemToken($canonical);
        if (!hash_equals($expected, $token)) {
            throw new \RuntimeException('Token mismatch.', 403);
        }
        return $canonical;
    }

    /**
     * Sanitise a config patch coming from the front-end. Only known keys
     * under known sections survive; everything else is dropped.
     *
     * @param array $patch raw user input
     * @return array
     */
    public function sanitizeConfigPatch(array $patch): array
    {
        $allowed = [
            'global' => [
                'enabled'           => 'boolString',
                'language'          => 'enum:auto,en_US,zh_CN',
                'log_level'         => 'enum:ERROR,WARN,INFO,DEBUG',
                'log_retention_days'=> 'int',
                'log_max_size_mib'  => 'int',
            ],
            'history' => [
                'enabled'        => 'boolString',
                'retention_days' => 'int',
            ],
            'maintenance' => [
                'interval_hours'      => 'int',
                'age_days'            => 'int',
                'capacity_mode'       => 'enum:percent,absolute',
                'capacity_percent'    => 'int',
                'capacity_absolute_gb'=> 'int',
                'auto_empty_cron'     => 'cron',
                'vacuum_sqlite'       => 'boolString',
            ],
            'security' => [
                'preserve_metadata'      => 'boolString',
                'allow_user_self_service'=> 'boolString',
            ],
        ];

        $clean = [];
        foreach ($patch as $section => $kv) {
            if (!is_string($section) || !isset($allowed[$section]) || !is_array($kv)) {
                continue;
            }
            foreach ($kv as $k => $v) {
                if (!isset($allowed[$section][$k])) {
                    continue;
                }
                $rule = $allowed[$section][$k];
                $clean[$section][$k] = $this->cast($rule, $v);
            }
        }
        return $clean;
    }

    private function cast(string $rule, mixed $v): string
    {
        $v = is_string($v) ? trim($v) : $v;
        if (str_starts_with($rule, 'enum:')) {
            $choices = explode(',', substr($rule, 5));
            $v = (string) $v;
            return in_array($v, $choices, true) ? $v : '';
        }
        if ($rule === 'boolString') {
            if (is_bool($v)) {
                return $v ? '1' : '0';
            }
            $s = strtolower((string) $v);
            return in_array($s, ['1','true','yes','on'], true) ? '1' : '0';
        }
        if ($rule === 'int') {
            return (string) max(0, (int) $v);
        }
        if ($rule === 'cron') {
            // 5 whitespace-separated fields, very permissive.
            $s = preg_replace('/\s+/', ' ', trim((string) $v));
            if ($s === '' || substr_count($s, ' ') === 4) {
                return $s;
            }
            return '';
        }
        return '';
    }

    private function secret(): string
    {
        if ($this->secret !== null) {
            return $this->secret;
        }
        $file = STATE_DIR . '/.secret';
        if (is_file($file)) {
            $s = (string) file_get_contents($file);
            if (strlen($s) >= 32) {
                return $this->secret = $s;
            }
        }
        // Generate and persist.
        $s = bin2hex(random_bytes(32));
        @file_put_contents($file, $s, LOCK_EX);
        @chmod($file, 0600);
        return $this->secret = $s;
    }
}
