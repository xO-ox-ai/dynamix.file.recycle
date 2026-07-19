<?php
/**
 * Restorer.php — move an item from the recycle bin back to its original path.
 *
 *   1. Look up the item row by id or token (must be state='active').
 *   2. Validate the recycle_path still exists.
 *   3. Compute the original_path. If something now occupies it, append
 *      "__restored_<ts>" suffix to the basename (never overwrite).
 *   4. Move (rename if same FS, copy+rm otherwise).
 *   5. Re-apply owner/group/mode from the recorded metadata.
 *   6. Mark the item state='restored' in history.
 */

declare(strict_types=1);

namespace DynamixFileRecycle;

final class Restorer
{
    private FsInspector $fs;
    private History $history;
    private Logger $logger;
    private Config $cfg;

    public function __construct(FsInspector $fs, History $history, Logger $logger, Config $cfg)
    {
        $this->fs = $fs;
        $this->history = $history;
        $this->logger = $logger;
        $this->cfg = $cfg;
    }

    public function restore(int $itemId): array
    {
        $row = $this->history->findById($itemId);
        if ($row === null) {
            return ['ok' => false, 'error' => 'Item not found.'];
        }
        if ($row['state'] !== 'active') {
            return ['ok' => false, 'error' => 'Item is not in the recycle bin (state=' . $row['state'] . ').'];
        }
        $src = $this->fs->normalise($row['recycle_path']);
        if ($src === null || !file_exists($src)) {
            // Recycle file is gone without a DB update. Mark it purged.
            $this->history->markPurged($itemId, 'manual');
            return ['ok' => false, 'error' => 'Recycle copy is missing on disk; history marked purged.'];
        }

        $original = $this->fs->normalise($row['original_path']);
        if ($original === null) {
            return ['ok' => false, 'error' => 'Original path is invalid.'];
        }

        // Ensure original parent dir exists.
        $parent = dirname($original);
        if (!is_dir($parent)) {
            if (!@mkdir($parent, 0755, true) && !is_dir($parent)) {
                return ['ok' => false, 'error' => 'Cannot recreate original parent directory: ' . $parent];
            }
        }

        // Conflict resolution: never overwrite.
        $dest = $original;
        if (file_exists($dest)) {
            $dir  = dirname($original);
            $base = basename($original);
            $dot  = strrpos($base, '.');
            $name = $dot === false || $dot === 0 ? $base : substr($base, 0, $dot);
            $ext  = $dot === false || $dot === 0 ? ''  : substr($base, $dot);
            $dest = $dir . '/' . $name . '__restored_' . date('Ymd_His') . $ext;
        }

        // Move.
        if ($this->fs->sameFilesystem($src, $dest)) {
            if (!@rename($src, $dest)) {
                return ['ok' => false, 'error' => 'rename() failed during restore.'];
            }
        } else {
            $copyErr = $this->recursiveCopy($src, $dest);
            if ($copyErr !== null) {
                return ['ok' => false, 'error' => $copyErr];
            }
            $this->recursiveRemove($src);
        }

        // Re-apply metadata.
        if ($this->cfg->getPreserveMetadata()) {
            $mode = $row['mode'] !== null ? (octdec((string) $row['mode']) & 07777) : null;
            if ($mode !== null) {
                @chmod($dest, $mode);
            }
            if ($row['owner_uid'] !== null) {
                @chown($dest, (int) $row['owner_uid']);
            }
            if ($row['owner_gid'] !== null) {
                @chgrp($dest, (int) $row['owner_gid']);
            }
            if ($row['mtime'] !== null) {
                @touch($dest, (int) $row['mtime']);
            }
        }

        $this->history->markRestored($itemId);
        $this->logger->info('restore', $src, 'restored to ' . $dest);
        return [
            'ok'      => true,
            'restored_to' => $dest,
            'conflict' => $dest !== $original,
        ];
    }

    /**
     * Standalone recursive copy used during restore. Factored out so we don't
     * depend on the Recycler instance (which is built per-request).
     */
    private function recursiveCopy(string $src, string $dst): ?string
    {
        if (is_dir($src)) {
            if (!is_dir($dst) && !@mkdir($dst, 0700, true) && !is_dir($dst)) {
                return 'mkdir failed: ' . $dst;
            }
            foreach (new \FilesystemIterator($src, \FilesystemIterator::SKIP_DOTS) as $entry) {
                $err = $this->recursiveCopy($entry->getPathname(), $dst . '/' . $entry->getFilename());
                if ($err !== null) {
                    return $err;
                }
            }
            return null;
        }
        if (is_link($src)) {
            $target = @readlink($src);
            return $target === false || !@symlink($target, $dst)
                ? 'symlink failed: ' . $dst
                : null;
        }
        return @copy($src, $dst) ? null : ('copy failed: ' . $src . ' -> ' . $dst);
    }

    private function recursiveRemove(string $path): void
    {
        if (is_link($path)) {
            @unlink($path);
            return;
        }
        if (is_dir($path)) {
            foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $entry) {
                $this->recursiveRemove($entry->getPathname());
            }
            @rmdir($path);
            return;
        }
        @unlink($path);
    }
}
