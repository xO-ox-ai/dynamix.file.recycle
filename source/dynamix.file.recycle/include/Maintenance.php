<?php
/** Per-volume maintenance coordinator. */

declare(strict_types=1);

namespace DynamixFileRecycle;

final class Maintenance
{
    public function __construct(
        private Config $cfg,
        private FsInspector $fs,
        private History $history,
        private Purger $purger,
        private Logger $logger,
        private OperationLock $lock
    ) {}

    public function run(bool $manual = false): array
    {
        return $this->lock->run(fn(): array => $this->runUnlocked($manual));
    }

    private function runUnlocked(bool $manual): array
    {
        $report = [
            'recovered' => 0, 'aged' => 0, 'capacity' => 0, 'logs' => 0,
            'history' => 0, 'emptied' => 0, 'skipped' => false,
        ];
        if (!$this->cfg->getEnabled()) {
            $report['skipped'] = true;
            return $report;
        }
        $scheduled = !$manual;
        if ($scheduled && trim($this->cfg->getAutoEmptyCron()) === '') {
            $report['skipped'] = true;
            return $report;
        }

        $report['recovered'] = $this->purger->resumeInterrupted();
        $ageDays = $this->cfg->getAgeDays();
        if ($ageDays > 0) {
            foreach ($this->history->listActiveBefore(time() - $ageDays * 86400) as $row) {
                if (!$this->cfg->isVolumeAllowed((string) $row['volume'])) continue;
                if (($this->purger->purgeByRow($row, 'age')['ok'] ?? false) === true) {
                    $report['aged']++;
                }
            }
        }
        $report['capacity'] = $this->evictByCapacity();
        if ($scheduled) {
            foreach ($this->history->managedVolumes() as $volume) {
                $result = $this->purger->emptyVolume($volume);
                $report['emptied'] += (int) ($result['count'] ?? 0);
            }
        }
        $report['logs'] = $this->history->pruneLog($this->cfg->getLogRetentionDays());
        $report['history'] = $this->history->pruneHistory($this->cfg->getHistoryRetentionDays());
        if ($this->cfg->getVacuumSqlite()) {
            try {
                $this->history->vacuum();
            } catch (\Throwable $e) {
                $this->logger->warn('maintain', '', 'VACUUM failed: ' . $e->getMessage());
            }
        }
        return $report;
    }

    private function evictByCapacity(): int
    {
        $mode = $this->cfg->getCapacityMode();
        $percent = $this->cfg->getCapacityPercent();
        $absoluteGb = $this->cfg->getCapacityAbsoluteGb();
        if (($mode === 'percent' && $percent <= 0) || ($mode === 'absolute' && $absoluteGb <= 0)) {
            return 0;
        }
        $count = 0;
        foreach ($this->history->managedVolumes() as $volume) {
            $root = $volume . '/' . FsInspector::RECYCLE_NAME;
            $stats = $this->fs->volumeStats($volume);
            $limit = $mode === 'percent'
                ? (int) floor($stats['total'] * ($percent / 100))
                : $absoluteGb * 1024 * 1024 * 1024;
            if ($limit <= 0) continue;
            $used = $this->fs->dirSize($root);
            if ($used <= $limit) continue;
            $target = (int) floor($limit * 0.9);
            foreach ($this->history->listOldestActive($volume, 5000) as $row) {
                if ($used <= $target) break;
                $result = $this->purger->purgeByRow($row, 'capacity');
                if (($result['ok'] ?? false) === true) {
                    $count++;
                    $used = max(0, $used - (int) $row['size']);
                }
            }
        }
        return $count;
    }

}
