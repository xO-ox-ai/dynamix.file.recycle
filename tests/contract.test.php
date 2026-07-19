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
check(str_contains($js, 'insertBefore(button, deleteButton)'), 'recycle control is not inserted immediately before Delete');
check(str_contains($js, "i[id^=\"check_\"].fa-check-square-o"), 'selected DFM rows are not read from native check controls');
check(str_contains($js, "document.getElementById('row_' + suffix)"), 'selected rows are not mapped to canonical DFM paths');
check(!str_contains($js, "className = 'recycle-action'"), 'dangerous per-row recycle controls are still created');
check(str_contains($js, "querySelectorAll('.recycle-slot, .recycle-action')"), 'legacy cached row controls are not removed');
check(str_contains($js, "action: 'inspect'"), 'front end does not inspect before recycle');
check(!str_contains($js, 'window.confirm'), 'DFM recycle still uses an uninformative native confirmation prompt');
check(str_contains($js, 'confirmInspectedItems(inspected)'), 'inspected files are not shown in a second-stage confirmation dialog');
check(str_contains($js, "makeElement('details', 'recycle-confirm-details')"), 'confirmation does not provide a collapsible item list');
check(str_contains($js, 'item.recycleDirectory'), 'confirmation does not show the authoritative destination folder');

$fs = source('source/dynamix.file.recycle/include/FsInspector.php');
foreach (['/boot', '/mnt/user', '/mnt/user0', '/mnt/cache', '/mnt/disks', '/mnt/remotes', '/mnt/rootshare'] as $blocked) {
    check(str_contains($fs, "'$blocked'"), "blocked root is missing: $blocked");
}
foreach (['lsblk', 'zpool', "'usb'", "'RM'"] as $topologySignal) {
    check(str_contains($fs, $topologySignal), "external-storage signal is missing: $topologySignal");
}
check(str_contains($fs, "'/var/local/emhttp/disks.ini'"), 'Unraid array/pool state is not consulted');
check(str_contains($fs, 'unraidArrayBackingDevice'), 'diskN is not mapped to its assigned physical device');
check(str_contains($fs, "preg_match('#^(/mnt/disk\\d+)(?:/|$)#'"), 'array child datasets do not reuse the assigned disk backing device');
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
check(str_contains($recycler, "\$recycleRoot = \$volume['volume']"), 'recycler does not anchor data to the resolved dataset root');

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
$languageHook = source('source/dynamix.file.recycle/RecycleLanguageHook.page');
$renderInject = static function (string $pageSource, string $requestUri): string {
    $separator = strpos($pageSource, "---\n");
    if ($separator === false) throw new RuntimeException('Runtime hook page separator is missing');
    $body = substr($pageSource, $separator + 4);
    $previous = $_SERVER['REQUEST_URI'] ?? null;
    $_SERVER['REQUEST_URI'] = $requestUri;
    ob_start();
    try {
        eval('?>' . $body);
        return (string) ob_get_clean();
    } finally {
        if (ob_get_level() > 0) ob_end_clean();
        if ($previous === null) unset($_SERVER['REQUEST_URI']);
        else $_SERVER['REQUEST_URI'] = $previous;
    }
};
check($renderInject($inject, '/Main') === '', 'runtime hook emits output on the Main/UD page');
check(str_contains($inject, "\$pluginDir  = '/usr/local/emhttp/plugins/dynamix.file.recycle'"), 'runtime hook does not use the absolute plugin directory');
check(!str_contains($inject, 'parse_plugin('), 'menu translation still depends on Unraid global plugin cache state');
check(str_contains($inject, "Link='recycle-runtime-hook'"), 'runtime hook can appear as an unwanted navigation button');
check(substr_count($inject, 'autov(') === 2, 'front-end assets do not use Unraid cache busting');
check(substr_count($inject, 'rawurlencode($version)') === 2, 'DFM assets are not keyed by plugin version');
check(str_contains($inject, "preg_match('#(?:^|/)Main/Browse/?$#'"), 'runtime hook is not restricted to the official DFM route');
check(str_contains($languageHook, 'Menu="Buttons:5z"'), 'menu localization does not use a dedicated pre-navigation hook');
check(str_contains($languageHook, 'dynamix.file.recycle.txt'), 'menu localization hook does not load the plugin-local catalog');
check(!str_contains($languageHook, 'Bootstrap.php'), 'menu localization hook loads plugin runtime services');
check(!str_contains($languageHook, '<script') && !str_contains($languageHook, '<link'), 'menu localization hook emits front-end assets');
check(
    strpos($inject, "preg_match('#(?:^|/)Main/Browse/?$#'") < strpos($inject, 'require_once $bootFile'),
    'runtime hook performs plugin or storage initialization before checking the DFM route'
);
check(
    !str_contains(substr($inject, 0, strpos($inject, 'require_once $bootFile')), '$_GET'),
    'runtime hook incorrectly uses the browsed storage path to suppress DFM assets'
);
check(str_contains($inject, "'enabled'    => \$enabled"), 'disabled state prevents the front-end assets from loading for diagnostics');
check(!str_contains($api, 'assertAdmin'), 'API still relies on a non-portable session shape');
check(str_contains($js, "Main\\/Browse"), 'front-end does not identify the exact official DFM Browse route');
check(str_contains($js, "path === '/mnt/user'"), 'known unsupported user-share browsing does not keep Recycle disabled');
check(str_contains($js, "button.classList.toggle('extra', !blocked)"), 'known unsupported paths can still be enabled by DFM selection state');
check(str_contains($js, "className = 'dfm_control recycle-batch-action'"), 'DFM batch control does not use a stable native control class');
check(str_contains($js, "button.classList.add('extra')"), 'supported DFM paths do not opt into native selection state');
check(str_contains($recycler, "'recycle_directory' => \$recycleDirectory"), 'inspection does not return the authoritative destination directory');

