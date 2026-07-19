<?php
/**
 * Per-volume SQLite history store.
 *
 * Each supported disk or ZFS dataset owns its authoritative database at:
 *   <volume>/.RecycleBin/.dynamix-file-recycle.sqlite
 *
 * There is no central database to copy or rebuild. Global views enumerate the
 * currently online supported volumes and merge their query results.
 */

declare(strict_types=1);

namespace DynamixFileRecycle;

final class History
{
    public const DB_NAME = '.dynamix-file-recycle.sqlite';

    private FsInspector $fs;
    private Config $config;
    private Logger $logger;

    /** @var array<string,\PDO> */
    private array $connections = [];

    public function __construct(FsInspector $fs, Config $config, Logger $logger)
    {
        $this->fs = $fs;
        $this->config = $config;
        $this->logger = $logger;
    }

    public function initSchema(): void
    {
        foreach ($this->managedVolumes() as $volume) {
            $this->pdoForVolume($volume, false);
        }
    }

    public function dbFile(string $volume): string
    {
        return rtrim($volume, '/') . '/' . FsInspector::RECYCLE_NAME . '/' . self::DB_NAME;
    }

    public function pdoForVolume(string $volume, bool $create = true): ?\PDO
    {
        $canonical = $this->fs->normalise($volume);
        if ($canonical === null || !$this->fs->isApprovedVolumeRoot($canonical)) {
            throw new \RuntimeException('History database volume is not an approved disk or ZFS dataset.', 400);
        }
        if (isset($this->connections[$canonical])) {
            return $this->connections[$canonical];
        }

        $root = $canonical . '/' . FsInspector::RECYCLE_NAME;
        $dbFile = $root . '/' . self::DB_NAME;
        if (!$create && !is_file($dbFile)) {
            return null;
        }
        if (is_link($root) || is_link($dbFile)) {
            throw new \RuntimeException('Recycle history path must not be a symbolic link.', 409);
        }
        if (!is_dir($root) && (!@mkdir($root, 0700, false) || !is_dir($root))) {
            throw new \RuntimeException('Unable to create the per-volume recycle directory.', 500);
        }
        @chmod($root, 0700);

        $pdo = new \PDO('sqlite:' . $dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA synchronous=FULL');
        $pdo->exec('PRAGMA foreign_keys=ON');
        $pdo->exec('PRAGMA busy_timeout=5000');
        $pdo->exec($this->schema());
        $columns = $pdo->query('PRAGMA table_info(items)')->fetchAll();
        $columnNames = array_column($columns, 'name');
        if (!in_array('operation_target', $columnNames, true)) {
            $pdo->exec('ALTER TABLE items ADD COLUMN operation_target TEXT');
        }
        @chmod($dbFile, 0600);
        $this->connections[$canonical] = $pdo;
        $this->recoverPending($canonical, $pdo);
        return $pdo;
    }

    public function insertItem(array $data): string
    {
        $id = (string) ($data['id'] ?? self::newId());
        $pdo = $this->pdoForVolume((string) $data['volume']);
        $stmt = $pdo->prepare(
            'INSERT INTO items
             (id, volume, recycle_path, original_path, size, is_dir, owner_uid,
              owner_gid, mode, mtime, deleted_at, state, meta_json)
             VALUES
             (:id, :volume, :recycle_path, :original_path, :size, :is_dir,
              :owner_uid, :owner_gid, :mode, :mtime, :deleted_at, :state, :meta_json)'
        );
        $stmt->execute([
            ':id' => $id,
            ':volume' => $data['volume'],
            ':recycle_path' => $data['recycle_path'],
            ':original_path' => $data['original_path'],
            ':size' => $data['size'] ?? 0,
            ':is_dir' => $data['is_dir'] ?? 0,
            ':owner_uid' => $data['owner_uid'] ?? null,
            ':owner_gid' => $data['owner_gid'] ?? null,
            ':mode' => $data['mode'] ?? null,
            ':mtime' => $data['mtime'] ?? null,
            ':deleted_at' => $data['deleted_at'] ?? time(),
            ':state' => $data['state'] ?? 'active',
            ':meta_json' => $data['meta_json'] ?? null,
        ]);
        return $id;
    }

    public function findById(string $id): ?array
    {
        if (!self::validId($id)) {
            return null;
        }
        foreach ($this->existingVolumes() as $volume) {
            $pdo = $this->pdoForVolume($volume, false);
            if ($pdo === null) {
                continue;
            }
            $stmt = $pdo->prepare('SELECT * FROM items WHERE id=:id LIMIT 1');
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch();
            if ($row !== false) {
                return $row;
            }
        }
        return null;
    }

    public function markRestored(string $id): bool
    {
        return $this->transition($id, 'restored', 'restore', ['restoring', 'active']);
    }

    public function markActive(string $id, string $volume): bool
    {
        $pdo = $this->pdoForVolume($volume, false);
        if ($pdo === null) return false;
        $stmt = $pdo->prepare("UPDATE items SET state='active' WHERE id=:id AND state='pending'");
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function deletePending(string $id, string $volume): void
    {
        $pdo = $this->pdoForVolume($volume, false);
        if ($pdo === null) return;
        $stmt = $pdo->prepare("DELETE FROM items WHERE id=:id AND state='pending'");
        $stmt->execute([':id' => $id]);
    }

    public function markPurged(string $id, string $reason): bool
    {
        return $this->transition($id, 'purged', $reason, ['purging', 'active']);
    }

    public function beginTransition(string $id, string $from, string $to, string $target): bool
    {
        $row = $this->findById($id);
        if ($row === null || $row['state'] !== $from) return false;
        $pdo = $this->pdoForVolume((string) $row['volume'], false);
        if ($pdo === null) return false;
        $stmt = $pdo->prepare(
            'UPDATE items SET state=:to,operation_target=:target WHERE id=:id AND state=:from'
        );
        $stmt->execute([':to' => $to, ':target' => $target, ':id' => $id, ':from' => $from]);
        return $stmt->rowCount() > 0;
    }

    public function rollbackTransition(string $id, string $from, string $to): void
    {
        $row = $this->findById($id);
        if ($row === null) return;
        $pdo = $this->pdoForVolume((string) $row['volume'], false);
        if ($pdo === null) return;
        $stmt = $pdo->prepare(
            'UPDATE items SET state=:to,operation_target=NULL WHERE id=:id AND state=:from'
        );
        $stmt->execute([':to' => $to, ':id' => $id, ':from' => $from]);
    }

    public function listActive(?string $volume = null, int $limit = 500, int $offset = 0): array
    {
        return $this->queryAcross("state='active'", $volume, $limit, $offset);
    }

    public function listAll(?string $volume = null, int $limit = 1000, int $offset = 0): array
    {
        return $this->queryAcross('1=1', $volume, $limit, $offset);
    }

    public function listActiveBefore(int $cutoff): array
    {
        $rows = [];
        foreach ($this->managedVolumes() as $volume) {
            $pdo = $this->pdoForVolume($volume, false);
            if ($pdo === null) continue;
            $stmt = $pdo->prepare("SELECT * FROM items WHERE state='active' AND deleted_at<:cutoff");
            $stmt->execute([':cutoff' => $cutoff]);
            array_push($rows, ...$stmt->fetchAll());
        }
        return $rows;
    }

    public function listByState(string $state): array
    {
        if (!in_array($state, ['pending', 'active', 'restoring', 'restored', 'purging', 'purged'], true)) {
            return [];
        }
        $rows = [];
        foreach ($this->managedVolumes() as $volume) {
            $pdo = $this->pdoForVolume($volume, false);
            if ($pdo === null) continue;
            $stmt = $pdo->prepare('SELECT * FROM items WHERE state=:state ORDER BY deleted_at ASC');
            $stmt->execute([':state' => $state]);
            array_push($rows, ...$stmt->fetchAll());
        }
        return $rows;
    }

    public function listOldestActive(string $volume, int $limit = 100): array
    {
        $pdo = $this->pdoForVolume($volume, false);
        if ($pdo === null) return [];
        $stmt = $pdo->prepare(
            "SELECT * FROM items WHERE state='active' ORDER BY deleted_at ASC LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function countActive(?string $volume = null): int
    {
        return $this->aggregate('COUNT(*)', $volume);
    }

    public function countAll(): int
    {
        return $this->aggregate('COUNT(*)', null, '1=1');
    }

    public function totalActiveSize(?string $volume = null): int
    {
        return $this->aggregate('COALESCE(SUM(size),0)', $volume);
    }

    public function pruneLog(int $days): int
    {
        if ($days <= 0) return 0;
        $cutoff = time() - $days * 86400;
        $count = 0;
        foreach ($this->managedVolumes() as $volume) {
            $pdo = $this->pdoForVolume($volume, false);
            if ($pdo === null) continue;
            $stmt = $pdo->prepare('DELETE FROM events WHERE ts<:cutoff');
            $stmt->execute([':cutoff' => $cutoff]);
            $count += $stmt->rowCount();
        }
        return $count;
    }

    public function pruneHistory(int $days): int
    {
        if ($days <= 0) return 0;
        $cutoff = time() - $days * 86400;
        $count = 0;
        foreach ($this->managedVolumes() as $volume) {
            $pdo = $this->pdoForVolume($volume, false);
            if ($pdo === null) continue;
            $stmt = $pdo->prepare(
                "DELETE FROM items WHERE state IN ('restored','purged')
                 AND COALESCE(purged_at,deleted_at)<:cutoff"
            );
            $stmt->execute([':cutoff' => $cutoff]);
            $count += $stmt->rowCount();
        }
        return $count;
    }

    /** Delete operation-event logs from every online history shard. */
    public function clearEvents(): int
    {
        $count = 0;
        foreach ($this->existingVolumes() as $volume) {
            $pdo = $this->pdoForVolume($volume, false);
            if ($pdo === null) continue;
            $count += $pdo->exec('DELETE FROM events');
        }
        return $count;
    }

    /**
     * Delete audit-only item rows. Active and transitional records are kept so
     * no recoverable content becomes detached from its database identity.
     */
    public function clearInactiveHistory(): int
    {
        $count = 0;
        foreach ($this->existingVolumes() as $volume) {
            $pdo = $this->pdoForVolume($volume, false);
            if ($pdo === null) continue;
            $count += $pdo->exec("DELETE FROM items WHERE state IN ('restored','purged')");
        }
        return $count;
    }

    public function recordEvent(string $volume, string $level, string $action, string $path, string $message): void
    {
        try {
            $pdo = $this->pdoForVolume($volume, true);
            $stmt = $pdo->prepare(
                'INSERT INTO events(ts,level,action,path,message) VALUES(:ts,:level,:action,:path,:message)'
            );
            $stmt->execute([
                ':ts' => time(), ':level' => $level, ':action' => $action,
                ':path' => $path, ':message' => $message,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('history_event', $path, $e->getMessage());
        }
    }

    public function vacuum(): void
    {
        foreach ($this->managedVolumes() as $volume) {
            $this->pdoForVolume($volume, false)?->exec('VACUUM');
        }
    }

    /** @return list<string> */
    public function existingVolumes(): array
    {
        return array_values(array_filter(
            $this->fs->supportedVolumes(),
            fn(string $volume): bool => is_file($this->dbFile($volume)) && !is_link($this->dbFile($volume))
        ));
    }

    /** @return list<string> */
    public function managedVolumes(): array
    {
        return array_values(array_filter(
            $this->existingVolumes(),
            fn(string $volume): bool => $this->config->isVolumeAllowed($volume)
        ));
    }

    public static function newId(): string
    {
        $hex = bin2hex(random_bytes(16));
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3)
            . '-' . dechex((hexdec($hex[16]) & 0x3) | 0x8) . substr($hex, 17, 3)
            . '-' . substr($hex, 20, 12);
    }

    public static function validId(string $id): bool
    {
        return preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $id) === 1;
    }

    /** @param list<string> $fromStates */
    private function transition(string $id, string $state, string $reason, array $fromStates): bool
    {
        $row = $this->findById($id);
        if ($row === null || !in_array($row['state'], $fromStates, true)) return false;
        $pdo = $this->pdoForVolume((string) $row['volume'], false);
        if ($pdo === null) return false;
        if (!$this->config->getHistoryEnabled()) {
            $stmt = $pdo->prepare('DELETE FROM items WHERE id=:id');
            $stmt->execute([':id' => $id]);
            return $stmt->rowCount() > 0;
        }
        $stmt = $pdo->prepare(
            'UPDATE items SET state=:state,purged_at=:ts,purged_reason=:reason,operation_target=NULL
             WHERE id=:id'
        );
        $stmt->execute([':state' => $state, ':ts' => time(), ':reason' => $reason, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    private function queryAcross(string $where, ?string $volume, int $limit, int $offset): array
    {
        $limit = max(1, min(5000, $limit));
        $offset = max(0, $offset);
        $volumes = $volume !== null ? [$volume] : $this->existingVolumes();
        $rows = [];
        foreach ($volumes as $candidate) {
            $pdo = $this->pdoForVolume($candidate, false);
            if ($pdo === null) continue;
            $stmt = $pdo->prepare("SELECT * FROM items WHERE $where ORDER BY deleted_at DESC LIMIT :limit");
            $stmt->bindValue(':limit', $limit + $offset, \PDO::PARAM_INT);
            $stmt->execute();
            array_push($rows, ...$stmt->fetchAll());
        }
        usort($rows, fn(array $a, array $b): int => ((int) $b['deleted_at']) <=> ((int) $a['deleted_at']));
        return array_slice($rows, $offset, $limit);
    }

    private function aggregate(string $expression, ?string $volume, string $where = "state='active'"): int
    {
        $sum = 0;
        $volumes = $volume !== null ? [$volume] : $this->existingVolumes();
        foreach ($volumes as $candidate) {
            $pdo = $this->pdoForVolume($candidate, false);
            if ($pdo !== null) {
                $sum += (int) $pdo->query("SELECT $expression FROM items WHERE $where")->fetchColumn();
            }
        }
        return $sum;
    }

    private function schema(): string
    {
        return <<<'SQL'
CREATE TABLE IF NOT EXISTS items (
  id TEXT PRIMARY KEY,
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
  operation_target TEXT,
  meta_json TEXT
);
CREATE INDEX IF NOT EXISTS idx_items_state_deleted ON items(state,deleted_at);
CREATE TABLE IF NOT EXISTS events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ts INTEGER NOT NULL,
  level TEXT NOT NULL,
  action TEXT,
  path TEXT,
  message TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_events_ts ON events(ts);
CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY,v TEXT);
INSERT OR IGNORE INTO meta(k,v) VALUES('schema_version','2');
SQL;
    }

    private function recoverPending(string $volume, \PDO $pdo): void
    {
        foreach ($pdo->query("SELECT * FROM items WHERE state='pending'")->fetchAll() as $row) {
            $sourceExists = file_exists((string) $row['original_path']) || is_link((string) $row['original_path']);
            $destExists = file_exists((string) $row['recycle_path']) || is_link((string) $row['recycle_path']);
            if (!$sourceExists && $destExists) {
                $this->markActive((string) $row['id'], $volume);
                $this->recordEvent($volume, 'WARN', 'recover', (string) $row['recycle_path'], 'finalized pending recycle after interruption');
            } elseif ($sourceExists && !$destExists) {
                $this->deletePending((string) $row['id'], $volume);
            } else {
                $this->recordEvent($volume, 'ERROR', 'recover', (string) $row['recycle_path'], 'ambiguous pending recycle requires manual review');
            }
        }
        foreach ($pdo->query("SELECT * FROM items WHERE state IN ('restoring','purging')")->fetchAll() as $row) {
            $sourceExists = file_exists((string) $row['recycle_path']) || is_link((string) $row['recycle_path']);
            $target = (string) ($row['operation_target'] ?? '');
            $targetExists = $target !== '' && (file_exists($target) || is_link($target));
            if ($row['state'] === 'restoring') {
                if (!$sourceExists && $targetExists) {
                    $this->markRestored((string) $row['id']);
                } elseif ($sourceExists && !$targetExists) {
                    $this->rollbackTransition((string) $row['id'], 'restoring', 'active');
                } else {
                    $this->recordEvent($volume, 'ERROR', 'recover', $target, 'ambiguous restore requires manual review');
                }
            } elseif ($row['state'] === 'purging') {
                if (!$targetExists && !$sourceExists) {
                    $this->markPurged((string) $row['id'], 'recovered_purge');
                } elseif ($targetExists) {
                    $this->recordEvent($volume, 'WARN', 'recover', $target, 'interrupted purge will resume during maintenance');
                } elseif ($sourceExists) {
                    $this->rollbackTransition((string) $row['id'], 'purging', 'active');
                }
            }
        }
    }
}
