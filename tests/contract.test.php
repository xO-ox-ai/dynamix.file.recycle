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
check(str_contains($plg, '<!ENTITY launch    "Settings/DynamixFileRecycle">'), 'Plugin Manager launch path does not follow the settings page');
check(!str_contains($plg, '<!ENTITY launch    "/Settings/'), 'Plugin Manager launch path can become a protocol-relative host');
check(str_contains($plg, 'icon="recycle"'), 'Plugin Manager does not use the recycle icon');

$api = source('source/dynamix.file.recycle/api.php');
check(str_contains($api, "REQUEST_METHOD'] ?? '') !== 'POST'"), 'API does not require POST');
check(!str_contains($api, '$_GET[\'action\']'), 'API accepts action from GET and can bypass CSRF');
check(str_contains($api, 'use function DynamixFileRecycle\\boot;'), 'API imports boot() as a class instead of a function');
check(str_contains($api, "case 'inspect'"), 'click-time inspect action is missing');
check(strpos($api, "case 'inspect'") < strpos($api, "case 'recycle'"), 'inspect must precede recycle');

$js = source('source/dynamix.file.recycle/javascript/recycle.js');
check(str_contains($js, "document.getElementById('buttons')"), 'official DFM bottom control container is missing');
check(str_contains($js, "doActions(1,"), 'native Delete control cannot be located');
check(str_contains($js, 'deleteButton.nextSibling'), 'recycle control is not inserted immediately after Delete');
check(str_contains($js, "i[id^=\"check_\"].fa-check-square-o"), 'selected DFM rows are not read from native check controls');
check(str_contains($js, "document.getElementById('row_' + suffix)"), 'selected rows are not mapped to canonical DFM paths');
check(!str_contains($js, "className = 'recycle-action'"), 'dangerous per-row recycle controls are still created');
check(str_contains($js, "querySelectorAll('.recycle-slot, .recycle-action')"), 'legacy cached row controls are not removed');
check(str_contains($js, "action: 'inspect'"), 'front end does not inspect before recycle');
check(strpos($js, "action: 'inspect'") < strpos($js, 'window.confirm'), 'confirmation occurs before backend inspection');

$fs = source('source/dynamix.file.recycle/include/FsInspector.php');
foreach (['/boot', '/mnt/user', '/mnt/user0', '/mnt/cache', '/mnt/disks', '/mnt/remotes', '/mnt/rootshare'] as $blocked) {
    check(str_contains($fs, "'$blocked'"), "blocked root is missing: $blocked");
}
foreach (['lsblk', 'zpool', "'usb'", "'RM'"] as $topologySignal) {
    check(str_contains($fs, $topologySignal), "external-storage signal is missing: $topologySignal");
}
check(str_contains($fs, "'/var/local/emhttp/disks.ini'"), 'Unraid array/pool state is not consulted');
check(str_contains($fs, 'unraidArrayBackingDevice'), 'diskN is not mapped to its assigned physical device');
foreach (['unsupported_boot_device', 'unsupported_unassigned_device', 'unsupported_usb_storage', 'unverified_storage_topology'] as $errorCode) {
    check(str_contains($api, "'$errorCode'"), "public device-scope error code is missing: $errorCode");
}

$history = source('source/dynamix.file.recycle/include/History.php');
check(str_contains($history, "<volume>/.RecycleBin"), 'per-volume database contract is undocumented');
check(str_contains($history, "FsInspector::RECYCLE_NAME . '/' . self::DB_NAME"), 'database is not routed into each recycle bin');
check(str_contains($history, 'isApprovedVolumeRoot'), 'history shards are not restricted to approved storage');
check(!str_contains($history, '/boot/config/plugins'), 'per-volume history must not be centralized on /boot');
check(str_contains($history, "DELETE FROM items WHERE state IN ('restored','purged')"), 'history cleanup is not limited to inactive audit rows');

