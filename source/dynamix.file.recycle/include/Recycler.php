<?php
/** Conservative, same-filesystem recycle implementation. */

declare(strict_types=1);

namespace DynamixFileRecycle;

final class Recycler
{
    public function __construct(
        private FsInspector $fs,
        private History $history,
        private Logger $logger,
        private Config $cfg,
        private Security $security,
        private OperationLock $lock
    ) {}

    public function inspect(string $absPath): array
    {
        $trace = bin2hex(random_bytes(6));
        $this->logger->debug('inspect_start', $absPath, 'trace=' . $trace);
        $canonical = $this->security->assertPathInScope($absPath);
        $volume = $this->fs->resolveVolume($canonical);
        $stat = @lstat($canonical);
        if ($volume === null || $stat === false) {
            throw new \RuntimeException('The selected item could not be inspected.', 409);
        }
        $this->assertVolumeAllowed($volume['volume']);
        $this->logger->debug(
            'inspect_ready',
            $canonical,
            'trace=' . $trace . ' volume=' . $volume['volume'] . ' fs=' . $volume['fs']
                . ' relative=' . $volume['relative'] . ' dev=' . (int) $stat['dev'] . ' ino=' . (int) $stat['ino']
        );
        $recycleRoot = rtrim($volume['volume'], '/') . '/' . FsInspector::RECYCLE_NAME;
        $relativeDirectory = dirname((string) $volume['relative']);
        $recycleDirectory = $recycleRoot
            . ($relativeDirectory === '.' ? '' : '/' . $relativeDirectory);
        return [
            'ok' => true,
            'path' => $canonical,
            'name' => basename($canonical),
            'volume' => $volume['volume'],
            'filesystem' => $volume['fs'],
            'is_dir' => (((int) $stat['mode']) & 0170000) === 0040000,
            'size' => (int) $stat['size'],
            'recycle_directory' => $recycleDirectory,
            'inspection_token' => $this->security->issueInspectionToken($canonical),
        ];
    }

