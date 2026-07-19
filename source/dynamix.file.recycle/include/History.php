<?php
/**
 * History.php — SQLite-backed history of recycled items.
 *
 * Every recycle/restore/purge mutates the `items` table. The `state` field
 * lets the Tools -> Recycle Bin page distinguish:
 *   - active   : still sitting in .RecycleBin, can be restored
 *   - restored : user restored it
 *   - purged   : user or maintenance permanently deleted it
 *
 * When history recording is DISABLED in config, we still keep a row for the
 * duration of `active` state (it is the only way to know what's in the bin),
 * but purge it from the table on transition out of `active`.
 */

declare(strict_types=1);

namespace DynamixFileRecycle;

final class History
{
    private string $dbFile;
    private Logger $logger;
    private ?\PDO $pdo = null;

    public function __construct(string $dbFile, Logger $logger)
    {
        $this->dbFile = $dbFile;
        $this->logger = $logger;
    }

    /**
     * Create / migrate the schema. Idempotent.
     */
    public function initSchema(): void
    {
        $pdo = $this->pdo();
        $sql = is_file(SCHEMA_FILE) ? (string) file_get_contents(SCHEMA_FILE) : '';
        if ($sql !== '') {
            $pdo->exec($sql);
        } else {
            // Fallback inline schema if the .sql file is missing.
            $this->ensureInlineSchema($pdo);
        }
    }

    public function pdo(): \PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        $dir = dirname($this->dbFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous  = NORMAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo = $pdo;
        return $pdo;
    }

