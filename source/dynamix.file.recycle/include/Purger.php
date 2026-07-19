<?php
/** Permanent deletion limited to tracked items inside an approved recycle bin. */

declare(strict_types=1);

namespace DynamixFileRecycle;

final class Purger
{
    public function __construct(
        private FsInspector $fs,
        private History $history,
        private Logger $logger,
        private Config $cfg,
        private Security $security,
        private OperationLock $lock
    ) {}

    public function purgeOne(string $itemId, string $reason = 'manual'): array
    {
        return $this->lock->run(function () use ($itemId, $reason): array {
            $row = $this->history->findById($itemId);
            if ($row === null) {
                throw new \RuntimeException('Recycle item was not found on any online supported volume.', 404);
            }
            if ($row['state'] !== 'active') {
                throw new \RuntimeException('Only active recycle items can be permanently deleted.', 409);
            }
            $this->assertVolumeAllowed((string) $row['volume']);
            return $this->purgeByRowUnlocked($row, $reason);
        });
    }

    public function purgeByRow(array $row, string $reason = 'manual'): array
    {
        return $this->lock->run(fn(): array => $this->purgeByRowUnlocked($row, $reason));
    }

    public function emptyVolume(string $volumeRoot): array
    {
        return $this->lock->run(function () use ($volumeRoot): array {
            if (!$this->fs->isApprovedVolumeRoot($volumeRoot)) {
                throw new \RuntimeException('Only an exact supported disk or ZFS dataset root can be emptied.', 409);
            }
            $this->assertVolumeAllowed($volumeRoot);
            $count = 0;
            $bytes = 0;
            $failed = 0;
            foreach ($this->history->listActive($volumeRoot, 5000, 0) as $row) {
                $result = $this->purgeByRowUnlocked($row, 'empty');
                if ($result['ok']) {
                    $count++;
                    $bytes += (int) $row['size'];
                } else {
                    $failed++;
                }
            }
            return ['ok' => $failed === 0, 'count' => $count, 'bytes' => $bytes, 'failed' => $failed];
        });
    }

    public function resumeInterrupted(): int
    {
        return $this->lock->run(function (): int {
            $completed = 0;
            foreach ($this->history->listByState('purging') as $row) {
                $id = (string) $row['id'];
                $volume = (string) $row['volume'];
                if (!$this->cfg->isVolumeAllowed($volume)) continue;
                $target = (string) ($row['operation_target'] ?? '');
                try {
                    $target = $this->security->assertRecyclePath($target, $volume);
                } catch (\Throwable $e) {
                    $this->logger->error('purge_recover', $target, $e->getMessage());
                    continue;
                }
                if (is_dir($target) && !is_link($target) && $this->fs->hasMountAtOrBelow($target)) {
                    $this->logger->error('purge_recover', $target, 'recovery refused because the tombstone contains a mount point');
                    continue;
                }
                if ((file_exists($target) || is_link($target)) && $this->recursiveRemove($target) !== null) {
                    continue;
                }
                if (!file_exists($target) && !is_link($target)
                    && $this->history->markPurged($id, 'recovered_purge')) {
                    $completed++;
                }
            }
            return $completed;
        });
    }

    private function purgeByRowUnlocked(array $row, string $reason): array
    {
        $id = (string) ($row['id'] ?? '');
        if (!History::validId($id) || ($row['state'] ?? '') !== 'active') {
            return ['ok' => false, 'error' => 'The recycle record is not active or has an invalid identity.'];
        }
        $volume = (string) $row['volume'];
        if (!$this->cfg->isVolumeAllowed($volume)) {
            return ['ok' => false, 'error' => 'Recycle management is disabled for this disk or ZFS dataset.'];
        }
        try {
            $source = $this->security->assertRecyclePath((string) $row['recycle_path'], $volume);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        if (!file_exists($source) && !is_link($source)) {
            $this->history->markPurged($id, 'missing');
            return ['ok' => true, 'removed_on_disk' => false, 'reason' => 'missing'];
        }
        if (is_dir($source) && !is_link($source) && $this->fs->hasMountAtOrBelow($source)) {
            return ['ok' => false, 'error' => 'Purge refused because the recycle item now contains a mount point.'];
        }

        $tombstone = dirname($source) . '/.purging-' . $id;
        if (file_exists($tombstone) || is_link($tombstone)) {
            return ['ok' => false, 'error' => 'A previous purge tombstone already exists and requires recovery.'];
        }
        if (!$this->history->beginTransition($id, 'active', 'purging', $tombstone)) {
            return ['ok' => false, 'error' => 'The recycle item state changed before purge could begin.'];
        }
        if (!@rename($source, $tombstone)) {
            $this->history->rollbackTransition($id, 'purging', 'active');
            return ['ok' => false, 'error' => 'Unable to isolate the item for permanent deletion.'];
        }
        $error = $this->recursiveRemove($tombstone);
        if ($error !== null) {
            $this->logger->error('purge', $tombstone, $error);
            return ['ok' => false, 'error' => $error];
        }
        if (!$this->history->markPurged($id, $reason)) {
            $this->logger->error('purge_finalize', $tombstone, 'content removed but history state could not be finalized');
            return ['ok' => false, 'error' => 'Content was removed, but the audit state needs automatic repair.'];
        }
        $this->history->recordEvent($volume, 'AUDIT', 'purge', $source, 'reason=' . $reason);
        $this->logger->info('purge', $source, 'reason=' . $reason);
        return ['ok' => true, 'removed_on_disk' => true, 'reason' => $reason];
    }

    private function recursiveRemove(string $path): ?string
    {
        if (is_link($path)) {
            return @unlink($path) ? null : 'Unable to unlink a symbolic link inside the recycle item.';
        }
        if (is_dir($path)) {
            foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $entry) {
                $error = $this->recursiveRemove($entry->getPathname());
                if ($error !== null) return $error;
            }
            return @rmdir($path) ? null : 'Unable to remove a recycle directory.';
        }
        return @unlink($path) ? null : 'Unable to remove a recycle file.';
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
