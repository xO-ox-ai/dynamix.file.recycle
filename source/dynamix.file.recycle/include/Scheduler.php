<?php
/** Synchronize the user-selected cleanup schedule with Unraid's cron store. */

declare(strict_types=1);

namespace DynamixFileRecycle;

final class Scheduler
{
    public const CRON_FILE = CFG_DIR . '/dynamix.file.recycle.cron';

    public function __construct(
        private Config $config,
        private Security $security
    ) {}

    public function sync(): void
    {
        $expression = trim($this->config->getAutoEmptyCron());
        if (!$this->config->getEnabled() || $expression === '') {
            if (is_file(self::CRON_FILE) && !@unlink(self::CRON_FILE)) {
                throw new \RuntimeException('Unable to remove the disabled cleanup schedule.', 500);
            }
            $this->reloadUnraidCron();
            return;
        }

        // Reuse the HTTP configuration validator so a manually edited cfg can
        // never inject shell syntax into root's crontab.
        $clean = $this->security->sanitizeConfigPatch([
            'maintenance' => ['auto_empty_cron' => $expression],
        ]);
        $validated = (string) ($clean['maintenance']['auto_empty_cron'] ?? '');
        if ($validated !== $expression) {
            throw new \RuntimeException('The cleanup schedule could not be validated.', 400);
        }

        $line = $validated
            . ' /usr/local/emhttp/plugins/dynamix.file.recycle/scripts/recycle-maintain scheduled'
            . ' >/dev/null 2>&1' . "\n";
        $tmp = self::CRON_FILE . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $line, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write the cleanup schedule.', 500);
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, self::CRON_FILE)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to activate the cleanup schedule.', 500);
        }
        $this->reloadUnraidCron();
    }

    private function reloadUnraidCron(): void
    {
        $command = '/usr/local/sbin/update_cron';
        if (!is_executable($command)) {
            // Unit/development environments do not provide Unraid's helper.
            if (PHP_OS_FAMILY !== 'Linux') return;
            throw new \RuntimeException('Unraid update_cron is unavailable.', 500);
        }
        $output = [];
        $status = 0;
        @exec($command, $output, $status);
        if ($status !== 0) {
            throw new \RuntimeException('Unraid could not reload the cleanup schedule.', 500);
        }
    }
}
