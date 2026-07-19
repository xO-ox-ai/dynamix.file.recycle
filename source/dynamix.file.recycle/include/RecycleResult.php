<?php
/**
 * RecycleResult.php — value object returned by Recycler::recycle().
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

    public function toArray(): array
    {
        return [
            'ok'            => $this->ok,
            'error'         => $this->error,
            'item_id'       => $this->itemId,
            'item_token'    => $this->itemToken,
            'original_path' => $this->originalPath,
            'recycle_path'  => $this->recyclePath,
            'size'          => $this->size,
            'is_dir'        => $this->isDir,
        ];
    }
}