$recycler = source('source/dynamix.file.recycle/include/Recycler.php');
$restorer = source('source/dynamix.file.recycle/include/Restorer.php');
check(!str_contains($recycler, 'recursiveCopy'), 'recycler still contains cross-filesystem copy fallback');
check(!str_contains($restorer, 'recursiveCopy'), 'restorer still contains cross-filesystem copy fallback');
check(str_contains($recycler, 'verifyInspectionToken'), 'recycle does not bind to the inspected inode state');
check(str_contains($recycler, 'isVolumeAllowed'), 'recycler does not enforce the configured volume allowlist');

$settings = source('source/dynamix.file.recycle/DynamixFileRecycle.page');
check(str_contains($settings, 'id="recycle-settings-volumes"'), 'settings page does not expose the managed-volume container');
check(!str_contains($settings, 'Bootstrap.php'), 'settings page still initializes backend services during Unraid page evaluation');
check(str_contains($settings, 'DynamixFileRecycleSettingsRuntime'), 'settings page does not use the USB Guardian-style runtime bootstrap');
check(str_contains($settings, 'javascript/settings.js'), 'settings page does not load its API client');
check(str_contains($settings, 'include/I18n.php'), 'settings page does not load its lightweight localization helper');
check(str_contains($settings, "'i18n' => \$recycleSettingsCatalog"), 'settings page does not embed its own localization catalog');
check(str_contains($settings, 'Menu="Utilities:30"'), 'settings tile is not registered inside User Programs');
check(!str_contains($settings, 'DiskUtilities'), 'settings page is still duplicated under Disk Utilities');
check(str_contains($settings, 'Title="Dynamix File Recycle Bin"'), 'plugin title is not a translatable English key');
check(str_contains($settings, 'Icon="recycle"'), 'plugin tile does not use a valid Unraid icon');
check(!str_contains($settings, 'name="language"'), 'settings page still overrides the Unraid system language');
check(!str_contains($settings, 'assertAdmin'), 'settings page still relies on a non-portable session shape');
check(!is_file($root . '/source/dynamix.file.recycle/settings.page'), 'legacy settings route still exists');
check(!is_file($root . '/source/dynamix.file.recycle/README.page'), 'legacy About menu page still exists');

$recyclePage = source('source/dynamix.file.recycle/RecycleBin.page');
check(str_contains($recyclePage, 'Menu="DiskUtilities:30"'), 'Recycle Bin details are not registered under Disk Utilities');
check(str_contains($recyclePage, 'DynamixFileRecycleBinRuntime'), 'Recycle Bin does not use a static API-backed page shell');
check(str_contains($recyclePage, 'javascript/recycle-bin.js'), 'Recycle Bin page does not load its API client');
check(!str_contains($recyclePage, 'Bootstrap.php'), 'Recycle Bin still initializes storage services during page evaluation');
check(!str_contains($recyclePage, 'assertAdmin'), 'Recycle Bin page still relies on a non-portable session shape');

foreach (glob($root . '/source/dynamix.file.recycle/*.page') ?: [] as $pageFile) {
    $pageSource = (string) file_get_contents($pageFile);
    check(
        !str_contains($pageSource, '__DIR__'),
        basename($pageFile) . ' uses __DIR__, which resolves to DefaultPageLayout when Unraid evals .page content'
    );
}
check(
    str_contains($recyclePage, "\$recycleBinPluginDir = '/usr/local/emhttp/plugins/dynamix.file.recycle'"),
    'Recycle Bin runtime page does not use the proven absolute plugin directory'
);

