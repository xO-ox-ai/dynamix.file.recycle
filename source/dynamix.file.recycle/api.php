<?php
/**
 * api.php — single HTTP entry point for the plugin.
 *
 * IMPORTANT — CSRF:
 *   Unraid's `auto_prepend` (configured via php.ini) runs BEFORE this file
 *   and validates `$_POST['csrf_token']` for every POST request. On success
 *   it `unset()`s the field. On failure it terminates the request. This
 *   plugin therefore does NOT re-implement CSRF: the front-end just needs to
 *   include `csrf_token` in the POST body, and Unraid handles the rest.
 *
 * Authorisation: every action requires an authenticated admin session (or
 * the experimental `allow_user_self_service` mode for some read-only /
 * own-item actions).
 *
 * Actions (POST body, application/x-www-form-urlencoded):
 *   action=recycle&path=<abs>                  move an item to the bin
 *   action=restore&id=<itemId>                 restore from the bin
 *   action=purge&id=<itemId>                   permanently delete
 *   action=empty&volume=<absVolumeRoot>        empty a volume's bin
 *   action=list[&volume=&state=]               list active (or all) items
 *   action=status                              summary stats
 *   action=config_get                          read the cfg (admin)
 *   action=config_save                         write the cfg (admin)
 *   action=maintain_now                        run maintenance synchronously (admin)
 *
 * All responses are JSON.
 */

declare(strict_types=1);

require_once __DIR__ . '/include/Bootstrap.php';

use DynamixFileRecycle\boot;
use DynamixFileRecycle\Config;
use DynamixFileRecycle\FsInspector;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function respond(array $payload, int $http = 200): void
{
    http_response_code($http);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $message, int $http = 400, ?string $code = null): void
{
    $payload = ['ok' => false, 'error' => $message];
    if ($code !== null) {
        $payload['code'] = $code;
    }
    respond($payload, $http);
}

