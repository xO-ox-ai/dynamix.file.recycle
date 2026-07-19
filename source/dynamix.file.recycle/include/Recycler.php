<?php
/**
 * Recycler.php — move a file/folder into the volume's .RecycleBin.
 *
 * Algorithm:
 *   1. Security: normalise + assertPathInScope.
 *   2. Resolve volume + relative path.
 *   3. Create .RecycleBin/{relative_dir} with mode 0700.
 *   4. Determine the destination absolute path. If it exists, append
 *      "__<n>" suffix until free.
 *   5. Move:
 *      - same filesystem  -> rename() (atomic)
 *      - cross filesystem -> recursive copy + rm (only after copy succeeds)
 *   6. Stat the original (we already had stat() to detect existence) and
 *      persist owner/gid/mode/mtime into the items table so Restorer can
 *      restore them exactly.
 *   7. Return a RecycleResult describing the operation.
 *
 * The source path is removed in ALL success paths. In the cross-filesystem
 * path the original is only removed AFTER the recursive copy reports success,
 * so a partial copy never loses data.
 */

declare(strict_types=1);

namespace DynamixFileRecycle;

final class Recycler
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

    public function recycle(string $absPath, bool $confirmed = false): RecycleResult
    {
        $canonical = $this->fs->normalise($absPath);
        if ($canonical === null) {
            return RecycleResult::error('Invalid path.');
        }
        if (str_contains($canonical, '/' . FsInspector::RECYCLE_NAME . '/')
            || str_ends_with($canonical, '/' . FsInspector::RECYCLE_NAME)) {
            return RecycleResult::error('Cannot recycle an item already in the recycle bin.');
        }
        $vol = $this->fs->resolveVolume($canonical);
        if ($vol === null) {
            return RecycleResult::error(
                'Out of scope for v1: only /mnt/disk* and ZFS datasets are supported.'
            );
        }

        // Capture original metadata BEFORE moving.
        $stat = @lstat($canonical);
        if ($stat === false) {
            return RecycleResult::error('Source does not exist or is not accessible: ' . $canonical);
        }
        $isDir = is_dir($canonical);
        $originalMeta = [
            'size'      => $isDir ? $this->fs->dirSize($canonical) : (int) $stat['size'],
            'owner_uid' => (int) $stat['uid'],
            'owner_gid' => (int) $stat['gid'],
            'mode'      => (int) ($stat['mode'] & 07777),
            'mtime'     => (int) $stat['mtime'],
        ];

        $recycleRoot = rtrim($vol['volume'], '/') . '/' . FsInspector::RECYCLE_NAME;
        $relative = $vol['relative'];

        $destDir = $recycleRoot . '/' . dirname($relative);
        if ($destDir !== '' && !is_dir($destDir)) {
            if (!@mkdir($destDir, 0700, true) && !is_dir($destDir)) {
                return RecycleResult::error('Failed to create recycle bin directory: ' . $destDir);
            }
        }
        // Make sure the top-level .RecycleBin itself is 0700.
        @chmod($recycleRoot, 0700);

        $dest = $recycleRoot . '/' . ($relative === '' ? basename($canonical) : $relative);
        $dest = $this->resolveConflict($dest);

        // Cross-filesystem check. If we would need a copy+rm (slow, expensive,
        // and harder to abort), refuse the first call unless the caller has
        // explicitly confirmed. The front-end uses this to prompt the user.
        $sameFs = $this->fs->sameFilesystem($canonical, $dest);
        if (!$sameFs && !$confirmed) {
            return RecycleResult::needConfirm(
                path: $canonical,
                dest: $dest,
                size: $originalMeta['size'],
                isDir: $isDir,
                volume: $vol['volume']
            );
        }

        if ($sameFs) {
            if (!@rename($canonical, $dest)) {
                return RecycleResult::error('rename() failed: ' . $canonical . ' -> ' . $dest);
            }
        } else {
            $copyErr = $this->recursiveCopy($canonical, $dest);
            if ($copyErr !== null) {
                return RecycleResult::error($copyErr);
            }
            // Remove the original only after the copy succeeded.
            $rmErr = $this->recursiveRemove($canonical);
            if ($rmErr !== null) {
                // The recycle copy is in place; just log a warning.
                $this->logger->warn(
                    'recycle',
                    $canonical,
                    'Source removal failed after copy: ' . $rmErr
                );
            }
        }

        // Optionally re-apply original owner/group/mode on the destination.
        if ($this->cfg->getPreserveMetadata()) {
            $this->reapplyMetadata($dest, $originalMeta);
        }

        // Insert history row.
        $token = $this->makeToken($canonical, $dest);
        try {
            $id = $this->history->insertItem([
                'item_token'    => $token,
                'volume'        => $vol['volume'],
                'recycle_path'  => $dest,
                'original_path' => $canonical,
                'size'          => $originalMeta['size'],
                'is_dir'        => $isDir ? 1 : 0,
                'owner_uid'     => $originalMeta['owner_uid'],
                'owner_gid'     => $originalMeta['owner_gid'],
                'mode'          => $originalMeta['mode'],
                'mtime'         => $originalMeta['mtime'],
                'deleted_at'    => time(),
                'meta_json'     => json_encode(['fs' => $vol['fs'], 'cross_fs' => !$sameFs], JSON_UNESCAPED_SLASHES),
            ]);
        } catch (\Throwable $e) {
            // The file has already moved; downgrade history failure to warning.
            $this->logger->error('recycle', $canonical, 'history insert failed: ' . $e->getMessage());
            $id = 0;
        }

        $this->logger->info(
            'recycle',
            $canonical,
            sprintf(
                'moved to %s (volume=%s, cross_fs=%d, confirmed=%d)',
                $dest,
                $vol['volume'],
                $sameFs ? 0 : 1,
                $confirmed ? 1 : 0
            )
        );

        return RecycleResult::ok($id, $token, $canonical, $dest, $originalMeta['size'], $isDir);
    }

    /**
     * Append __1, __2, ... before the final extension until free.
     */
    private function resolveConflict(string $dest): string
    {
        if (!file_exists($dest)) {
            return $dest;
        }
        $dir  = dirname($dest);
        $base = basename($dest);
        $dot  = strrpos($base, '.');
        $name = $dot === false || $dot === 0 ? $base : substr($base, 0, $dot);
        $ext  = $dot === false || $dot === 0 ? ''  : substr($base, $dot);
        $i = 1;
        do {
            $candidate = $dir . '/' . $name . '__' . $i . $ext;
            $i++;
        } while (file_exists($candidate));
        return $candidate;
    }

    private function recursiveCopy(string $src, string $dst): ?string
    {
        if (is_dir($src)) {
            if (!is_dir($dst) && !@mkdir($dst, 0700, true) && !is_dir($dst)) {
                return 'mkdir failed: ' . $dst;
            }
            $it = new \FilesystemIterator($src, \FilesystemIterator::SKIP_DOTS);
            foreach ($it as $entry) {
                $err = $this->recursiveCopy($entry->getPathname(), $dst . '/' . $entry->getFilename());
                if ($err !== null) {
                    return $err;
                }
            }
            return null;
        }
        if (is_link($src)) {
            $target = @readlink($src);
            if ($target === false) {
                return 'readlink failed: ' . $src;
            }
            if (!@symlink($target, $dst)) {
                return 'symlink failed: ' . $dst;
            }
            return null;
        }
        if (!@copy($src, $dst)) {
            return 'copy failed: ' . $src . ' -> ' . $dst;
        }
        return null;
    }

    private function recursiveRemove(string $path): ?string
    {
        if (is_link($path)) {
            return @unlink($path) ? null : ('unlink symlink failed: ' . $path);
        }
        if (is_dir($path)) {
            $it = new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS);
            foreach ($it as $entry) {
                $err = $this->recursiveRemove($entry->getPathname());
                if ($err !== null) {
                    return $err;
                }
            }
            return @rmdir($path) ? null : ('rmdir failed: ' . $path);
        }
        return @unlink($path) ? null : ('unlink failed: ' . $path);
    }

    private function reapplyMetadata(string $dest, array $meta): void
    {
        if ($meta['mode'] !== null) {
            @chmod($dest, $meta['mode']);
        }
        if ($meta['owner_uid'] !== null && $meta['owner_gid'] !== null) {
            @chown($dest, $meta['owner_uid']);
            @chgrp($dest, $meta['owner_gid']);
        }
        if ($meta['mtime'] !== null) {
            @touch($dest, $meta['mtime']);
        }
    }

    private function makeToken(string $original, string $dest): string
    {
        return hash_hmac('sha256', $original . '|' . $dest, STATE_DIR . '|' . php_uname('n'));
    }
}