$settingsJs = source('source/dynamix.file.recycle/javascript/settings.js');
$settingsCss = source('source/dynamix.file.recycle/javascript/settings.css');
check(str_contains($settingsJs, "request('config_get')"), 'settings are not loaded through the API');
check(str_contains($settingsJs, "request('config_save'"), 'settings are not saved through the API');
check(str_contains($settingsJs, "'clear_logs'"), 'settings do not expose log cleanup');
check(str_contains($settingsJs, "'clear_history'"), 'settings do not expose inactive-history cleanup');
check(str_contains($settingsJs, 'volume.hierarchy'), 'settings do not render structured volume hierarchy');
check(strpos($settings, 'id="recycle-clear-logs"') < strpos($settings, 'data-i18n="history"'), 'log cleanup is not inside the Logging section');
check(strpos($settings, 'id="recycle-clear-history"') < strpos($settings, 'data-i18n="maintenance"'), 'history cleanup is not inside the History section');
check(str_contains($settingsCss, 'inline-size: fit-content !important'), 'section cleanup buttons still expand to the full settings column');
check(str_contains($settingsCss, '.recycle-action-row > button.recycle-compact-action'), 'cleanup buttons are not isolated from Unraid grid stretching');
check(str_contains($settings, 'id="recycle-download-diagnostics"'), 'settings do not expose diagnostic download');
check(str_contains($settingsJs, "body.append('action', 'diagnostics')"), 'settings diagnostic button does not call the API');
check(str_contains($settings, 'id="recycle-settings-log-size"'), 'settings do not display current plugin log size');
check(str_contains($settings, 'id="recycle-settings-clearable-history"'), 'settings do not display clearable history count');
check(str_contains($api, "'log_bytes' => \$logger->sizeBytes()"), 'settings API omits current plugin log size');
check(str_contains($api, "'clearable_history' => \$c->history()->countInactive()"), 'settings API omits clearable history count');
check(str_contains($api, "'supported_volumes' => \$supportedVolumes"), 'settings API does not return validated volumes');
check(str_contains($api, "case 'clear_logs'"), 'log cleanup API action is missing');
check(str_contains($api, "case 'clear_history'"), 'history cleanup API action is missing');
check(str_contains($api, "case 'diagnostics'"), 'diagnostic download API action is missing');
check(str_contains($api, "'hierarchy' => \$display['hierarchy']"), 'volume hierarchy metadata is missing');
check(str_contains($api, "['name', 'deleted_at', 'size']"), 'Recycle Bin API sort fields are not strictly allowlisted');
check(str_contains($api, "min(200, (int) (\$_POST['limit'] ?? 50))"), 'Recycle Bin API does not default to 50 bounded rows');
check(str_contains($api, "'total_records' => \$totalRecords"), 'Recycle Bin API omits total records for paging');

