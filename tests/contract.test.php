<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function source(string $relative): string
{
    global $root;
    $value = file_get_contents($root . '/' . $relative);
    if ($value === false) throw new RuntimeException("Unable to read $relative");
    return $value;
}

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$version = trim(source('VERSION'));
check(preg_match('/\A\d{4}\.\d{2}\.\d{2}[a-z]\z/', $version) === 1, 'VERSION is not date-letter format');

$plg = source('dynamix.file.recycle.plg');
check(str_contains($plg, 'Method="install"'), 'PLG install method is missing');
check(str_contains($plg, 'Method="remove"'), 'PLG remove method is missing');
check(str_contains($plg, '<SHA256>'), 'PLG must use SHA256');
check(!str_contains($plg, '<MD5>'), 'PLG still uses MD5');
check(str_contains($plg, 'raw.githubusercontent.com/xO-ox-ai/dynamix.file.recycle/main/dynamix.file.recycle.plg'), 'pluginURL is not stable');
check(str_contains($plg, '/boot/config/plugins/&name;/&packageName;'), 'release package is not cached on /boot');
check(str_contains($plg, 'releases/download/&version;/&packageName;'), 'release URL must use the exact version without a v prefix');
check(!str_contains($plg, 'releases/download/v&version;'), 'release URL still adds an unwanted v prefix');

$api = source('source/dynamix.file.recycle/api.php');
check(str_contains($api, "REQUEST_METHOD'] ?? '') !== 'POST'"), 'API does not require POST');
check(!str_contains($api, '$_GET[\'action\']'), 'API accepts action from GET and can bypass CSRF');
check(str_contains($api, "case 'inspect'"), 'click-time inspect action is missing');
check(strpos($api, "case 'inspect'") < strpos($api, "case 'recycle'"), 'inspect must precede recycle');

$js = source('source/dynamix.file.recycle/javascript/recycle.js');
check(str_contains($js, "document.querySelector('table.indexer')"), 'official DFM table selector is missing');
check(str_contains($js, "i[id^=\"row_\"][data][type]"), 'official DFM row-path selector is missing');
check(str_contains($js, "url === '/webGui/include/Browse.php'"), 'Browse.php response bridge is missing');
check(str_contains($js, 'args[0] = decorateHtml(html)'), 'DFM HTML is not decorated before commit');
check(str_contains($js, "action: 'inspect'"), 'front end does not inspect before recycle');
check(strpos($js, "action: 'inspect'") < strpos($js, 'window.confirm'), 'confirmation occurs before backend inspection');

$fs = source('source/dynamix.file.recycle/include/FsInspector.php');
foreach (['/boot', '/mnt/user', '/mnt/user0', '/mnt/cache', '/mnt/disks', '/mnt/remotes', '/mnt/rootshare'] as $blocked) {
    check(str_contains($fs, "'$blocked'"), "blocked root is missing: $blocked");
}
foreach (['lsblk', 'zpool', "'usb'", "'RM'"] as $topologySignal) {
    check(str_contains($fs, $topologySignal), "external-storage signal is missing: $topologySignal");
}
foreach (['unsupported_boot_device', 'unsupported_unassigned_device', 'unsupported_usb_storage', 'unverified_storage_topology'] as $errorCode) {
    check(str_contains($api, "'$errorCode'"), "public device-scope error code is missing: $errorCode");
}

$history = source('source/dynamix.file.recycle/include/History.php');
check(str_contains($history, "<volume>/.RecycleBin"), 'per-volume database contract is undocumented');
check(str_contains($history, "FsInspector::RECYCLE_NAME . '/' . self::DB_NAME"), 'database is not routed into each recycle bin');
check(str_contains($history, 'isApprovedVolumeRoot'), 'history shards are not restricted to approved storage');
check(!str_contains($history, '/boot/config/plugins'), 'per-volume history must not be centralized on /boot');

$recycler = source('source/dynamix.file.recycle/include/Recycler.php');
$restorer = source('source/dynamix.file.recycle/include/Restorer.php');
check(!str_contains($recycler, 'recursiveCopy'), 'recycler still contains cross-filesystem copy fallback');
check(!str_contains($restorer, 'recursiveCopy'), 'restorer still contains cross-filesystem copy fallback');
check(str_contains($recycler, 'verifyInspectionToken'), 'recycle does not bind to the inspected inode state');
check(str_contains($recycler, 'isVolumeAllowed'), 'recycler does not enforce the configured volume allowlist');

$settings = source('source/dynamix.file.recycle/settings.page');
check(str_contains($settings, 'allowed_volumes[]'), 'settings page does not expose per-volume management switches');
check(str_contains($settings, 'supportedVolumes()'), 'volume switches are not built from currently supported volumes');

$scheduler = source('source/dynamix.file.recycle/include/Scheduler.php');
check(str_contains($scheduler, "CFG_DIR . '/dynamix.file.recycle.cron'"), 'schedule is not stored in Unraid plugin config');
check(str_contains($scheduler, '/usr/local/sbin/update_cron'), 'schedule does not use Unraid update_cron');
check(!is_file($root . '/source/dynamix.file.recycle/cron/dynamix.file.recycle.cron'), 'legacy hourly maintenance cron still exists');

echo "Core contract tests passed.\n";
