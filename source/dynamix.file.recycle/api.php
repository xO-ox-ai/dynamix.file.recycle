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
 * Authorisation: every action requires an authenticated admin session.
 *
 * Actions (POST body, application/x-www-form-urlencoded):
 *   action=inspect&path=<abs>                  validate and sign current item state
 *   action=recycle&path=<abs>&inspection_token move an inspected item to the bin
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

use DynamixFileRecycle\FsInspector;
use function DynamixFileRecycle\boot;

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

function publicErrorCode(\Throwable $e): string
{
    $message = $e->getMessage();
    return match (true) {
        str_contains($message, 'boot device') => 'unsupported_boot_device',
        str_contains($message, 'user-share paths') => 'unsupported_user_share',
        str_contains($message, 'Cache and pool paths') => 'unsupported_cache_pool',
        str_contains($message, 'Unassigned Devices') => 'unsupported_unassigned_device',
        str_contains($message, 'Remote filesystem') => 'unsupported_remote_path',
        str_contains($message, 'USB-backed') => 'unsupported_usb_storage',
        str_contains($message, 'Removable storage') => 'unsupported_removable_storage',
        str_contains($message, 'backing block-device'),
        str_contains($message, 'backing devices'),
        str_contains($message, 'physical backing disk'),
        str_contains($message, 'verifiable local block device'),
        str_contains($message, 'ZFS pool identity') => 'unverified_storage_topology',
        str_contains($message, 'mount point') => 'unsupported_nested_mount',
        str_contains($message, 'Symbolic') => 'unsupported_symbolic_link',
        str_contains($message, 'filesystem boundary') => 'unsupported_cross_filesystem',
        str_contains($message, 'management is disabled') => 'volume_management_disabled',
        str_contains($message, 'selected disk or dataset') => 'invalid_volume_selection',
        str_contains($message, 'out of scope') => 'unsupported_path',
        default => 'operation_failed',
    };
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        fail('POST is required.', 405, 'method_not_allowed');
    }
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength < 0 || $contentLength > 32768) {
        fail('Request body is too large.', 413, 'request_too_large');
    }
    $c = boot();
    $cfg = $c->config();
    $sec = $c->security();
    $logger = $c->logger();
    $action = trim((string) ($_POST['action'] ?? ''));
    if (preg_match('/\A(?:inspect|recycle|restore|purge|empty|list|status|config_get|config_save|maintain_now)\z/', $action) !== 1) {
        fail('Unknown action.', 400, 'unknown_action');
    }
    // For all mutating actions we require the plugin to be enabled.
    $mutatingActions = ['inspect', 'recycle', 'restore', 'purge', 'empty', 'maintain_now'];
    if (in_array($action, $mutatingActions, true)) {
        $sec->assertEnabled($cfg);
    }

    switch ($action) {
        case 'inspect': {
            $path = (string) ($_POST['path'] ?? '');
            respond($c->recycler()->inspect($path));
        }

        case 'recycle': {
            $path = (string) ($_POST['path'] ?? '');
            $inspectionToken = (string) ($_POST['inspection_token'] ?? '');
            $r = $c->recycler()->recycle($path, $inspectionToken);
            if (!$r->ok) {
                fail($r->error ?? 'Unknown error', 409, 'recycle_failed');
            }
            respond(['ok' => true] + $r->toArray());
        }

        case 'restore': {
            $id = (string) ($_POST['id'] ?? '');
            if (!\DynamixFileRecycle\History::validId($id)) fail('Invalid item id.', 400);
            $result = $c->restorer()->restore($id);
            respond($result, ($result['ok'] ?? false) ? 200 : 409);
        }

        case 'purge': {
            $id = (string) ($_POST['id'] ?? '');
            if (!\DynamixFileRecycle\History::validId($id)) fail('Invalid item id.', 400);
            $result = $c->purger()->purgeOne($id, 'manual');
            respond($result, ($result['ok'] ?? false) ? 200 : 409);
        }

        case 'empty': {
            $vol = (string) ($_POST['volume'] ?? '');
            $volCanon = $c->fs()->normalise($vol);
            if ($volCanon === null || !is_dir($volCanon)) {
                fail('Invalid volume.', 400);
            }
            if (!$c->fs()->isApprovedVolumeRoot($volCanon)) {
                fail('Only an exact supported disk or ZFS dataset root can be emptied.', 409);
            }
            if (!$cfg->isVolumeAllowed($volCanon)) {
                fail('Recycle management is disabled for this disk or ZFS dataset. Enable it in the plugin settings and try again.', 409, 'volume_management_disabled');
            }
            respond($c->purger()->emptyVolume($volCanon));
        }

        case 'list': {
            $state = (string) ($_POST['state'] ?? 'active');
            $volume = isset($_POST['volume']) && $_POST['volume'] !== '' ? (string) $_POST['volume'] : null;
            if (!in_array($state, ['active', 'all'], true)) fail('Invalid state filter.', 400);
            if ($volume !== null && !$c->fs()->isApprovedVolumeRoot($volume)) fail('Invalid volume filter.', 400);
            $includeHistory = $cfg->getHistoryEnabled() && $state === 'all';
            $limit = max(1, min(2000, (int) ($_POST['limit'] ?? 500)));
            $offset = max(0, (int) ($_POST['offset'] ?? 0));

            if ($includeHistory) {
                $rows = $c->history()->listAll($volume, $limit, $offset);
            } else {
                $rows = $c->history()->listActive($volume, $limit, $offset);
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
            foreach ($c->fs()->supportedVolumes() as $d) {
                $rec = $d . '/' . FsInspector::RECYCLE_NAME;
                if (!is_dir($rec)) continue;
                $stats[] = [
                    'volume' => $d,
                    'fs' => $c->fs()->resolveVolume($d)['fs'] ?? 'unknown',
                    'recycle_present' => is_dir($rec),
                    'items' => $c->history()->countActive($d),
                    'size' => is_dir($rec) ? $c->fs()->dirSize($rec) : 0,
                    'total' => $c->fs()->volumeStats($d)['total'],
                    'used' => $c->fs()->volumeStats($d)['used'],
                    'management_enabled' => $cfg->isVolumeAllowed($d),
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
            $supportedVolumes = [];
            foreach ($c->fs()->supportedVolumes() as $volume) {
                $resolved = $c->fs()->resolveVolume($volume);
                $supportedVolumes[] = [
                    'path' => $volume,
                    'fs' => $resolved['fs'] ?? 'unknown',
                ];
            }
            respond([
                'ok' => true,
                'config' => $raw,
                'supported_volumes' => $supportedVolumes,
                'totals' => [
                    'items' => $c->history()->countActive(),
                    'size' => $c->history()->totalActiveSize(),
                ],
                'schema_version' => '1',
            ]);
        }

        case 'config_save': {
            // Two accepted payload shapes:
            //   1. config=<json>                 (JSON string in the 'config' field)
            //   2. config[global][enabled]=1     (PHP-style nested array)
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
                fail('A nested or JSON config payload is required.', 400, 'invalid_config');
            } else {
                fail('No config payload supplied.', 400);
            }
            $patch = $c->security()->sanitizeConfigPatch($payload);
            $cfg->mergeAndSave($patch);
            $c->scheduler()->sync();
            respond(['ok' => true]);
        }

        case 'maintain_now': {
            $report = $c->maintenance()->run(true);
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
    fail($e->getMessage(), $http, publicErrorCode($e));
}