    public function recycle(string $absPath, string $inspectionToken): RecycleResult
    {
        return $this->lock->run(function () use ($absPath, $inspectionToken): RecycleResult {
            $trace = bin2hex(random_bytes(6));
            $stage = 'verify_token';
            $canonical = $absPath;
            $this->logger->debug('recycle_start', $absPath, 'trace=' . $trace . ' token_bytes=' . strlen($inspectionToken));
            try {
            $canonical = $this->security->verifyInspectionToken($inspectionToken, $absPath);
            $stage = 'resolve_dataset';
            $volume = $this->fs->resolveVolume($canonical);
            $stat = @lstat($canonical);
            if ($volume === null || $stat === false) {
                throw new \RuntimeException('The selected item changed before recycling.', 409);
            }
            $this->assertVolumeAllowed($volume['volume']);
            $this->logger->debug(
                'recycle_resolved',
                $canonical,
                'trace=' . $trace . ' volume=' . $volume['volume'] . ' fs=' . $volume['fs']
                    . ' relative=' . $volume['relative'] . ' source_dev=' . (int) $stat['dev']
            );

            $isDir = ((((int) $stat['mode']) & 0170000) === 0040000);
            $meta = [
                'size' => $isDir ? $this->fs->dirSize($canonical) : (int) $stat['size'],
                'owner_uid' => (int) $stat['uid'],
                'owner_gid' => (int) $stat['gid'],
                'mode' => (int) ($stat['mode'] & 07777),
                'mtime' => (int) $stat['mtime'],
            ];
            $id = History::newId();
            $recycleRoot = $volume['volume'] . '/' . FsInspector::RECYCLE_NAME;
            $relative = (string) $volume['relative'];
            $relativeDir = dirname($relative);
            $destDir = $recycleRoot . ($relativeDir === '.' ? '' : '/' . $relativeDir);

            // Opening the shard creates and validates the top-level recycle
            // directory before any item is moved.
            $stage = 'history_open';
            $this->logger->debug('recycle_history_open', $canonical, 'trace=' . $trace . ' root=' . $recycleRoot);
            $this->history->databaseForVolume($volume['volume'], true);
            $stage = 'destination_prepare';
            $this->ensureDestinationDirectory($destDir, $recycleRoot);
            $dest = $destDir . '/' . basename($canonical) . '.__recycle_' . $id;
            if (file_exists($dest) || is_link($dest)) {
                throw new \RuntimeException('The generated recycle destination already exists.', 409);
            }
            $stage = 'filesystem_check';
            $sourceParentStat = @stat(dirname($canonical));
            $destParentStat = @stat(dirname($dest));
            $this->logger->debug(
                'recycle_filesystem_check',
                $canonical,
                'trace=' . $trace . ' destination=' . $dest
                    . ' source_parent_dev=' . (int) ($sourceParentStat['dev'] ?? -1)
                    . ' destination_parent_dev=' . (int) ($destParentStat['dev'] ?? -1)
            );
            if (!$this->fs->sameFilesystem($canonical, $dest)) {
                throw new \RuntimeException('Cross-filesystem recycling is not supported in this release.', 409);
            }

            $stage = 'history_insert';
            $this->history->insertItem([
                'id' => $id,
                'volume' => $volume['volume'],
                'recycle_path' => $dest,
                'original_path' => $canonical,
                'size' => $meta['size'],
                'is_dir' => $isDir ? 1 : 0,
                'owner_uid' => $meta['owner_uid'],
                'owner_gid' => $meta['owner_gid'],
                'mode' => $meta['mode'],
                'mtime' => $meta['mtime'],
                'deleted_at' => time(),
                'state' => 'pending',
                'meta_json' => json_encode(['fs' => $volume['fs']], JSON_UNESCAPED_SLASHES),
            ]);
            $this->logger->debug('recycle_pending_recorded', $canonical, 'trace=' . $trace . ' id=' . $id . ' destination=' . $dest);

            $stage = 'rename';
            error_clear_last();
            if (!@rename($canonical, $dest)) {
                $renameError = error_get_last();
                $this->history->deletePending($id, $volume['volume']);
                throw new \RuntimeException(
                    'The atomic move into the recycle bin failed.'
                        . (is_array($renameError) && isset($renameError['message']) ? ' ' . $renameError['message'] : ''),
                    500
                );
            }
            $this->logger->debug('recycle_renamed', $canonical, 'trace=' . $trace . ' id=' . $id . ' destination=' . $dest);
            $stage = 'history_finalize';
            if (!$this->history->markActive($id, $volume['volume'])) {
                $this->logger->error('recycle_finalize', $dest, 'item moved but pending database row could not be finalized');
                throw new \RuntimeException('The item was moved, but its recovery record needs automatic repair. Do not alter the recycle bin.', 500);
            }

            if ($this->cfg->getPreserveMetadata()) {
                @chmod($dest, $meta['mode']);
                @chown($dest, $meta['owner_uid']);
                @chgrp($dest, $meta['owner_gid']);
                @touch($dest, $meta['mtime']);
            }
            $this->history->recordEvent($volume['volume'], 'AUDIT', 'recycle', $canonical, 'moved atomically to ' . $dest);
            $this->logger->info('recycle', $canonical, 'moved atomically to ' . $dest);
            return RecycleResult::ok($id, $canonical, $dest, $meta['size'], $isDir);
            } catch (\Throwable $e) {
                $this->logger->error(
                    'recycle_failed',
                    $canonical,
                    'trace=' . $trace . ' stage=' . $stage . ' type=' . get_class($e)
                        . ' file=' . $e->getFile() . ':' . $e->getLine() . ' message=' . $e->getMessage()
                );
                throw $e;
            }
        });
    }

    private function ensureDestinationDirectory(string $destDir, string $recycleRoot): void
    {
        $root = realpath($recycleRoot);
        if ($root === false || is_link($recycleRoot)) {
            throw new \RuntimeException('The recycle root is not a safe ordinary directory.', 409);
        }
        $relative = ltrim(substr($destDir, strlen($recycleRoot)), '/');
        $current = $root;
        if ($relative !== '') {
            foreach (explode('/', $relative) as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..') {
                    throw new \RuntimeException('Unsafe recycle destination component.', 409);
                }
                $current .= '/' . $segment;
                if (is_link($current)) {
                    throw new \RuntimeException('A recycle destination directory is a symbolic link.', 409);
                }
                if (!is_dir($current) && (!@mkdir($current, 0700, false) || !is_dir($current))) {
                    throw new \RuntimeException('Unable to create the recycle destination directory.', 500);
                }
            }
        }
        $resolved = realpath($current);
        if ($resolved === false || ($resolved !== $root && !str_starts_with($resolved, $root . '/'))) {
            throw new \RuntimeException('The recycle destination escaped its owning volume.', 409);
        }
    }

    private function assertVolumeAllowed(string $volume): void
    {
        if (!$this->cfg->isVolumeAllowed($volume)) {
            throw new \RuntimeException(
                'Recycle management is disabled for this disk or ZFS dataset. Enable it in the plugin settings and try again.',
                409
            );
        }
    }
}
