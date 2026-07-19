<?php

declare(strict_types=1);

use DynamixFileRecycle\Config;
use DynamixFileRecycle\SqliteConnection;
use DynamixFileRecycle\SqliteStatement;

$root = dirname(__DIR__);
require_once $root . '/source/dynamix.file.recycle/include/Config.php';
require_once $root . '/source/dynamix.file.recycle/include/SqliteResult.php';
require_once $root . '/source/dynamix.file.recycle/include/SqliteStatement.php';
require_once $root . '/source/dynamix.file.recycle/include/SqliteConnection.php';

function checkSqlite(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$temp = sys_get_temp_dir() . '/dynamix-file-recycle-sqlite-' . bin2hex(random_bytes(5));
mkdir($temp, 0700, true);
$dbFile = $temp . '/history.sqlite';
$configFile = $temp . '/dynamix.file.recycle.cfg';

try {
    $db = new SqliteConnection($dbFile);
    $db->exec('CREATE TABLE items (id TEXT PRIMARY KEY, path TEXT NOT NULL, size INTEGER NOT NULL, state TEXT NOT NULL)');
    $insert = $db->prepare('INSERT INTO items(id,path,size,state) VALUES(:id,:path,:size,:state)');
    $path = "/mnt/disk1/TV_series/a'quote;line\nsecond.txt";
    $insert->execute([':id' => 'item-1', ':path' => $path, ':size' => 3, ':state' => 'active']);
    checkSqlite($insert->rowCount() === 1, 'CLI insert did not report one affected row');

    $select = $db->prepare('SELECT path,size,state FROM items WHERE id=:id');
    $select->execute([':id' => 'item-1']);
    $row = $select->fetch();
    checkSqlite(is_array($row) && $row['path'] === $path, 'CLI text parameter round-trip failed');
    checkSqlite((int) $row['size'] === 3 && $row['state'] === 'active', 'CLI row values are incorrect');

    $update = $db->prepare('UPDATE items SET state=:state WHERE id=:id');
    $update->execute([':state' => 'restored', ':id' => 'item-1']);
    checkSqlite($update->rowCount() === 1, 'CLI update did not report one affected row');
    checkSqlite((string) $db->query('SELECT COUNT(*) AS count FROM items')->fetchColumn() === '1', 'CLI aggregate query failed');

    $limited = $db->prepare('SELECT id FROM items ORDER BY id LIMIT :limit');
    $limited->bindValue(':limit', 1, SqliteStatement::PARAM_INT);
    $limited->execute();
    checkSqlite(count($limited->fetchAll()) === 1, 'CLI integer binding failed');
    checkSqlite($db->exec("DELETE FROM items WHERE state='restored'") === 1, 'CLI direct delete did not report one affected row');

    $config = new Config($configFile, $root . '/source/dynamix.file.recycle/dynamix.file.recycle.cfg.default');
    $expected = ['/mnt/disk1', '/mnt/disk1/TV_series'];
    $config->mergeAndSave(['volumes' => ['allowed' => json_encode($expected, JSON_UNESCAPED_SLASHES)]]);
    $reloaded = new Config($configFile, $root . '/source/dynamix.file.recycle/dynamix.file.recycle.cfg.default');
    checkSqlite($reloaded->getAllowedVolumes() === $expected, 'INI JSON volume selection did not round-trip');
    checkSqlite(($reloaded->raw()['volumes']['allowed'] ?? '') === json_encode($expected, JSON_UNESCAPED_SLASHES), 'Saved volume policy still contains INI escape slashes');
} finally {
    foreach (glob($temp . '/*') ?: [] as $file) @unlink($file);
    @rmdir($temp);
}

echo "SQLite CLI and config round-trip tests passed.\n";
