<?php
/**
 * RecycleResult.php — value object returned by Recycler::recycle().
 *
 * The $ok flag follows a three-state convention:
 *
 *   - $ok === true                     success, file moved
 *   - $ok === false && needConfirm     cross-fs move detected; caller MUST ask
 *                                      the user and re-call with confirm=1
 *   - $ok === false && !needConfirm    hard error
 */

declare(strict_types=1);

namespace DynamixFileRecycle;

final class RecycleResult
{
    public bool $ok;
    public ?string $error;
    public ?int $itemId;
    public ?string $itemToken;
    public ?string $originalPath;
    public ?string $recyclePath;
    public int $size;
    public bool $isDir;

    /** @var bool True when this result represents a "needs user confirmation" precheck response. */
    public bool $needConfirm = false;
    public ?string $destPath = null;
    public ?string $volume = null;

    private function __construct(
        bool $ok,
        ?string $error = null,
        ?int $itemId = null,
        ?string $itemToken = null,
        ?string $originalPath = null,
        ?string $recyclePath = null,
        int $size = 0,
        bool $isDir = false
    ) {
        $this->ok = $ok;
        $this->error = $error;
        $this->itemId = $itemId;
        $this->itemToken = $itemToken;
        $this->originalPath = $originalPath;
        $this->recyclePath = $recyclePath;
        $this->size = $size;
        $this->isDir = $isDir;
    }

    public static function ok(
        int $itemId,
        string $itemToken,
        string $originalPath,
        string $recyclePath,
        int $size,
        bool $isDir
    ): self {
        return new self(true, null, $itemId, $itemToken, $originalPath, $recyclePath, $size, $isDir);
    }

    public static function error(string $message): self
    {
        return new self(false, $message);
    }

    /**
     * Build a "needs confirmation" result. The file has NOT been moved yet;
     * the caller is expected to ask the user and re-issue the call with
     * confirm=1.
     */
    public static function needConfirm(
        string $path,
        string $dest,
        int $size,
        bool $isDir,
        string $volume
    ): self {
        $r = new self(
            ok: false,
            error: null,
            originalPath: $path,
            size: $size,
            isDir: $isDir
        );
        $r->needConfirm = true;
        $r->destPath = $dest;
        $r->volume = $volume;
        return $r;
    }

    public function toArray(): array
    {
        $base = [
            'ok'            => $this->ok,
            'error'         => $this->error,
            'item_id'       => $this->itemId,
            'item_token'    => $this->itemToken,
            'original_path' => $this->originalPath,
            'recycle_path'  => $this->recyclePath,
            'size'          => $this->size,
            'is_dir'        => $this->isDir,
        ];
        if ($this->needConfirm) {
            $base['need_confirm'] = true;
            $base['cross_fs']     = true;
            $base['dest_path']    = $this->destPath;
            $base['volume']       = $this->volume;
            $base['code']         = 'need_confirm';
        }
        return $base;
    }
}

