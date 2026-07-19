<?php
/**
 * Maintenance.php — background maintenance orchestrator.
 *
 * Invoked by scripts/recycle-maintain (cron.hourly) which calls:
 *   php Bootstrap.php maintain
 *
 * Steps (all guarded by config thresholds so a disabled step is a cheap
 * no-op):
 *   1. Throttle: skip if last_run is within `interval_hours`.
 *   2. Age-based eviction: purge active items older than age_days.
 *   3. Capacity-based eviction (LRU): per volume, if .RecycleBin size exceeds
 *      threshold, purge oldest-first until under threshold.
 *   4. Optional auto-empty cron: if `auto_empty_cron` matches the current
 *      minute, empty every volume's bin.
 *   5. Log retention: prune log table + history table by retention_days.
 *   6. Optional SQLite VACUUM.
 *
 * Returns a small report array (also written to the log file by the caller).
 */

declare(strict_types=1);

namespace DynamixFileRecycle;

final class Maintenance
{
    private Config $cfg;
    private FsInspector $fs;
    private History $history;
    private Purger $purger;
    private Logger $logger;
    private string $metaKeyLastRun = 'maintenance_last_run';
    private string $metaKeyAutoEmpty = 'maintenance_auto_empty_last';

    public function __construct(
        Config $cfg,
        FsInspector $fs,
        History $history,
        Purger $purger,
        Logger $logger
    ) {
        $this->cfg = $cfg;
        $this->fs = $fs;
        $this->history = $history;
        $this->purger = $purger;
        $this->logger = $logger;
    }

    public function run(): array
    {
        $report = ['aged' => 0, 'capacity' => 0, 'logs' => 0, 'history' => 0, 'emptied' => 0, 'skipped' => false];

        if (!$this->cfg->getEnabled()) {
            $report['skipped'] = true;
            return $report;
        }

        // 1. Throttle.
        $intervalSecs = $this->cfg->getMaintenanceIntervalHours() * 3600;
        $last = (int) $this->metaGet($this->metaKeyLastRun, 0);
        if ($last !== 0 && (time() - $last) < $intervalSecs) {
            $report['skipped'] = true;
            return $report;
        }
        $this->metaSet($this->metaKeyLastRun, (string) time());

        // 2. Age eviction.
        $ageDays = $this->cfg->getAgeDays();
        if ($ageDays > 0) {
            $report['aged'] = $this->evictByAge($ageDays);
        }

        // 3. Capacity eviction.
        $report['capacity'] = $this->evictByCapacity();

        // 4. Auto-empty (if cron expression matches the current minute).
        if ($this->shouldAutoEmptyNow()) {
            $report['emptied'] = $this->autoEmptyAll();
            $this->metaSet($this->metaKeyAutoEmpty, (string) time());
        }

        // 5. Retention sweeps.
        $report['logs']    = $this->history->pruneLog($this->cfg->getLogRetentionDays());
        $report['history'] = $this->history->pruneHistory($this->cfg->getHistoryRetentionDays());

        // 6. Optional vacuum.
        if ($this->cfg->getVacuumSqlite()) {
            try {
                $this->history->vacuum();
            } catch (\Throwable $e) {
                $this->logger->warn('maintain', '', 'VACUUM failed: ' . $e->getMessage());
            }
        }

        return $report;
    }

    private function evictByAge(int $ageDays): int
    {
        $cutoff = time() - $ageDays * 86400;
        $pdo = $this->history->pdo();
        $stmt = $pdo->prepare(
            "SELECT * FROM items WHERE state='active' AND deleted_at < :cutoff"
        );
        $stmt->execute([':cutoff' => $cutoff]);
        $count = 0;
        foreach ($stmt->fetchAll() as $row) {
            $r = $this->purger->purgeByRow($row, 'age');
            if (!empty($r['ok'])) {
                $count++;
            }
        }
        return $count;
    }

