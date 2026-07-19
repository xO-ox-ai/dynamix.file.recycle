<?php
/**
 * Purger.php — permanently delete an item from the recycle bin.
 *
 * Two entry points:
 *   - purgeOne(int $itemId):   single item, manual
 *   - purgeByRow(array $row):  used by Maintenance for batch eviction
 *
 * Both remove the on-disk file/tree first, then mark the row purged in
 * history. If the on-disk copy is already gone, the row is still marked
 * purged so the history table is consistent with reality.
 */

declare(strict_types=1);

namespace DynamixFileRecycle;

final class Purger
{
    private FsInspector $fs;
    private History $history;
    private Logger $logger;

    public function __construct(FsInspector $fs, History $history, Logger $logger)
    {
        $this->fs = $fs;
        $this->history = $history;
        $this->logger = $logger;
    }

    public function purgeOne(int $itemId, string $reason = 'manual'): array
    {
        $row = $this->history->findById($itemId);
        if ($row === null) {
            return ['ok' => false, 'error' => 'Item not found.'];
        }
        return $this->purgeByRow($row, $reason);
    }

    /**
     * @param array $row  A row from the items table.
     */
    public function purgeByRow(array $row, string $reason = 'manual'): array
    {
        $path = $this->fs->normalise($row['recycle_path']);
        $removed = false;
        if ($path !== null && file_exists($path)) {
            $err = $this->recursiveRemove($path);
            if ($err !== null) {
                $this->logger->error('purge', $path, 'failed: ' . $err);
                return ['ok' => false, 'error' => $err];
            }
            $removed = true;
        }
        // Update history row regardless: if state isn't 'active' (e.g. already
        // restored) we leave it alone.
        if (($row['state'] ?? '') === 'active') {
            $this->history->markPurged((int) $row['id'], $reason);
        }
        $this->logger->info(
            'purge',
            $path ?? '',
            sprintf('reason=%s removed=%d', $reason, $removed ? 1 : 0)
        );
        return ['ok' => true, 'removed_on_disk' => $removed, 'reason' => $reason];
    }

    /**
     * Empty an entire .RecycleBin directory for a given volume. Used by the
     * optional auto-empty cron. Returns counts.
     */
    public function emptyVolume(string $volumeRoot): array
    {
        $recycleDir = rtrim($volumeRoot, '/') . '/' . FsInspector::RECYCLE_NAME;
        $count = 0;
        $bytes = 0;
        if (is_dir($recycleDir)) {
            foreach ($this->history->listActive($volumeRoot) as $row) {
                $bytes += (int) $row['size'];
                $this->purgeByRow($row, 'auto_empty');
                $count++;
            }
            // Sweep the directory for stray files (rows without history).
            foreach (new \FilesystemIterator($recycleDir, \FilesystemIterator::SKIP_DOTS) as $entry) {
                $this->recursiveRemove($entry->getPathname());
            }
        }
        return ['ok' => true, 'count' => $count, 'bytes' => $bytes];
    }

    private function recursiveRemove(string $path): ?string
    {
        if (is_link($path)) {
            return @unlink($path) ? null : ('unlink symlink failed: ' . $path);
        }
        if (is_dir($path)) {
            foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $entry) {
                $err = $this->recursiveRemove($entry->getPathname());
                if ($err !== null) {
                    return $err;
                }
            }
            return @rmdir($path) ? null : ('rmdir failed: ' . $path);
        }
        return @unlink($path) ? null : ('unlink failed: ' . $path);
    }
}
