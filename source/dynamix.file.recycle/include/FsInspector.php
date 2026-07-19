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
    /** @var array<string,string|null> */
    private array $topologyCache = [];

    public const RECYCLE_NAME = '.RecycleBin';
    /** @var list<string> */
    private const COMPLEX_ROOTS = [
        '/boot',
        '/mnt/user',
        '/mnt/user0',
        '/mnt/cache',
        '/mnt/disks',
        '/mnt/remotes',
        '/mnt/rootshare',
        '/mnt/addons',
    ];

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
        if ($this->unsupportedPathReason($absPath) !== null) {
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
     * Return a user-facing reason for known virtual, cache, remote or otherwise
     * complex roots that this conservative release intentionally rejects.
     */
    public function unsupportedPathReason(string $path): ?string
    {
        $lexical = $this->lexicalNormalise($path);
        if ($lexical === null) {
            return 'The path is not a valid absolute filesystem path.';
        }
        foreach (self::COMPLEX_ROOTS as $root) {
            if ($lexical === $root || str_starts_with($lexical, $root . '/')) {
                return match ($root) {
                    '/boot' => 'The Unraid boot device is system-managed and is never handled by this plugin.',
                    '/mnt/user', '/mnt/user0' => 'Unraid user-share paths are virtual and are not supported in this release. Browse the underlying /mnt/diskN path instead.',
                    '/mnt/cache' => 'Cache and pool paths are not supported in this release.',
                    '/mnt/disks' => 'Unassigned Devices and externally mounted paths are not supported in this release.',
                    '/mnt/remotes' => 'Remote filesystem paths are not supported in this release.',
                    default => 'This Unraid virtual or system-managed path is not supported in this release.',
                };
            }
        }
        if (preg_match('#^/mnt/cache[^/]*(?:/|$)#', $lexical)) {
            return 'Cache and pool paths are not supported in this release.';
        }
        return null;
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
        if ($path === '' || str_contains($path, chr(0))) {
            return null;
        }
        // If the file exists, realpath is the cheapest correct answer.
        $real = realpath($path);
        if ($real !== false) {
            return $real;
        }
        return $this->lexicalNormalise($path);
    }

    public function lexicalNormalise(string $path): ?string
    {
        if ($path === '' || str_contains($path, chr(0))) {
            return null;
        }
        if ($path[0] !== '/') {
            return null;
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

    /** @return list<string> */
    public function supportedVolumes(): array
    {
        $volumes = [];
        foreach (glob('/mnt/disk*', GLOB_ONLYDIR) ?: [] as $disk) {
            if (preg_match('#^/mnt/disk\d+$#', $disk)) {
                $volumes[$disk] = true;
            }
        }
        foreach (array_keys($this->zfsMounts()) as $mountpoint) {
            if ($this->unsupportedPathReason($mountpoint) === null) {
                $volumes[$mountpoint] = true;
            }
        }
        $approved = [];
        foreach (array_keys($volumes) as $volume) {
            $resolved = $this->resolveVolume($volume);
            if ($resolved !== null
                && $resolved['volume'] === $volume
                && $this->externalStorageReason($volume, $resolved['fs']) === null) {
                $approved[] = $volume;
            }
        }
        return $approved;
    }

    /**
     * Return stable display metadata without weakening the volume checks.
     * Array disks are grouped together; ZFS datasets retain their native
     * pool/dataset hierarchy even when mountpoint names differ.
     *
     * @return array{kind:string,label:string,hierarchy:list<string>}|null
     */
    public function volumeDisplayInfo(string $volume): ?array
    {
        $canonical = $this->normalise($volume);
        if ($canonical === null || !$this->isApprovedVolumeRoot($canonical)) {
            return null;
        }
        if (preg_match('#^/mnt/disk(\d+)$#', $canonical, $match)) {
            return [
                'kind' => 'array',
                'label' => 'Disk ' . $match[1],
                'hierarchy' => ['Disk ' . $match[1]],
            ];
        }
        $dataset = $this->zfsMounts()[$canonical] ?? '';
        if ($dataset === '') {
            return null;
        }
        $hierarchy = array_values(array_filter(explode('/', $dataset), 'strlen'));
        return [
            'kind' => 'zfs',
            'label' => end($hierarchy) ?: $dataset,
            'hierarchy' => $hierarchy,
        ];
    }

    public function isExactVolumeRoot(string $path): bool
    {
        $canonical = $this->normalise($path);
        if ($canonical === null) {
            return false;
        }
        $resolved = $this->resolveVolume($canonical);
        return $resolved !== null && $resolved['volume'] === $canonical;
    }

    public function isApprovedVolumeRoot(string $path): bool
    {
        $canonical = $this->normalise($path);
        if ($canonical === null || !$this->isExactVolumeRoot($canonical)) {
            return false;
        }
        $resolved = $this->resolveVolume($canonical);
        return $resolved !== null
            && $this->externalStorageReason($canonical, $resolved['fs']) === null;
    }

    /**
     * Return null only when every backing block device can be proven to be a
     * non-removable, non-USB disk. Unknown topology is intentionally rejected.
     */
    public function externalStorageReason(string $volume, string $filesystem): ?string
    {
        $key = $volume . '|' . $filesystem;
        if (array_key_exists($key, $this->topologyCache)) {
            return $this->topologyCache[$key];
        }
        $devices = [];
        if ($filesystem === 'zfs') {
            $dataset = $this->zfsMounts()[$volume] ?? '';
            $pool = explode('/', $dataset, 2)[0] ?? '';
            if ($pool === '') {
                return $this->topologyCache[$key] = 'The ZFS pool identity could not be verified.';
            }
            $status = $this->exec(['zpool', 'status', '-P', $pool]);
            if ($status === null) {
                return $this->topologyCache[$key] = 'The ZFS backing devices could not be inspected.';
            }
            foreach (explode("\n", $status) as $line) {
                if (preg_match('#(?:^|\s)(/dev/(?:disk/[^\s]+|[^\s]+))(?=\s|$)#', $line, $match)) {
                    $devices[$match[1]] = true;
                }
            }
        } else {
            $source = $this->mountSource($volume);
            if ($source !== null && str_starts_with($source, '/dev/')) {
                $devices[$source] = true;
            }
        }
        if ($devices === []) {
            return $this->topologyCache[$key] = 'The backing block-device topology could not be verified; removable and external storage are not supported.';
        }
        foreach (array_keys($devices) as $device) {
            $reason = $this->blockDeviceReason($device);
            if ($reason !== null) {
                return $this->topologyCache[$key] = $reason;
            }
        }
        return $this->topologyCache[$key] = null;
    }

    public function isInsideRecycleRoot(string $path, string $volume): bool
    {
        $canonical = $this->normalise($path);
        $volumeCanonical = $this->normalise($volume);
        if ($canonical === null || $volumeCanonical === null) {
            return false;
        }
        $root = $volumeCanonical . '/' . self::RECYCLE_NAME;
        return str_starts_with($canonical, $root . '/');
    }

    public function hasMountAtOrBelow(string $path): bool
    {
        $canonical = $this->normalise($path);
        if ($canonical === null || !is_readable('/proc/self/mountinfo')) {
            return true;
        }
        foreach (file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $fields = explode(' ', $line);
            if (count($fields) < 5) {
                continue;
            }
            $mount = str_replace(['\\040', '\\011', '\\134'], [' ', "\t", '\\'], $fields[4]);
            if ($mount === $canonical || str_starts_with($mount, $canonical . '/')) {
                return true;
            }
        }
        return false;
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
            if ($mountpoint && $mountpoint !== '-' && str_starts_with($mountpoint, '/mnt/')
                && $this->unsupportedPathReason($mountpoint) === null
                && !str_contains($mountpoint, '/' . self::RECYCLE_NAME)) {
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
        return array_values(array_filter(
            array_keys($this->zfsMounts()),
            fn(string $root): bool => $this->unsupportedPathReason($root) === null
        ));
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

    private function mountSource(string $mountpoint): ?string
    {
        if (!is_readable('/proc/self/mountinfo')) return null;
        foreach (file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $fields = explode(' ', $line);
            $separator = array_search('-', $fields, true);
            if ($separator === false || !isset($fields[4], $fields[$separator + 2])) continue;
            $mounted = str_replace(['\\040', '\\011', '\\134'], [' ', "\t", '\\'], $fields[4]);
            if ($mounted === $mountpoint) {
                return str_replace(['\\040', '\\011', '\\134'], [' ', "\t", '\\'], $fields[$separator + 2]);
            }
        }
        return null;
    }

    private function blockDeviceReason(string $device): ?string
    {
        $resolved = realpath($device);
        if ($resolved === false || !str_starts_with($resolved, '/dev/')) {
            return 'A backing device is not a verifiable local block device.';
        }
        $output = $this->exec(['lsblk', '-s', '-n', '-P', '-o', 'NAME,TYPE,TRAN,RM', $resolved]);
        if ($output === null) {
            return 'The backing block-device transport could not be inspected.';
        }
        $physical = 0;
        foreach (explode("\n", trim($output)) as $line) {
            if ($line === '') continue;
            $values = [];
            preg_match_all('/([A-Z]+)="((?:\\\\.|[^"])*)"/', $line, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $values[$match[1]] = stripcslashes($match[2]);
            }
            if (strtolower((string) ($values['TRAN'] ?? '')) === 'usb') {
                return 'USB-backed storage is not supported in this release.';
            }
            if ((string) ($values['RM'] ?? '') === '1') {
                return 'Removable storage is not supported in this release.';
            }
            if (($values['TYPE'] ?? '') === 'disk') {
                $physical++;
                $sysfs = realpath('/sys/class/block/' . basename((string) ($values['NAME'] ?? '')));
                if ($sysfs !== false && str_contains(strtolower($sysfs), '/usb')) {
                    return 'USB-backed storage is not supported in this release.';
                }
            }
        }
        return $physical > 0
            ? null
            : 'The physical backing disk could not be proven; external storage is not supported.';
    }
}