$binJs = source('source/dynamix.file.recycle/javascript/recycle-bin.js');
check(str_contains($binJs, "request('list'"), 'Recycle Bin details do not load through the API');
check(str_contains($binJs, "request(action, { id:"), 'Recycle Bin restore/purge actions are missing');
check(str_contains($binJs, "limit: String(pageSize)"), 'Recycle Bin list is not paged server-side');
check(str_contains($binJs, "sort: sortControl.value"), 'Recycle Bin list does not request sorting');
check(str_contains($binJs, "runBatch('restore')"), 'Recycle Bin batch restore is missing');
check(str_contains($binJs, "runBatch('purge')"), 'Recycle Bin batch purge is missing');
check(str_contains($binJs, "'/Main/Browse?dir='"), 'restored paths do not link to the built-in file browser');
check(str_contains($recyclePage, 'id="recycle-bin-pagination-top"'), 'top-right pagination is missing');
check(str_contains($recyclePage, 'id="recycle-bin-pagination-bottom"'), 'bottom-right pagination is missing');
check(str_contains($recyclePage, 'id="recycle-bin-select-all"'), 'Recycle Bin page selection is missing');

$pluginReadme = source('source/dynamix.file.recycle/README.md');
check(str_contains($pluginReadme, 'html:lang(zh)'), 'installed plugin description does not follow the Unraid page language');
check(str_contains($pluginReadme, 'official built-in Dynamix File Manager'), 'installed plugin description omits the official file-browser scope');
check(str_contains($history, "'name' => 'COALESCE(display_name,original_path) COLLATE NOCASE'"), 'history does not support server-side name sorting');
check(str_contains($history, 'public function countInactive'), 'settings cannot count clearable history rows');

$diagnostics = source('source/dynamix.file.recycle/include/Diagnostics.php');
check(str_contains($diagnostics, "'pdo_drivers' => \\PDO::getAvailableDrivers()"), 'diagnostics omit PDO driver availability');
check(str_contains($diagnostics, "'sqlite_backend' => 'sqlite3-cli'"), 'diagnostics omit the active SQLite backend');
check(str_contains($diagnostics, "'integrity_check'"), 'diagnostics omit SQLite integrity state');
check(str_contains($diagnostics, "'zfs-list.txt'"), 'diagnostics omit ZFS topology');
check(str_contains($recycler, "\$stage = 'rename'"), 'recycler does not log the rename stage');

$sqliteConnection = source('source/dynamix.file.recycle/include/SqliteConnection.php');
check(str_contains($sqliteConnection, "['/usr/bin/sqlite3', '/bin/sqlite3']"), 'SQLite adapter does not use Unraid bundled paths');
check(str_contains($sqliteConnection, "proc_open(\$argv"), 'SQLite adapter invokes a shell instead of an argv process');
check(str_contains($sqliteConnection, "CAST(X'"), 'SQLite text parameters are not encoded as syntax-safe hex literals');
check(str_contains($sqliteConnection, 'PRAGMA synchronous=FULL'), 'SQLite CLI calls do not enforce full synchronous writes');
check(!str_contains($history, 'new \\PDO'), 'history still requires a missing PDO SQLite driver');

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
