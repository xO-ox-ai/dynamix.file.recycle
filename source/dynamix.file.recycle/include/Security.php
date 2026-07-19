<?php
/**
 * Security.php — path and request-safety helpers.
 *
 *   - assertEnabled()  require the plugin master switch to be on
 *   - assertPathInScope($absPath)   require a path within a v1 volume
 *   - issueInspectionToken()        sign the currently inspected inode state
 *   - verifyInspectionToken()       re-check that state before mutation
 *
 * Tokens are signed with HMAC-SHA256 over the absolute path using a per-host
 * volatile secret stored under STATE_DIR. They are not authorization by
 * themselves: Unraid authenticates the WebGUI request and validates its CSRF
 * token, while the plugin repeats path, topology and inode checks. Tokens
 * expire after two minutes and intentionally become invalid after reboot.
 */

declare(strict_types=1);

namespace DynamixFileRecycle;

final class Security
{
    private FsInspector $fs;
    private ?string $secret = null;

    public function __construct(FsInspector $fs)
    {
        $this->fs = $fs;
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
        $unsupported = $this->fs->unsupportedPathReason($absPath);
        if ($unsupported !== null) {
            throw new \RuntimeException($unsupported, 409);
        }
        $lexical = $this->fs->lexicalNormalise($absPath);
        $canonical = $this->fs->normalise($absPath);
        if ($canonical === null || $lexical === null) {
            throw new \RuntimeException('Invalid or escaping path.', 400);
        }
        if ($canonical !== $lexical) {
            throw new \RuntimeException('Symbolic-link or aliased paths are not supported in this release.', 409);
        }
        if (is_link($canonical)) {
            throw new \RuntimeException('Symbolic links are not supported in this release.', 409);
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
        $volume = $this->fs->resolveVolume($canonical);
        if ($volume === null || $canonical === $volume['volume']) {
            throw new \RuntimeException('A disk or ZFS dataset root cannot be moved to its own recycle bin.', 409);
        }
        $externalReason = $this->fs->externalStorageReason($volume['volume'], $volume['fs']);
        if ($externalReason !== null) {
            throw new \RuntimeException($externalReason, 409);
        }
        $stat = @lstat($canonical);
        if ($stat === false) {
            throw new \RuntimeException('The selected item no longer exists.', 404);
        }
        $type = ((int) $stat['mode']) & 0170000;
        if (!in_array($type, [0100000, 0040000], true)) {
            throw new \RuntimeException('Only regular files and ordinary directories are supported in this release.', 409);
        }
        if ($type === 0040000 && $this->fs->hasMountAtOrBelow($canonical)) {
            throw new \RuntimeException('The selected directory is or contains a mount point and cannot be recycled safely.', 409);
        }
        $volumeStat = @stat($volume['volume']);
        if ($volumeStat === false || (int) $stat['dev'] !== (int) $volumeStat['dev']) {
            throw new \RuntimeException('The item crosses a filesystem boundary; this release only supports atomic same-filesystem moves.', 409);
        }
        return $canonical;
    }

    public function issueInspectionToken(string $canonical): string
    {
        $canonical = $this->assertPathInScope($canonical);
        $stat = @lstat($canonical);
        if ($stat === false) {
            throw new \RuntimeException('The selected item no longer exists.', 404);
        }
        $payload = [
            'v' => 1,
            'path' => $canonical,
            'dev' => (int) $stat['dev'],
            'ino' => (int) $stat['ino'],
            'mode' => (int) $stat['mode'],
            'size' => (int) $stat['size'],
            'mtime' => (int) $stat['mtime'],
            'ctime' => (int) $stat['ctime'],
            'exp' => time() + 120,
        ];
        $encoded = $this->base64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $encoded, $this->secret(), true));
        return $encoded . '.' . $signature;
    }

    public function verifyInspectionToken(string $token, string $claimedPath): string
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || strlen($token) > 2048) {
            throw new \RuntimeException('The inspection approval is malformed. Check the item again.', 409);
        }
        [$encoded, $signature] = $parts;
        $expected = $this->base64UrlEncode(hash_hmac('sha256', $encoded, $this->secret(), true));
        if (!hash_equals($expected, $signature)) {
            throw new \RuntimeException('The inspection approval is invalid. Check the item again.', 409);
        }
        $decoded = $this->base64UrlDecode($encoded);
        $payload = $decoded === null ? null : json_decode($decoded, true);
        if (!is_array($payload) || ($payload['v'] ?? null) !== 1 || (int) ($payload['exp'] ?? 0) < time()) {
            throw new \RuntimeException('The inspection approval expired. Check the item again.', 409);
        }
        $canonical = $this->assertPathInScope($claimedPath);
        if (!hash_equals((string) ($payload['path'] ?? ''), $canonical)) {
            throw new \RuntimeException('The inspected path does not match this request.', 409);
        }
        $stat = @lstat($canonical);
        foreach (['dev', 'ino', 'mode', 'size', 'mtime', 'ctime'] as $field) {
            if ($stat === false || (int) ($payload[$field] ?? -1) !== (int) ($stat[$field] ?? -2)) {
                throw new \RuntimeException('The item changed after inspection. Check it again before recycling.', 409);
            }
        }
        return $canonical;
    }

    public function assertRecyclePath(string $path, string $volume): string
    {
        $canonical = $this->fs->normalise($path);
        if ($canonical === null || is_link($path) || !$this->fs->isInsideRecycleRoot($canonical, $volume)) {
            throw new \RuntimeException('Stored recycle path escaped its owning recycle bin.', 409);
        }
        if (basename($canonical) === History::DB_NAME
            || str_starts_with(basename($canonical), History::DB_NAME . '-')) {
            throw new \RuntimeException('The recycle metadata database cannot be modified as an item.', 409);
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
                'log_level'         => 'enum:ERROR,WARN,INFO,DEBUG',
                'log_retention_days'=> 'int:0:3650',
                'log_max_size_mib'  => 'int:1:100',
            ],
            'history' => [
                'enabled'        => 'boolString',
                'retention_days' => 'int:0:36500',
            ],
            'volumes' => [
                'allowed' => 'volumeList',
            ],
            'maintenance' => [
                'age_days'            => 'int:0:36500',
                'capacity_mode'       => 'enum:percent,absolute',
                'capacity_percent'    => 'int:0:100',
                'capacity_absolute_gb'=> 'int:0:1048576',
                'auto_empty_cron'     => 'cron',
                'vacuum_sqlite'       => 'boolString',
            ],
            'security' => [
                'preserve_metadata'      => 'boolString',
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
            if (!in_array($v, $choices, true)) {
                throw new \RuntimeException('A configuration option contains an unsupported value.', 400);
            }
            return $v;
        }
        if ($rule === 'boolString') {
            if (is_bool($v)) {
                return $v ? '1' : '0';
            }
            $s = strtolower((string) $v);
            return in_array($s, ['1','true','yes','on'], true) ? '1' : '0';
        }
        if ($rule === 'volumeList') {
            if (!is_array($v) || count($v) > 128) {
                throw new \RuntimeException('The selected volume list is invalid.', 400);
            }
            $approved = [];
            foreach ($v as $volume) {
                if (!is_string($volume) || strlen($volume) > 1024) {
                    throw new \RuntimeException('The selected volume list contains an invalid path.', 400);
                }
                $lexical = $this->fs->lexicalNormalise($volume);
                $canonical = $this->fs->normalise($volume);
                if ($lexical === null || $canonical === null || $lexical !== $canonical
                    || !$this->fs->isApprovedVolumeRoot($canonical)) {
                    throw new \RuntimeException(
                        'A selected disk or dataset is outside the currently supported internal-storage scope. Refresh the page and select only listed volumes.',
                        409
                    );
                }
                $approved[$canonical] = true;
            }
            return (string) json_encode(array_keys($approved), JSON_UNESCAPED_SLASHES);
        }
        if (str_starts_with($rule, 'int:')) {
            [, $minimum, $maximum] = explode(':', $rule, 3);
            if (filter_var($v, FILTER_VALIDATE_INT) === false) {
                throw new \RuntimeException('A numeric configuration value is invalid.', 400);
            }
            $number = (int) $v;
            if ($number < (int) $minimum || $number > (int) $maximum) {
                throw new \RuntimeException('A numeric configuration value is outside the allowed range.', 400);
            }
            return (string) $number;
        }
        if ($rule === 'cron') {
            $s = preg_replace('/\s+/', ' ', trim((string) $v));
            if ($s === '') {
                return $s;
            }
            $fields = explode(' ', $s);
            $ranges = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];
            if (count($fields) !== 5) {
                throw new \RuntimeException('The auto-empty schedule is not a supported five-field cron expression.', 400);
            }
            foreach ($fields as $index => $field) {
                if (!$this->validCronField($field, $ranges[$index][0], $ranges[$index][1])) {
                    throw new \RuntimeException('The auto-empty schedule is not a supported five-field cron expression.', 400);
                }
            }
            return $s;
        }
        return '';
    }

    private function validCronField(string $field, int $minimum, int $maximum): bool
    {
        foreach (explode(',', $field) as $part) {
            if ($part === '*') continue;
            if (preg_match('/\A\*\/([1-9][0-9]*)\z/', $part, $match) === 1) {
                if ((int) $match[1] <= ($maximum - $minimum + 1)) continue;
                return false;
            }
            if (preg_match('/\A([0-9]+)-([0-9]+)\z/', $part, $match) === 1) {
                $low = (int) $match[1];
                $high = (int) $match[2];
                if ($low >= $minimum && $high <= $maximum && $low <= $high) continue;
                return false;
            }
            if (ctype_digit($part)) {
                $number = (int) $part;
                if ($number >= $minimum && $number <= $maximum) continue;
            }
            return false;
        }
        return true;
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

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/\A[A-Za-z0-9_-]+\z/', $value) !== 1) {
            return null;
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }
}
