-- Dynamix File Recycle Bin SQLite schema.
-- Executed by History::initSchema() on first install or version bump.
PRAGMA journal_mode = WAL;
PRAGMA synchronous  = NORMAL;
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS items (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    item_token    TEXT    NOT NULL UNIQUE,
    volume        TEXT    NOT NULL,
    recycle_path  TEXT    NOT NULL,
    original_path TEXT    NOT NULL,
    size          INTEGER NOT NULL DEFAULT 0,
    is_dir        INTEGER NOT NULL DEFAULT 0,
    owner_uid     INTEGER,
    owner_gid     INTEGER,
    mode          INTEGER,
    mtime         INTEGER,
    deleted_at    INTEGER NOT NULL,
    state         TEXT    NOT NULL DEFAULT 'active',   -- active | restored | purged
    purged_at     INTEGER,
    purged_reason TEXT,                                  -- age | capacity | manual | restore
    meta_json     TEXT
);
CREATE INDEX IF NOT EXISTS idx_items_volume_state ON items(volume, state);
CREATE INDEX IF NOT EXISTS idx_items_deleted_at   ON items(deleted_at);
CREATE INDEX IF NOT EXISTS idx_items_state        ON items(state);

CREATE TABLE IF NOT EXISTS log (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    ts      INTEGER NOT NULL,
    level   TEXT    NOT NULL,            -- ERROR | WARN | INFO | DEBUG
    action  TEXT,
    path    TEXT,
    message TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_log_ts     ON log(ts);
CREATE INDEX IF NOT EXISTS idx_log_level  ON log(level);

CREATE TABLE IF NOT EXISTS meta (
    k TEXT PRIMARY KEY,
    v TEXT
);

INSERT OR IGNORE INTO meta(k, v) VALUES('schema_version', '1');
INSERT OR IGNORE INTO meta(k, v) VALUES('created_at', strftime('%s','now'));
