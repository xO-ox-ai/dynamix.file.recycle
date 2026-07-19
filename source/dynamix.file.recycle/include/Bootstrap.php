<?php
/**
 * Bootstrap.php — single entry point shared by web (api.php) and CLI
 * (cron/recycle-maintain) code paths.
 *
 * Responsibilities:
 *   - Define path constants.
 *   - Load Configuration, Logger, I18n singletons.
 *   - Provide an autoloader for the include/ classes.
 *   - CLI sub-commands:
 *       php Bootstrap.php init       reconcile online SQLite shards
 *       php Bootstrap.php maintain            run manual maintenance
 *       php Bootstrap.php maintain-scheduled  run scheduled empty + maintenance
 *       php Bootstrap.php sync-cron           sync the user-selected schedule
 *       php Bootstrap.php status     print a quick status line
 *
 * This file is safe to require from both web (under emhttp) and CLI contexts.
 * It does NOT perform any CSRF or auth checks — those live in api.php.
 */

declare(strict_types=1);

namespace DynamixFileRecycle;

if (defined(__NAMESPACE__ . '\\ROOT')) {
    return; // bootstrapped already
}

/** Plugin root, e.g. /usr/local/emhttp/plugins/dynamix.file.recycle. */
define(__NAMESPACE__ . '\\ROOT', dirname(__DIR__));
/** include/ directory. */
define(__NAMESPACE__ . '\\INCLUDE_DIR', __DIR__);
/** Persistent config directory under /boot. */
define(__NAMESPACE__ . '\\CFG_DIR', '/boot/config/plugins/dynamix.file.recycle');
/** Volatile request state; authoritative item data lives on each volume. */
define(__NAMESPACE__ . '\\STATE_DIR', '/usr/local/emhttp/state/plugins/dynamix.file.recycle');
/** Volatile runtime directory for locks and request state. */
define(__NAMESPACE__ . '\\RUN_DIR', '/run/dynamix.file.recycle');
/** Default cfg file shipped with the plugin. */
define(__NAMESPACE__ . '\\CFG_DEFAULT', ROOT . '/dynamix.file.recycle.cfg.default');
/** User-edited cfg file. */
define(__NAMESPACE__ . '\\CFG_FILE', CFG_DIR . '/dynamix.file.recycle.cfg');
/** Volatile diagnostic log plus a bounded persistent audit log. */
define(__NAMESPACE__ . '\\LOG_DIR', STATE_DIR . '/logs');
define(__NAMESPACE__ . '\\LOG_FILE', LOG_DIR . '/dynamix.file.recycle.log');
define(__NAMESPACE__ . '\\AUDIT_DIR', CFG_DIR . '/logs');
define(__NAMESPACE__ . '\\AUDIT_FILE', AUDIT_DIR . '/audit.log');
/** Schema SQL shipped with the plugin. */
define(__NAMESPACE__ . '\\SCHEMA_FILE', ROOT . '/sql/schema.sql');

spl_autoload_register(function (string $class): void {
    $prefix = __NAMESPACE__ . '\\';
    if (str_starts_with($class, $prefix)) {
        $relative = substr($class, strlen($prefix));
        $file = __DIR__ . '/' . $relative . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

/**
 * Ensure runtime directories exist. Called on every bootstrap; cheap.
 */
function ensureDirs(): void
{
    if (!is_dir(CFG_DIR)) {
        @mkdir(CFG_DIR, 0755, true);
    }
    if (!is_dir(STATE_DIR)) {
        @mkdir(STATE_DIR, 0700, true);
    }
    if (!is_dir(LOG_DIR)) {
        @mkdir(LOG_DIR, 0700, true);
    }
    if (!is_dir(RUN_DIR)) {
        @mkdir(RUN_DIR, 0700, true);
    }
    if (!is_dir(AUDIT_DIR)) {
        @mkdir(AUDIT_DIR, 0700, true);
    }
}

/**
 * Bootstrap the runtime and return the shared Container of singletons.
 */
function boot(): Container
{
    static $container = null;
    if ($container !== null) {
        return $container;
    }
    ensureDirs();
    // Seed the user cfg from the default if missing.
    if (!is_file(CFG_FILE) && is_file(CFG_DEFAULT)) {
        @copy(CFG_DEFAULT, CFG_FILE);
    }
    $container = new Container();
    return $container;
}

// ----- CLI dispatcher -------------------------------------------------------

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $cmd = $argv[1] ?? '';
    $c = boot();
    switch ($cmd) {
        case 'init':
            $c->history()->initSchema();
            $c->logger()->info('init', '', 'SQLite schema initialised.');
            echo "OK: online per-volume schemas initialised.\n";
            break;
        case 'maintain':
            $result = $c->maintenance()->run(true);
            $c->logger()->info('maintain', '', sprintf(
                'Manual maintenance finished. aged=%d capacity=%d logs=%d emptied=%d',
                $result['aged'],
                $result['capacity'],
                $result['logs'],
                $result['emptied']
            ));
            printf(
                "Maintenance done: aged=%d capacity=%d logs=%d emptied=%d\n",
                $result['aged'],
                $result['capacity'],
                $result['logs'],
                $result['emptied']
            );
            break;
        case 'maintain-scheduled':
            $result = $c->maintenance()->run(false);
            $c->logger()->info('maintain', '', sprintf(
                'Scheduled cleanup finished. aged=%d capacity=%d logs=%d emptied=%d',
                $result['aged'],
                $result['capacity'],
                $result['logs'],
                $result['emptied']
            ));
            printf(
                "Maintenance done: aged=%d capacity=%d logs=%d emptied=%d\n",
                $result['aged'],
                $result['capacity'],
                $result['logs'],
                $result['emptied']
            );
            break;
        case 'sync-cron':
            $c->scheduler()->sync();
            echo trim($c->config()->getAutoEmptyCron()) === ''
                ? "OK: no scheduled cleanup configured.\n"
                : "OK: scheduled cleanup synchronized.\n";
            break;
        case 'status':
            $cfg = $c->config();
            printf("enabled=%d\n", $cfg->getEnabled() ? 1 : 0);
            printf("log_level=%s\n", $cfg->getLogLevel());
            printf("databases=%d\n", count($c->history()->existingVolumes()));
            printf("items=%d\n", $c->history()->countAll());
            break;
        default:
            echo "Usage: php Bootstrap.php [init|maintain|maintain-scheduled|sync-cron|status]\n";
            exit(1);
    }
}
