<?php
/**
 * FsInspector.php — path and filesystem inspection.
 *
 * Decides which "volume" an absolute path belongs to. v1 scope:
 *   /mnt/disk*        -> volume = /mnt/diskN, fs = whatever df reports
 *   ZFS dataset mount -> volume = dataset mountpoint, fs = zfs
 *
 * Explicitly OUT OF SCOPE for v1:
 *   /mnt/user*        -> pool/share virtual path
 *   /mnt/cache*       -> cache pool
 *
 * The class caches ZFS dataset mappings within a single request because
 * `zfs list` is moderately expensive.
 */

declare(strict_types=1);

namespace DynamixFileRecycle;

final class FsInspector
{
    /** @var array<string,string>|null mountpoint -> dataset name */
    private ?array $zfsMap = null;

    /** @var array<string,array{total:int,used:int}> */
    private array $statCache = [];

    public const RECYCLE_NAME = '.RecycleBin';

    /**
     * Resolve an absolute path to {volume, fs, relative}.
     *
     * @return array{volume:string,fs:string,relative:string}|null
     */
    public function resolveVolume(string $absPath): ?array
    {
        $absPath = $this->normalise($absPath);
        if ($absPath === null) {
            return null;
        }
        // ZFS dataset mount? (check most-specific first)
        foreach ($this->zfsMounts() as $mountpoint => $ds) {
            if ($absPath === $mountpoint || str_starts_with($absPath, $mountpoint . '/')) {
                $rel = substr($absPath, strlen($mountpoint));
                $rel = ltrim($rel, '/');
                return ['volume' => $mountpoint, 'fs' => 'zfs', 'relative' => $rel];
            }
        }
        // /mnt/diskN?
        if (preg_match('#^(/mnt/disk\d+)(/|$)#', $absPath, $m)) {
            $vol = $m[1];
            $rel = substr($absPath, strlen($vol));
            $rel = ltrim($rel, '/');
            return ['volume' => $vol, 'fs' => $this->fsType($vol), 'relative' => $rel];
        }
        return null;
    }

    public function isInScope(string $absPath): bool
    {
        return $this->resolveVolume($absPath) !== null;
    }

    /**
     * Compute the recycle bin path for a given absolute source path.
     */
    public function recycleRoot(string $absPath): ?string
    {
        $vol = $this->resolveVolume($absPath);
        if ($vol === null) {
            return null;
        }
        return rtrim($vol['volume'], '/') . '/' . self::RECYCLE_NAME;
    }

    /**
     * Return total and used bytes for a volume.
     *
     * @return array{total:int,used:int}
     */
    public function volumeStats(string $volume): array
    {
        if (isset($this->statCache[$volume])) {
            return $this->statCache[$volume];
        }
        $total = @disk_total_space($volume) ?: 0;
        $free  = @disk_free_space($volume) ?: 0;
        return $this->statCache[$volume] = [
            'total' => (int) $total,
            'used'  => (int) max(0, $total - $free),
        ];
    }

    /**
     * Total size occupied by a directory tree, in bytes. Used by capacity
     * eviction. Cached for the lifetime of the request.
     */
    public function dirSize(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $bytes = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $entry) {
            if ($entry->isFile()) {
                $bytes += (int) $entry->getSize();
            }
        }
        return $bytes;
    }

    /**
     * Whether two paths live on the same filesystem (so rename() is atomic).
     */
    public function sameFilesystem(string $a, string $b): bool
    {
        $sa = @stat(dirname($a));
        $sb = @stat(dirname($b));
        if ($sa === false || $sb === false) {
            return false;
        }
        return ($sa['dev'] ?? -1) === ($sb['dev'] ?? -2);
    }

    /**
     * Canonicalise an absolute path. Returns null on traversal escape or
     * when the result is not absolute.
     */
    public function normalise(string $path): ?string
    {
        if ($path === '') {
            return null;
        }
        // Strip NULs and other nasties.
        $path = str_replace(chr(0), '', $path);
        // If the file exists, realpath is the cheapest correct answer.
        $real = realpath($path);
        if ($real !== false) {
            return $real;
        }
        // Source may not exist anymore (e.g. after deletion). Reconstruct
        // manually using lexically-resolved components.
        if ($path[0] !== '/') {
            $path = getcwd() . '/' . $path;
        }
        $parts = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                if ($parts === []) {
                    return null; // traversal escape
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $seg;
        }
        return '/' . implode('/', $parts);
    }

    /**
     * Cached filesystem type detection. Falls back to 'unknown'.
     */
    private function fsType(string $volume): string
    {
        $out = $this->exec(['df', '-PT', $volume]);
        if ($out === null) {
            return 'unknown';
        }
        $lines = explode("\n", trim($out));
        if (count($lines) < 2) {
            return 'unknown';
        }
        $cols = preg_split('/\s+/', $lines[1]);
        return $cols[1] ?? 'unknown';
    }

    /**
     * Build and cache the ZFS dataset mountpoint -> dataset name map.
     *
     * @return array<string,string>
     */
    private function zfsMounts(): array
    {
        if ($this->zfsMap !== null) {
            return $this->zfsMap;
        }
        $this->zfsMap = [];
        $out = $this->exec(['zfs', 'list', '-H', '-o', 'mountpoint,name', '-t', 'filesystem']);
        if ($out === null) {
            return $this->zfsMap;
        }
        foreach (explode("\n", trim($out)) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = preg_split('/\s+/', $line);
            if (count($parts) < 2) {
                continue;
            }
            [$mountpoint, $name] = $parts;
            if ($mountpoint && $mountpoint !== '-' && str_starts_with($mountpoint, '/mnt/')) {
                $this->zfsMap[$mountpoint] = $name;
            }
        }
        // Sort by longest mountpoint first so the most specific dataset wins.
        uksort($this->zfsMap, fn($a, $b) => strlen($b) <=> strlen($a));
        return $this->zfsMap;
    }

    /**
     * Return ZFS dataset mountpoints that live under /mnt, suitable to expose
     * to the front-end for scope classification.
     *
     * @return list<string>
     */
    public function zfsRootsForFrontend(): array
    {
        return array_keys($this->zfsMounts());
    }

    /**
     * Safely exec a command without shell interpolation.
     *
     * @param string[] $argv
     */
    private function exec(array $argv): ?string
    {
        if (!$this->commandExists($argv[0])) {
            return null;
        }
        $cmd = implode(' ', array_map('escapeshellarg', $argv)) . ' 2>/dev/null';
        $out = @shell_exec($cmd);
        return $out === null ? null : $out;
    }

    private function commandExists(string $name): bool
    {
        $name = escapeshellarg($name);
        $out = @shell_exec('command -v ' . $name . ' 2>/dev/null');
        return $out !== null && trim($out) !== '';
    }
}
