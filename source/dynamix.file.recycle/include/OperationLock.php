<?php

declare(strict_types=1);

namespace DynamixFileRecycle;

final class OperationLock
{
    /** @var resource|null */
    private $handle = null;
    private int $depth = 0;

    public function run(callable $operation): mixed
    {
        if ($this->depth > 0) {
            $this->depth++;
            try {
                return $operation();
            } finally {
                $this->depth--;
            }
        }

        if (!is_dir(RUN_DIR) && !@mkdir(RUN_DIR, 0700, true) && !is_dir(RUN_DIR)) {
            throw new \RuntimeException('Unable to create the operation lock directory.', 500);
        }
        $handle = @fopen(RUN_DIR . '/operation.lock', 'c+');
        if ($handle === false || !@flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) fclose($handle);
            throw new \RuntimeException('Another recycle-bin operation is active. Wait for it to finish and try again.', 409);
        }
        $this->handle = $handle;
        $this->depth = 1;
        try {
            return $operation();
        } finally {
            $this->depth = 0;
            @flock($handle, LOCK_UN);
            fclose($handle);
            $this->handle = null;
        }
    }
}
