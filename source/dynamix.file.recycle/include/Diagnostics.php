<?php
/** Build a bounded, temporary diagnostic archive for support analysis. */

declare(strict_types=1);

namespace DynamixFileRecycle;

final class Diagnostics
{
    public function __construct(
        private Config $config,
        private FsInspector $fs,
        private History $history,
        private Logger $logger
    ) {}

    /** @return array{path:string,filename:string,size:int} */
    public function create(): array
    {
        $id = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6));
        $stage = RUN_DIR . '/diagnostics-' . $id;
        $archive = RUN_DIR . '/dynamix-file-recycle-diagnostics-' . $id . '.tar.gz';
        if (!@mkdir($stage, 0700, true) && !is_dir($stage)) {
            throw new \RuntimeException('Unable to create the diagnostics staging directory.', 500);
        }

        try {
            $this->copyIfRegular(LOG_FILE, $stage . '/logs/runtime.log');
            $this->copyIfRegular(LOG_FILE . '.1', $stage . '/logs/runtime.log.1');
            $this->copyIfRegular(AUDIT_FILE, $stage . '/logs/audit.log');
            $this->copyIfRegular(AUDIT_FILE . '.1', $stage . '/logs/audit.log.1');
            $this->copyIfRegular(CFG_FILE, $stage . '/config/dynamix.file.recycle.cfg');
            $this->copyIfRegular('/var/local/emhttp/disks.ini', $stage . '/system/disks.ini');
            $this->copyIfRegular('/proc/self/mountinfo', $stage . '/system/mountinfo.txt');

            $commands = [
                'zfs-list.txt' => ['zfs', 'list', '-H', '-p', '-o', 'name,mountpoint,used,available,refer'],
                'zpool-status.txt' => ['zpool', 'status', '-P'],
                'findmnt.txt' => ['findmnt', '-rn', '-o', 'TARGET,SOURCE,FSTYPE,OPTIONS'],
                'df.txt' => ['df', '-PT'],
                'lsblk.txt' => ['lsblk', '-P', '-o', 'NAME,KNAME,PATH,TYPE,TRAN,RM,FSTYPE,MOUNTPOINTS'],
                'processes.txt' => ['ps', '-eo', 'pid,ppid,state,lstart,comm,args'],
                'syslog-tail.txt' => ['tail', '-n', '2500', '/var/log/syslog'],
                'dmesg-warnings.txt' => ['dmesg', '--ctime', '--level=err,warn'],
                'php-modules.txt' => ['/usr/bin/php', '-m'],
                'php-ini.txt' => ['/usr/bin/php', '--ini'],
                'sqlite-version.txt' => ['sqlite3', '--version'],
            ];
            foreach ($commands as $name => $argv) {
                $this->captureCommand($stage . '/system/' . $name, $argv);
            }

            $manifest = [
                'schema_version' => 1,
                'generated_at' => gmdate('c'),
                'plugin_version' => is_file(ROOT . '/VERSION') ? trim((string) file_get_contents(ROOT . '/VERSION')) : 'unknown',
                'php_version' => PHP_VERSION,
                'php_binary' => PHP_BINARY,
                'pdo_drivers' => \PDO::getAvailableDrivers(),
                'sqlite3_extension_loaded' => extension_loaded('sqlite3'),
                'sqlite_backend' => 'sqlite3-cli',
                'sqlite_binary' => SqliteConnection::findBinary(),
                'uname' => php_uname('a'),
                'config' => $this->config->raw(),
                'volumes' => $this->volumeSnapshot($stage),
            ];
            $this->writeFile(
                $stage . '/manifest.json',
                (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
            );

            $tar = is_executable('/bin/tar') ? '/bin/tar' : '/usr/bin/tar';
            if (!is_executable($tar)) {
                throw new \RuntimeException('The tar utility is unavailable.', 503);
            }
            $output = [];
            $exit = 1;
            $command = escapeshellarg($tar) . ' -czf ' . escapeshellarg($archive)
                . ' -C ' . escapeshellarg($stage) . ' . 2>&1';
            @exec($command, $output, $exit);
            clearstatcache(true, $archive);
            $size = is_file($archive) ? @filesize($archive) : false;
            if ($exit !== 0 || $size === false || $size < 20 || $size > 67108864 || is_link($archive)) {
                @unlink($archive);
                throw new \RuntimeException('Unable to create a valid diagnostics archive: ' . implode(' ', $output), 503);
            }
            $this->logger->debug('diagnostics_created', '', 'archive_bytes=' . $size);
            return ['path' => $archive, 'filename' => basename($archive), 'size' => (int) $size];
        } finally {
            $this->removeTree($stage);
        }
    }

    /** @return list<array<string,mixed>> */
    private function volumeSnapshot(string $stage): array
    {
        $out = [];
        try {
            $volumes = $this->fs->supportedVolumes();
        } catch (\Throwable $e) {
            return [['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]];
        }
        foreach ($volumes as $index => $volume) {
            $root = $volume . '/' . FsInspector::RECYCLE_NAME;
            $db = $this->history->dbFile($volume);
            $entry = [
                'volume' => $volume,
                'resolved' => $this->fs->resolveVolume($volume),
                'management_enabled' => $this->config->isVolumeAllowed($volume),
                'recycle_root_exists' => is_dir($root) && !is_link($root),
                'database_exists' => is_file($db) && !is_link($db),
                'database_bytes' => is_file($db) ? (int) @filesize($db) : 0,
                'root_stat' => $this->statSummary($root),
                'database_stat' => $this->statSummary($db),
                'recycle_entries' => $this->directorySnapshot($root),
            ];
            if ($entry['database_exists']) {
                try {
                    $pdo = $this->history->databaseForVolume($volume, false);
                    if ($pdo === null) throw new \RuntimeException('SQLite database disappeared during diagnostics.');
                    $entry['integrity_check'] = $pdo->query('PRAGMA integrity_check')->fetchColumn();
                    $entry['state_counts'] = $pdo->query('SELECT state,COUNT(*) AS count FROM items GROUP BY state')->fetchAll();
                    $entry['recent_items'] = $pdo->query(
                        'SELECT id,volume,original_path,display_name,recycle_path,size,is_dir,deleted_at,state,purged_at,purged_reason,operation_target '
                        . 'FROM items ORDER BY deleted_at DESC LIMIT 100'
                    )->fetchAll();
                } catch (\Throwable $e) {
                    $entry['database_error'] = $e->getMessage();
                }
            }
            $out[] = $entry;
            $this->writeFile(
                $stage . '/volumes/volume-' . sprintf('%03d', $index + 1) . '.json',
                (string) json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
            );
        }
        return $out;
    }

    /** @return array<string,int|string>|null */
    private function statSummary(string $path): ?array
    {
        $stat = @lstat($path);
        if ($stat === false) return null;
        return [
            'dev' => (int) $stat['dev'],
            'ino' => (int) $stat['ino'],
            'mode' => sprintf('%04o', ((int) $stat['mode']) & 07777),
            'uid' => (int) $stat['uid'],
            'gid' => (int) $stat['gid'],
            'size' => (int) $stat['size'],
            'mtime' => (int) $stat['mtime'],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function directorySnapshot(string $root): array
    {
        if (!is_dir($root) || is_link($root)) return [];
        $rows = [];
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            $iterator->setMaxDepth(5);
            foreach ($iterator as $entry) {
                if (count($rows) >= 500) {
                    $rows[] = ['truncated' => true];
                    break;
                }
                $path = $entry->getPathname();
                $rows[] = [
                    'relative_path' => ltrim(substr($path, strlen($root)), '/'),
                    'type' => $entry->isLink() ? 'link' : ($entry->isDir() ? 'directory' : 'file'),
                    'size' => $entry->isFile() ? (int) $entry->getSize() : 0,
                    'mtime' => (int) $entry->getMTime(),
                ];
            }
        } catch (\Throwable $e) {
            $rows[] = ['error' => $e->getMessage()];
        }
        return $rows;
    }

    /** @param list<string> $argv */
    private function captureCommand(string $destination, array $argv): void
    {
        $command = implode(' ', array_map('escapeshellarg', $argv)) . ' 2>&1';
        $lines = [];
        $exit = 127;
        @exec($command, $lines, $exit);
        $text = 'exit_code=' . $exit . "\n" . implode("\n", $lines) . "\n";
        if (strlen($text) > 4194304) {
            $text = substr($text, 0, 4194304) . "\n[truncated]\n";
        }
        $this->writeFile($destination, $text);
    }

    private function copyIfRegular(string $source, string $destination): void
    {
        if (!is_file($source) || is_link($source)) return;
        $dir = dirname($destination);
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        if (!@copy($source, $destination)) {
            throw new \RuntimeException('Unable to copy diagnostic source: ' . $source, 500);
        }
        @chmod($destination, 0600);
    }

    private function writeFile(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create diagnostics subdirectory.', 500);
        }
        if (@file_put_contents($path, $content, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write diagnostics file.', 500);
        }
        @chmod($path, 0600);
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) return;
        if (is_link($path) || !is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $entry) {
            $this->removeTree($entry->getPathname());
        }
        @rmdir($path);
    }
}