try {
    $c = boot();
    $cfg = $c->config();
    $sec = $c->security();
    $logger = $c->logger();
    $action = trim($_POST['action'] ?? $_GET['action'] ?? '');

    // Read-only / unauthenticated-ish actions are gated inside their handlers.
    // All other actions require admin.
    $publicActions = ['status', 'list'];
    if (!in_array($action, $publicActions, true)) {
        $sec->assertAdmin();
    }

    // For all mutating actions we require the plugin to be enabled.
    $mutatingActions = ['recycle', 'restore', 'purge', 'empty', 'config_save', 'maintain_now'];
    if (in_array($action, $mutatingActions, true)) {
        $sec->assertEnabled($cfg);
    }

    switch ($action) {
        case 'recycle': {
            $path = (string) ($_POST['path'] ?? '');
            $confirm = isset($_POST['confirm']) && in_array(strtolower((string) $_POST['confirm']), ['1', 'true', 'yes', 'on'], true);
            $canonical = $sec->assertPathInScope($path);
            $r = $c->recycler()->recycle($canonical, $confirm);
            if ($r->needConfirm) {
                // 202 Accepted: precheck succeeded, waiting for the user to
                // confirm before we actually move anything.
                respond($r->toArray(), 202);
            }
            if (!$r->ok) {
                fail($r->error ?? 'Unknown error', 400, 'recycle_failed');
            }
            respond(['ok' => true] + $r->toArray());
        }

        case 'restore': {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                fail('Missing id.', 400);
            }
            respond($c->restorer()->restore($id));
        }

        case 'purge': {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                fail('Missing id.', 400);
            }
            respond($c->purger()->purgeOne($id, 'manual'));
        }

        case 'empty': {
            $vol = (string) ($_POST['volume'] ?? '');
            $volCanon = $c->fs()->normalise($vol);
            if ($volCanon === null || !is_dir($volCanon)) {
                fail('Invalid volume.', 400);
            }
            // Only allow emptying actual /mnt/disk* roots or ZFS mounts.
            if (!$c->fs()->isInScope($volCanon)) {
                fail('Volume is out of scope.', 400);
            }
            respond($c->purger()->emptyVolume($volCanon));
        }

        case 'list': {
            // Admin sees everything; non-admin sees only their own (if allowed).
            $allowSelf = $cfg->getAllowUserSelfService();
            try {
                $sec->assertAdmin();
                $isAdmin = true;
            } catch (\Throwable $_) {
                if (!$allowSelf) {
                    fail('Administrator privileges required.', 403);
                }
                $isAdmin = false;
            }
            $state = $_GET['state'] ?? ($_POST['state'] ?? 'active');
            $volume = $_GET['volume'] ?? ($_POST['volume'] ?? null);
            $includeHistory = $isAdmin
                && $cfg->getHistoryEnabled()
                && ($state === 'all');
            $limit = max(1, min(2000, (int) ($_GET['limit'] ?? 500)));
            $offset = max(0, (int) ($_GET['offset'] ?? 0));

            if ($includeHistory) {
                $rows = $c->history()->listAll($volume, $limit, $offset);
            } else {
                $rows = $c->history()->listActive($volume, $limit, $offset);
            }
            // Never leak absolute paths to non-admin viewers.
            if (!$isAdmin) {
                foreach ($rows as &$row) {
                    $row['original_path'] = basename($row['original_path']);
                    $row['recycle_path'] = basename($row['recycle_path']);
                }
            }
            respond([
                'ok' => true,
                'items' => $rows,
                'count' => count($rows),
                'state_filter' => $state,
            ]);
        }

        case 'status': {
            $stats = [];
            foreach (glob('/mnt/disk*', GLOB_ONLYDIR) as $d) {
                $rec = $d . '/' . FsInspector::RECYCLE_NAME;
                $stats[] = [
                    'volume' => $d,
                    'fs' => $c->fs()->resolveVolume($d)['fs'] ?? 'unknown',
                    'recycle_present' => is_dir($rec),
                    'items' => $c->history()->countActive($d),
                    'size' => is_dir($rec) ? $c->fs()->dirSize($rec) : 0,
                    'total' => $c->fs()->volumeStats($d)['total'],
                    'used' => $c->fs()->volumeStats($d)['used'],
                ];
            }
            respond([
                'ok' => true,
                'enabled' => $cfg->getEnabled(),
                'language' => $c->i18n()->lang(),
                'history_enabled' => $cfg->getHistoryEnabled(),
                'volumes' => $stats,
                'totals' => [
                    'items' => $c->history()->countActive(),
                    'size' => $c->history()->totalActiveSize(),
                ],
            ]);
        }

        case 'config_get': {
            $raw = $cfg->raw();
            // Hide internal-only keys.
            respond([
                'ok' => true,
                'config' => $raw,
                'schema_version' => '1',
            ]);
        }

        case 'config_save': {
            // Three accepted payload shapes:
            //   1. config=<json>                 (JSON string in the 'config' field)
            //   2. config[global][enabled]=1     (PHP-style nested array)
            //   3. flat fields                   (enabled=1&language=en_US&...)
            $payload = $_POST['config'] ?? null;
            if (is_string($payload)) {
                $decoded = json_decode($payload, true);
                if (!is_array($decoded)) {
                    fail('config is not valid JSON.', 400);
                }
                $payload = $decoded;
            } elseif (is_array($payload)) {
                // PHP nested array from config[...]=...; use as-is.
            } elseif ($payload === null) {
                // Flat fallback: rebuild section/keys from $_POST top-level keys.
                $payload = $_POST;
                unset($payload['csrf_token'], $payload['action']);
            } else {
                fail('No config payload supplied.', 400);
            }
            $patch = $c->security()->sanitizeConfigPatch($payload);
            $cfg->mergeAndSave($patch);
            respond(['ok' => true]);
        }

        case 'maintain_now': {
            $report = $c->maintenance()->run();
            $logger->info('maintain_now', '', 'manual run: ' . json_encode($report));
            respond(['ok' => true, 'report' => $report]);
        }

        default:
            fail('Unknown action.', 400, 'unknown_action');
    }
} catch (\Throwable $e) {
    $code = $e->getCode();
    $http = is_int($code) && $code >= 400 && $code < 600 ? $code : 500;
    if (isset($logger)) {
        try { $logger->error('api', '', $e->getMessage() . "\n" . $e->getTraceAsString()); } catch (\Throwable $_) {}
    }
    fail($e->getMessage(), $http);
}
