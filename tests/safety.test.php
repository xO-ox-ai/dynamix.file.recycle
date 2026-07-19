<?php

declare(strict_types=1);

use DynamixFileRecycle\Config;
use DynamixFileRecycle\FsInspector;
use DynamixFileRecycle\History;
use DynamixFileRecycle\Logger;
use DynamixFileRecycle\Security;

$root = dirname(__DIR__);
require_once $root . '/source/dynamix.file.recycle/include/Config.php';
require_once $root . '/source/dynamix.file.recycle/include/FsInspector.php';
require_once $root . '/source/dynamix.file.recycle/include/Logger.php';
require_once $root . '/source/dynamix.file.recycle/include/History.php';
require_once $root . '/source/dynamix.file.recycle/include/Security.php';

function checkSafety(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$fs = new FsInspector();
$blocked = [
    '/boot/config/go' => 'boot device',
    '/mnt/user/share/file' => 'user-share',
    '/mnt/user0/share/file' => 'user-share',
    '/mnt/cache/appdata/file' => 'Cache and pool',
    '/mnt/cache_nvme/file' => 'Cache and pool',
    '/mnt/disks/portable/file' => 'Unassigned Devices',
    '/mnt/remotes/server/share/file' => 'Remote filesystem',
];
foreach ($blocked as $path => $fragment) {
    $reason = $fs->unsupportedPathReason($path);
    checkSafety($reason !== null && str_contains($reason, $fragment), "Unexpected scope result for $path");
    checkSafety($fs->resolveVolume($path) === null, "Blocked path resolved to a supported volume: $path");
}

checkSafety($fs->lexicalNormalise('/mnt/disk1/a/../b') === '/mnt/disk1/b', 'Lexical path normalization failed');
checkSafety($fs->lexicalNormalise('/../../etc/passwd') === null, 'Traversal above root was accepted');
checkSafety($fs->lexicalNormalise('mnt/disk1/file') === null, 'Relative path was accepted');
checkSafety($fs->lexicalNormalise("/mnt/disk1/a\0b") === null, 'NUL-containing path was accepted');

$diskStateFile = sys_get_temp_dir() . '/dynamix-file-recycle-disks-' . bin2hex(random_bytes(4)) . '.ini';
file_put_contents($diskStateFile, <<<INI
[disk1]
name="disk1"
device="sda"
fsMountpoint="/mnt/disk1"

[fastpool]
name="fastpool"
device="nvme0n1"
fsMountpoint="/mnt/fastpool"
INI);
$stateAwareFs = new FsInspector([$diskStateFile]);
checkSafety(
    $stateAwareFs->unsupportedPathReason('/mnt/fastpool/appdata') === null,
    'Independent named storage pool was incorrectly treated as cache'
);
$arrayDeviceMethod = new ReflectionMethod(FsInspector::class, 'unraidArrayBackingDevice');
$arrayDeviceMethod->setAccessible(true);
checkSafety(
    $arrayDeviceMethod->invoke($stateAwareFs, '/mnt/disk1') === '/dev/sda',
    'Unraid diskN did not resolve to its physical disks.ini device'
);
@unlink($diskStateFile);

$cfg = new Config(
    $root . '/tests/.missing.cfg',
    $root . '/source/dynamix.file.recycle/dynamix.file.recycle.cfg.default'
);
$logger = new Logger(
    sys_get_temp_dir() . '/dynamix-file-recycle-test.log',
    sys_get_temp_dir() . '/dynamix-file-recycle-audit-test.log',
    'ERROR',
    1
);
$history = new History($fs, $cfg, $logger);
$security = new Security($fs);
$patch = $security->sanitizeConfigPatch([
    'global' => ['enabled' => 'on', 'language' => 'zh_CN'],
    'maintenance' => ['age_days' => '168', 'auto_empty_cron' => '0 3 * * 0'],
    'unknown' => ['dangerous' => '1'],
]);
checkSafety(($patch['global']['enabled'] ?? '') === '1', 'Boolean config sanitization failed');
checkSafety(!isset($patch['global']['language']), 'API can still override the Unraid system language');
checkSafety(($patch['maintenance']['age_days'] ?? '') === '168', 'Integer config sanitization failed');
checkSafety(!isset($patch['unknown']), 'Unknown config section survived sanitization');
checkSafety($cfg->getAllowedVolumes() === null, 'Default volume policy must allow all currently supported volumes');

$invalidVolumeRejected = false;
try {
    $security->sanitizeConfigPatch(['volumes' => ['allowed' => ['/boot']]]);
} catch (RuntimeException) {
    $invalidVolumeRejected = true;
}
checkSafety($invalidVolumeRejected, 'Unsupported volume selection was accepted');

$invalidCronRejected = false;
try {
    $security->sanitizeConfigPatch(['maintenance' => ['auto_empty_cron' => '*/0 * * * *']]);
} catch (RuntimeException) {
    $invalidCronRejected = true;
}
checkSafety($invalidCronRejected, 'Invalid cron step was accepted');

$outOfRangeCronRejected = false;
try {
    $security->sanitizeConfigPatch(['maintenance' => ['auto_empty_cron' => '0 25 * * *']]);
} catch (RuntimeException) {
    $outOfRangeCronRejected = true;
}
checkSafety($outOfRangeCronRejected, 'Out-of-range cron field was accepted');

for ($i = 0; $i < 100; $i++) {
    checkSafety(History::validId(History::newId()), 'Generated item UUID is invalid');
}

$testLog = sys_get_temp_dir() . '/dynamix-file-recycle-clear-' . bin2hex(random_bytes(4)) . '.log';
$testAudit = $testLog . '.audit';
$clearLogger = new Logger($testLog, $testAudit, 'DEBUG', 1);
$clearLogger->error('clear_test', '/mnt/disk1/test', 'test entry');
checkSafety(is_file($testLog) && is_file($testAudit), 'Logger did not create both test logs');
checkSafety($clearLogger->clear() === 2, 'Logger clear did not report both files');
checkSafety(!is_file($testLog) && !is_file($testAudit), 'Logger clear left a test log behind');

echo "Safety unit tests passed.\n";