$inject = source('source/dynamix.file.recycle/RecycleInject.page');
check(str_contains($inject, "parse_ini_file("), 'plugin-local Unraid menu translations are not loaded');
check(str_contains($inject, "\$pluginDir  = '/usr/local/emhttp/plugins/dynamix.file.recycle'"), 'runtime hook does not use the absolute plugin directory');
check(!str_contains($inject, 'parse_plugin('), 'menu translation still depends on Unraid global plugin cache state');
check(str_contains($inject, "Link='recycle-runtime-hook'"), 'runtime hook can appear as an unwanted navigation button');
check(substr_count($inject, 'autov(') === 2, 'front-end assets do not use Unraid cache busting');
check(substr_count($inject, 'rawurlencode($version)') === 2, 'DFM assets are not keyed by plugin version');
check(!str_contains($inject, '$onBrowse'), 'server-side Browse detection can still suppress the DFM button');
check(str_contains($inject, "'enabled'    => \$enabled"), 'disabled state prevents the front-end assets from loading for diagnostics');
check(!str_contains($api, 'assertAdmin'), 'API still relies on a non-portable session shape');
check(str_contains($js, "window.location.pathname"), 'front-end does not identify the DFM Browse route');
check(str_contains($js, "className = 'dfm_control extra recycle-batch-action'"), 'DFM batch control does not use native selection-state classes');

$settingsJs = source('source/dynamix.file.recycle/javascript/settings.js');
check(str_contains($settingsJs, "request('config_get')"), 'settings are not loaded through the API');
check(str_contains($settingsJs, "request('config_save'"), 'settings are not saved through the API');
check(str_contains($settingsJs, "'clear_logs'"), 'settings do not expose log cleanup');
check(str_contains($settingsJs, "'clear_history'"), 'settings do not expose inactive-history cleanup');
check(str_contains($settingsJs, 'volume.hierarchy'), 'settings do not render structured volume hierarchy');
check(strpos($settings, 'id="recycle-clear-logs"') < strpos($settings, 'data-i18n="history"'), 'log cleanup is not inside the Logging section');
check(strpos($settings, 'id="recycle-clear-history"') < strpos($settings, 'data-i18n="maintenance"'), 'history cleanup is not inside the History section');
check(str_contains($api, "'supported_volumes' => \$supportedVolumes"), 'settings API does not return validated volumes');
check(str_contains($api, "case 'clear_logs'"), 'log cleanup API action is missing');
check(str_contains($api, "case 'clear_history'"), 'history cleanup API action is missing');
check(str_contains($api, "'hierarchy' => \$display['hierarchy']"), 'volume hierarchy metadata is missing');

$binJs = source('source/dynamix.file.recycle/javascript/recycle-bin.js');
check(str_contains($binJs, "request('list'"), 'Recycle Bin details do not load through the API');
check(str_contains($binJs, "request(action, { id:"), 'Recycle Bin restore/purge actions are missing');

$menuLanguage = source('source/dynamix.file.recycle/unraid-language/zh_CN/dynamix.file.recycle.txt');
check(str_contains($menuLanguage, 'Dynamix File Recycle Bin=文件回收站'), 'Chinese Unraid menu translation is missing');
$install = source('source/dynamix.file.recycle/scripts/install.sh');
$remove = source('source/dynamix.file.recycle/scripts/remove.sh');
check(str_contains($install, 'languages/zh_CN/dynamix.file.recycle.txt'), 'install hook does not remove the obsolete global translation');
check(str_contains($install, 'dynamix.file.recycle.dot'), 'install hook does not remove the obsolete translation cache');
check(str_contains($remove, 'dynamix.file.recycle.txt'), 'remove hook leaves the global menu translation behind');
check(str_contains($remove, 'dynamix.file.recycle.dot'), 'remove hook leaves the translation cache behind');

$scheduler = source('source/dynamix.file.recycle/include/Scheduler.php');
check(str_contains($scheduler, "CFG_DIR . '/dynamix.file.recycle.cron'"), 'schedule is not stored in Unraid plugin config');
check(str_contains($scheduler, '/usr/local/sbin/update_cron'), 'schedule does not use Unraid update_cron');
check(!is_file($root . '/source/dynamix.file.recycle/cron/dynamix.file.recycle.cron'), 'legacy hourly maintenance cron still exists');

echo "Core contract tests passed.\n";
