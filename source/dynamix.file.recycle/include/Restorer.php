<?php
/** Same-filesystem restore implementation for per-volume recycle shards. */

declare(strict_types=1);

namespace DynamixFileRecycle;

final class Restorer
{
    public function __construct(
        private FsInspector $fs,
        private History $history,
        private Logger $logger,
        private Config $cfg,
        private Security $security,
        private OperationLock $lock
    ) {}

    public function restore(string $itemId): array
    {
        return $this->lock->run(function () use ($itemId): array {
            $row = $this->history->findById($itemId);
            if ($row === null) {
                throw new \RuntimeException('Recycle item was not found on any online supported volume.', 404);
            }
            if ($row['state'] !== 'active') {
                throw new \RuntimeException('Only active recycle items can be restored.', 409);
            }
            $volume = (string) $row['volume'];
            if (!$this->cfg->isVolumeAllowed($volume)) {
                throw new \RuntimeException('Recycle management is disabled for this disk or ZFS dataset. Enable it in the plugin settings and try again.', 409);
            }
            $src = $this->security->assertRecyclePath((string) $row['recycle_path'], $volume);
            if (!file_exists($src) || is_link($src)) {
                throw new \RuntimeException('The recycle item is missing or is no longer an ordinary file or directory.', 409);
            }

            $original = $this->fs->lexicalNormalise((string) $row['original_path']);
            if ($original === null || $this->fs->unsupportedPathReason($original) !== null) {
                throw new \RuntimeException('The stored original path is no longer supported.', 409);
            }
            $resolved = $this->fs->resolveVolume($original);
            if ($resolved === null || $resolved['volume'] !== $volume || $original === $volume) {
                throw new \RuntimeException('Restore refused because the original path no longer belongs to the same volume or dataset.', 409);
            }

            $parent = dirname($original);
            $this->ensureParentDirectory($parent, $volume);
            $dest = $this->availableRestorePath($original);
            if (!$this->fs->sameFilesystem($src, $dest)) {
                throw new \RuntimeException('Cross-filesystem restore is not supported in this release.', 409);
            }
            if (!$this->history->beginTransition($itemId, 'active', 'restoring', $dest)) {
                throw new \RuntimeException('The recycle item state changed before restore could begin.', 409);
            }
            if (!@rename($src, $dest)) {
                $this->history->rollbackTransition($itemId, 'restoring', 'active');
                throw new \RuntimeException('The atomic restore move failed.', 500);
            }
            if (!$this->history->markRestored($itemId)) {
                $this->logger->error('restore_finalize', $dest, 'item restored but database state could not be finalized');
                throw new \RuntimeException('The item was restored, but its audit state needs automatic repair.', 500);
            }

            if ($this->cfg->getPreserveMetadata()) {
                if ($row['mode'] !== null) @chmod($dest, ((int) $row['mode']) & 07777);
                if ($row['owner_uid'] !== null) @chown($dest, (int) $row['owner_uid']);
                if ($row['owner_gid'] !== null) @chgrp($dest, (int) $row['owner_gid']);
                if ($row['mtime'] !== null) @touch($dest, (int) $row['mtime']);
            }
            $this->history->recordEvent($volume, 'AUDIT', 'restore', $src, 'restored atomically to ' . $dest);
            $this->logger->info('restore', $src, 'restored atomically to ' . $dest);
            return ['ok' => true, 'restored_to' => $dest, 'conflict' => $dest !== $original];
        });
    }

    private function ensureParentDirectory(string $parent, string $volume): void
    {
        if ($parent !== $volume && !str_starts_with($parent, $volume . '/')) {
            throw new \RuntimeException('Restore parent escaped the owning volume.', 409);
        }
        $relative = ltrim(substr($parent, strlen($volume)), '/');
        $current = $volume;
        foreach ($relative === '' ? [] : explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \RuntimeException('Restore parent contains an unsafe path component.', 409);
            }
            $current .= '/' . $segment;
            if (is_link($current)) {
                throw new \RuntimeException('Restore refused because a parent directory is a symbolic link.', 409);
            }
            if (!is_dir($current) && (!@mkdir($current, 0755, false) || !is_dir($current))) {
                throw new \RuntimeException('Unable to recreate the original parent directory.', 500);
            }
        }
        $resolved = realpath($current);
        if ($resolved === false || ($resolved !== $volume && !str_starts_with($resolved, $volume . '/'))) {
            throw new \RuntimeException('Restore parent escaped the owning volume.', 409);
        }
    }

    private function availableRestorePath(string $original): string
    {
        if (!file_exists($original) && !is_link($original)) return $original;
        $dir = dirname($original);
        $base = basename($original);
        for ($attempt = 0; $attempt < 32; $attempt++) {
            $candidate = $dir . '/' . $base . '.__restored_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(3));
            if (!file_exists($candidate) && !is_link($candidate)) return $candidate;
        }
        throw new \RuntimeException('Unable to allocate a non-conflicting restore path.', 409);
    }
}