    private function evictByCapacity(): int
    {
        $mode = $this->cfg->getCapacityMode();
        $percent = $this->cfg->getCapacityPercent();
        $absoluteGb = $this->cfg->getCapacityAbsoluteGb();
        if ($mode === 'percent' && $percent <= 0) {
            return 0;
        }
        if ($mode === 'absolute' && $absoluteGb <= 0) {
            return 0;
        }

        $volumes = $this->collectVolumes();
        $count = 0;
        foreach ($volumes as $vol) {
            $recycleDir = $vol . '/' . FsInspector::RECYCLE_NAME;
            if (!is_dir($recycleDir)) {
                continue;
            }
            $stats = $this->fs->volumeStats($vol);
            $limitBytes = $mode === 'percent'
                ? (int) floor($stats['total'] * ($percent / 100))
                : $absoluteGb * 1024 * 1024 * 1024;
            if ($limitBytes <= 0) {
                continue;
            }
            $used = $this->fs->dirSize($recycleDir);
            if ($used <= $limitBytes) {
                continue;
            }
            // Purge oldest active first until under 90% of the limit.
            $target = (int) floor($limitBytes * 0.9);
            $rows = $this->history->listOldestActive($vol, 500);
            foreach ($rows as $row) {
                if ($used <= $target) {
                    break;
                }
                $r = $this->purger->purgeByRow($row, 'capacity');
                if (!empty($r['ok'])) {
                    $count++;
                    $used -= (int) $row['size'];
                }
            }
        }
        return $count;
    }

    private function autoEmptyAll(): int
    {
        $count = 0;
        foreach ($this->collectVolumes() as $vol) {
            $r = $this->purger->emptyVolume($vol);
            $count += (int) ($r['count'] ?? 0);
        }
        return $count;
    }

    /**
     * Very small cron matcher supporting the 5-field UNIX cron syntax. It
     * matches the current local time at minute granularity. This is a best
     * effort: we only need to fire once per minute at most (cron.hourly won't
     * give finer resolution anyway).
     */
    private function shouldAutoEmptyNow(): bool
    {
        $expr = $this->cfg->getAutoEmptyCron();
        if ($expr === '' || $expr === '0') {
            return false;
        }
        $last = (int) $this->metaGet($this->metaKeyAutoEmpty, 0);
        // Never auto-empty more than once per hour.
        if ($last !== 0 && (time() - $last) < 3600) {
            return false;
        }
        $parts = preg_split('/\s+/', trim($expr));
        if ($parts === false || count($parts) !== 5) {
            return false;
        }
        [$min, $hour, $dom, $mon, $dow] = $parts;
        $now = getdate();
        return $this->cronMatch($min, $now['minutes'])
            && $this->cronMatch($hour, $now['hours'])
            && $this->cronMatch($dom, $now['mday'])
            && $this->cronMatch($mon, $now['mon'])
            && $this->cronMatch($dow, $now['wday']);
    }

    private function cronMatch(string $field, int $value): bool
    {
        if ($field === '*') {
            return true;
        }
        foreach (explode(',', $field) as $alt) {
            $alt = trim($alt);
            if ($alt === '*') {
                return true;
            }
            if (ctype_digit($alt) && (int) $alt === $value) {
                return true;
            }
            if (str_contains($alt, '/')) {
                [$base, $step] = explode('/', $alt, 2);
                if ($base === '*' || $base === '') {
                    if ($value % (int) $step === 0) {
                        return true;
                    }
                }
            }
            if (str_contains($alt, '-')) {
                [$lo, $hi] = explode('-', $alt, 2);
                if (ctype_digit($lo) && ctype_digit($hi) && $value >= (int) $lo && $value <= (int) $hi) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Collect every known volume that has at least one active item, plus
     * every /mnt/diskN directory on disk.
     *
     * @return list<string>
     */
    private function collectVolumes(): array
    {
        $vols = [];
        foreach (glob('/mnt/disk*', GLOB_ONLYDIR) as $d) {
            $vols[$d] = true;
        }
        // Add volumes referenced by items in case a disk is offline.
        $pdo = $this->history->pdo();
        foreach ($pdo->query("SELECT DISTINCT volume FROM items WHERE state='active'")->fetchAll() as $row) {
            $vols[$row['volume']] = true;
        }
        return array_keys($vols);
    }

    private function metaGet(string $k, int $fallback): int
    {
        $stmt = $this->history->pdo()->prepare('SELECT v FROM meta WHERE k=:k');
        $stmt->execute([':k' => $k]);
        $v = $stmt->fetchColumn();
        return $v === false ? $fallback : (int) $v;
    }

    private function metaSet(string $k, string $v): void
    {
        $stmt = $this->history->pdo()->prepare(
            'INSERT INTO meta(k,v) VALUES(:k,:v) ON CONFLICT(k) DO UPDATE SET v=:v2'
        );
        $stmt->execute([':k' => $k, ':v' => $v, ':v2' => $v]);
    }
}