    public function insertItem(array $data): int
    {
        $pdo = $this->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO items
                (item_token, volume, recycle_path, original_path, size, is_dir,
                 owner_uid, owner_gid, mode, mtime, deleted_at, state, meta_json)
             VALUES
                (:item_token, :volume, :recycle_path, :original_path, :size, :is_dir,
                 :owner_uid, :owner_gid, :mode, :mtime, :deleted_at, :state, :meta_json)'
        );
        $stmt->execute([
            ':item_token'   => $data['item_token'],
            ':volume'       => $data['volume'],
            ':recycle_path' => $data['recycle_path'],
            ':original_path'=> $data['original_path'],
            ':size'         => $data['size'] ?? 0,
            ':is_dir'       => $data['is_dir'] ?? 0,
            ':owner_uid'    => $data['owner_uid'] ?? null,
            ':owner_gid'    => $data['owner_gid'] ?? null,
            ':mode'         => $data['mode'] ?? null,
            ':mtime'        => $data['mtime'] ?? null,
            ':deleted_at'   => $data['deleted_at'] ?? time(),
            ':state'        => 'active',
            ':meta_json'    => $data['meta_json'] ?? null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Mark an item as restored. Returns true if a row was updated.
     */
    public function markRestored(int $id): bool
    {
        $stmt = $this->pdo()->prepare(
            "UPDATE items
             SET state='restored', purged_at=:ts, purged_reason='restore'
             WHERE id=:id AND state='active'"
        );
        $stmt->execute([':id' => $id, ':ts' => time()]);
        return $stmt->rowCount() > 0;
    }

    public function markPurged(int $id, string $reason): bool
    {
        $stmt = $this->pdo()->prepare(
            "UPDATE items
             SET state='purged', purged_at=:ts, purged_reason=:reason
             WHERE id=:id AND state='active'"
        );
        $stmt->execute([':id' => $id, ':ts' => time(), ':reason' => $reason]);
        return $stmt->rowCount() > 0;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM items WHERE id=:id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findByToken(string $token): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM items WHERE item_token=:t LIMIT 1');
        $stmt->execute([':t' => $token]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Active items currently sitting in the bin.
     *
     * @return array<int,array>
     */
    public function listActive(?string $volume = null, int $limit = 500, int $offset = 0): array
    {
        $sql = "SELECT * FROM items WHERE state='active'";
        $args = [];
        if ($volume !== null) {
            $sql .= ' AND volume=:volume';
            $args[':volume'] = $volume;
        }
        $sql .= ' ORDER BY deleted_at DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo()->prepare($sql);
        foreach ($args as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * All items (active + historical). Used by Tools -> Recycle Bin when
     * history display is enabled.
     *
     * @return array<int,array>
     */
    public function listAll(?string $volume = null, int $limit = 1000, int $offset = 0): array
    {
        $sql = "SELECT * FROM items WHERE 1=1";
        $args = [];
        if ($volume !== null) {
            $sql .= ' AND volume=:volume';
            $args[':volume'] = $volume;
        }
        $sql .= ' ORDER BY deleted_at DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo()->prepare($sql);
        foreach ($args as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Oldest active items (for LRU capacity eviction).
     *
     * @return array<int,array>
     */
    public function listOldestActive(string $volume, int $limit = 100): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT * FROM items
             WHERE state='active' AND volume=:volume
             ORDER BY deleted_at ASC
             LIMIT :limit"
        );
        $stmt->bindValue(':volume', $volume);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function countActive(?string $volume = null): int
    {
        $sql = "SELECT COUNT(*) FROM items WHERE state='active'";
        $args = [];
        if ($volume !== null) {
            $sql .= ' AND volume=:volume';
            $args[':volume'] = $volume;
        }
        $stmt = $this->pdo()->prepare($sql);
        foreach ($args as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    public function countAll(): int
    {
        return (int) $this->pdo()->query('SELECT COUNT(*) FROM items')->fetchColumn();
    }

    public function totalActiveSize(?string $volume = null): int
    {
        $sql = "SELECT COALESCE(SUM(size),0) FROM items WHERE state='active'";
        $args = [];
        if ($volume !== null) {
            $sql .= ' AND volume=:volume';
            $args[':volume'] = $volume;
        }
        $stmt = $this->pdo()->prepare($sql);
        foreach ($args as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * Delete log rows older than $days. Used by Maintenance.
     */
    public function pruneLog(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }
        $cutoff = time() - $days * 86400;
        $stmt = $this->pdo()->prepare('DELETE FROM log WHERE ts < :cutoff');
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    /**
     * Drop historical (non-active) items older than $days. Used by Maintenance.
     */
    public function pruneHistory(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }
        $cutoff = time() - $days * 86400;
        $stmt = $this->pdo()->prepare(
            "DELETE FROM items WHERE state IN ('restored','purged') AND COALESCE(purged_at, deleted_at) < :cutoff"
        );
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    public function vacuum(): void
    {
        $this->pdo()->exec('VACUUM');
    }

    /**
     * Inline fallback schema if the shipped .sql is missing.
     */
    private function ensureInlineSchema(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                item_token TEXT NOT NULL UNIQUE,
                volume TEXT NOT NULL,
                recycle_path TEXT NOT NULL,
                original_path TEXT NOT NULL,
                size INTEGER NOT NULL DEFAULT 0,
                is_dir INTEGER NOT NULL DEFAULT 0,
                owner_uid INTEGER,
                owner_gid INTEGER,
                mode INTEGER,
                mtime INTEGER,
                deleted_at INTEGER NOT NULL,
                state TEXT NOT NULL DEFAULT 'active',
                purged_at INTEGER,
                purged_reason TEXT,
                meta_json TEXT
            );
            CREATE INDEX IF NOT EXISTS idx_items_volume_state ON items(volume, state);
            CREATE INDEX IF NOT EXISTS idx_items_deleted_at   ON items(deleted_at);
            CREATE INDEX IF NOT EXISTS idx_items_state        ON items(state);

            CREATE TABLE IF NOT EXISTS log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ts INTEGER NOT NULL,
                level TEXT NOT NULL,
                action TEXT,
                path TEXT,
                message TEXT NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_log_ts    ON log(ts);
            CREATE INDEX IF NOT EXISTS idx_log_level ON log(level);

            CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT);
            INSERT OR IGNORE INTO meta(k,v) VALUES('schema_version','1');"
        );
    }
}
