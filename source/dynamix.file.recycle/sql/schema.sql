-- Informational copy of the per-volume schema used by History.php.
PRAGMA journal_mode = WAL;
PRAGMA synchronous = FULL;
PRAGMA busy_timeout = 5000;

CREATE TABLE IF NOT EXISTS items (
    id            TEXT PRIMARY KEY,
    volume        TEXT NOT NULL,
    recycle_path  TEXT NOT NULL,
    original_path TEXT NOT NULL,
    display_name  TEXT,
    size          INTEGER NOT NULL DEFAULT 0,
    is_dir        INTEGER NOT NULL DEFAULT 0,
    owner_uid     INTEGER,
    owner_gid     INTEGER,
    mode          INTEGER,
    mtime         INTEGER,
    deleted_at    INTEGER NOT NULL,
    state         TEXT NOT NULL DEFAULT 'active',
    purged_at     INTEGER,
    purged_reason TEXT,
    operation_target TEXT,
    meta_json     TEXT
);
CREATE INDEX IF NOT EXISTS idx_items_state_deleted ON items(state, deleted_at);
CREATE INDEX IF NOT EXISTS idx_items_display_name ON items(display_name);

CREATE TABLE IF NOT EXISTS events (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    ts      INTEGER NOT NULL,
    level   TEXT NOT NULL,
    action  TEXT,
    path    TEXT,
    message TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_events_ts ON events(ts);

CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT);
INSERT INTO meta(k, v) VALUES('schema_version', '3')
ON CONFLICT(k) DO UPDATE SET v=excluded.v;
