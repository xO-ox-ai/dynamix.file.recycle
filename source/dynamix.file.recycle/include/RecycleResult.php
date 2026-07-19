<?php

declare(strict_types=1);

namespace DynamixFileRecycle;

final class RecycleResult
{
    private function __construct(
        public bool $ok,
        public ?string $error = null,
        public ?string $itemId = null,
        public ?string $originalPath = null,
        public ?string $recyclePath = null,
        public int $size = 0,
        public bool $isDir = false
    ) {}

    public static function ok(
        string $itemId,
        string $originalPath,
        string $recyclePath,
        int $size,
        bool $isDir
    ): self {
        return new self(true, null, $itemId, $originalPath, $recyclePath, $size, $isDir);
    }

    public static function error(string $message): self
    {
        return new self(false, $message);
    }

    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'error' => $this->error,
            'item_id' => $this->itemId,
            'original_path' => $this->originalPath,
            'recycle_path' => $this->recyclePath,
            'size' => $this->size,
            'is_dir' => $this->isDir,
        ];
    }
}
