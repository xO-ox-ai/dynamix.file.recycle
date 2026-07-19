# Design document — Dynamix File Recycle Bin

> This document captures the architecture decisions, the verified Unraid
> mechanisms, and how the implementation maps to the 10-rule front-end guide.
> Bilingual section headings are inline; the prose is English-first.

## 1. Goals

| Goal | Implementation |
|---|---|
| Add a "Move to Recycle Bin" button to DFM Browse | `RecycleInject.page` (`Menu='Buttons:5'`) + `javascript/recycle.js` |
| One recycle bin per volume; v1 = `/mnt/disk*` + ZFS datasets | `FsInspector::resolveVolume` / `recycleRoot` |
| Configurable maintenance (age / capacity / log level / log retention / auto-empty) | `Maintenance.php`, `Config.php`, `settings.page` |
| Tools → Recycle Bin browser, with history field for state | `RecycleBin.page`, SQLite `items.state` |
| Compliant with CA + Unraid CSRF | `.plg` + `plugins.json`; CSRF delegated to `auto_prepend` |
| No modification to any Unraid core file | All injection rides the official `Buttons` page channel |

## 2. Verified Unraid mechanisms

These were verified against the Unraid `webgui` source tree.

### 2.1 The `Buttons` page injection channel

`emhttp/plugins/dynamix/include/DefaultPageLayout.php` iterates over
`find_pages('Buttons')` inside `<head>` and `include`s each matched `.page`
file's body. This is exactly the channel DFM itself uses
(`BrowseButton.page`, `Menu='Buttons:3a'`).

By placing our `RecycleInject.page` at `Menu='Buttons:5'`, we ensure:

1. Our `<link>`/`<script>` is included on **every** page (cheap, ~1 KB).
2. On the Browse page specifically, we rank AFTER DFM, so `window.doAction`
   is already defined when our JS wraps it.
3. We do **not** modify any dynamix file — only ship our own `.page`.

### 2.2 CSRF

`/usr/local/emhttp/plugins/dynamix/include/local_prepend.php` is registered
via php.ini's `auto_prepend_file`. It validates `$_POST['csrf_token']` against
`/usr/local/emhttp/state/var.ini` for every POST request (except
login/auth-request), then `unset()`s the field. Failures terminate the
request with `exit()`.

Consequences for our plugin:

- `api.php` must NOT re-implement CSRF.
- The front-end must include `csrf_token` in the POST body.
- The token must be passed from PHP to JS at render time (never hardcoded).

We read the token in `RecycleInject.page` from `$var['csrf_token']` (populated
by DefaultPageLayout) and fall back to parsing `var.ini` directly. It is then
injected as `window.__recycleRuntime.csrfToken`.

### 2.3 ZFS detection

`zfs list -H -o mountpoint,name -t filesystem` is the canonical way to list
dataset mountpoints. We parse it once per request, cache it, and sort by
longest-mountpoint-first so the most specific dataset wins when paths nest.

## 3. Architecture

```
[DFM Browse render]
  -> DefaultPageLayout.php iterates find_pages('Buttons')
  -> RecycleInject.page (Buttons:5) included AFTER DFM (3a)
  -> emits <link recycle.css><script recycle.js>
     + window.__recycleRuntime { csrfToken, apiBase, scopeRoots, i18n, ... }

[recycle.js]
  -> waits for table
  -> wraps window.doAction / doActions / refreshList / renderList (rule 2.2)
  -> MutationObserver fallback (rule 2.3)
  -> ensureButton(row): idempotent, stable itemId, fixed 28x28 slot (rules 5,6)
  -> document-level event delegation for click + keydown (rule 7)
  -> click: two-step recycle protocol (see §3.1 below)

[api.php]
  -> Unraid auto_prepend verifies csrf_token (rule: do NOT re-implement CSRF)
  -> require admin (assertAdmin)
  -> dispatch: recycle | restore | purge | empty | list | status
                | config_get | config_save | maintain_now
  -> JSON response

[cron.hourly/dynamix.file.recycle]
  -> scripts/recycle-maintain
  -> php Bootstrap.php maintain
  -> Maintenance::run() (throttled by interval_hours)
     1. age eviction
     2. capacity eviction (LRU, per-volume)
     3. auto-empty cron (optional)
     4. log + history retention sweeps
     5. SQLite VACUUM (optional)
```

### 3.1 Two-step recycle protocol (cross-filesystem confirmation)

A recycle operation moves the source into `{volume}/.RecycleBin`. When the
source and destination share a filesystem, `rename()` is atomic and instant.
When they don't (e.g. a ZFS dataset's child being moved across a dataset
boundary, or an XFS file on disk1 whose `.RecycleBin` somehow lives on a
different fs), the move degrades to a recursive `cp -a` followed by `rm`,
which is slow and cannot be safely cancelled mid-way.

We never silently take that slow path. Instead the recycle endpoint follows
a two-step protocol:

```
CLIENT                          SERVER (api.php?action=recycle)
  |  POST action=recycle           |
  |  (no confirm field)            |
  |------------------------------->|
  |                                | precheck: sameFilesystem(src,dst)?
  |                                |   yes -> perform rename(), return 200 ok
  |                                |   no  -> return 202 {need_confirm, size, ...}
  |<-------------------------------|
  |  if 202:                       |
  |    show confirm dialog         |
  |    with size + warning         |
  |  user clicks OK                |
  |  POST action=recycle           |
  |       confirm=1                |
  |------------------------------->|
  |                                | confirmed=true -> perform cp + rm
  |                                | return 200 ok (or 4xx on failure)
  |<-------------------------------|
```

The first call is therefore **non-mutating** when cross-fs would be required.
The actual move only happens after the user explicitly opts in. The
generation counter in the front-end (rule 8) ensures that if the user clicks
the button again while the dialog is open, stale confirmations cannot
overwrite a newer state.


## 4. Front-end rules mapping

The 10-rule guide maps to concrete code as follows.

| Rule | Implementation |
|---|---|
| 1. Render-time integration | `decorateRows()` runs inside the wrapped DFM function (called BEFORE the DFM render completes) |
| 2.1 Use formal extension interface | None exists in DFM → wrap the next best thing |
| 2.2 Wrap the render function | `wrapDfmFunctions()` wraps `doAction/doActions/refreshList/renderList` |
| 2.3 MutationObserver as fallback | `startObserver()` with `observeReentry` guard to avoid loops |
| 3. Static button, state machine only | `data-state` attribute drives icon/disabled/title; button never added/removed by async checks |
| 4. Stable identity | `stableId(absPath)` = FNV-1a hash + length |
| 5. Idempotent reconciliation | `ensureButton` early-returns on `data-recycle-injected` |
| 6. Fixed placeholder | `.recycle-slot{position:absolute;width:28px;height:28px}` + `.recycle-cell-reserve{padding-right:44px}` |
| 7. Event delegation | `document.addEventListener('click', onClickEvent, true)` + keyboard variant |
| 8. Async race control | `generations[id]` counter; stale responses ignored |
| 9. Error preserves button | `setState(btn, STATE_ERROR)` keeps button visible, changes colour/title |
| 10. Overall pipeline | decorateRows → ensureButton (placeholder) → delegate → click → check → state update |

## 5. Acceptance criteria traceability

| Acceptance criterion | Where addressed |
|---|---|
| Button stays during auto-refresh | Observer + delegation + idempotent `ensureButton` |
| Button never duplicated | `data-recycle-injected` marker + slot query |
| No row-height / column-width shift | Fixed 28×28 slot + 44px cell reserve |
| Spinner does not enlarge button | 16×16 icon, fixed button size |
| Button remains on API failure | `setState(error)` only — never `remove()` |
| Stale responses ignored | `generations[id]` generation counter |
| Button recovers after row replace | Observer + `data-recycle-injected` re-evaluated per render |
| Self-writes don't trigger loops | `observeReentry` counter |
| Per-row state independence | Per-id generation counter |
| Keyboard + a11y | `aria-label`, `aria-disabled`, Enter/Space handler |
| Light/dark/mobile stable | CSS variables + media query for toast |

## 6. File layout (final)

```
source/dynamix.file.recycle/
├── README.page                 # Tools → About (Tools:80)
├── RecycleBin.page             # Tools → Recycle Bin (Tools:70)
├── RecycleInject.page          # Buttons:5 — the JS/CSS injection point
├── settings.page               # Settings → Dynamix File Recycle Bin (OtherSettings:30)
├── api.php                     # single POST dispatcher
├── dynamix.file.recycle.cfg.default
├── include/
│   ├── Bootstrap.php           # autoloader + CLI dispatcher + path constants
│   ├── Container.php           # tiny DI
│   ├── Config.php              # INI cfg reader/writer
│   ├── FsInspector.php         # path/volume/ZFS detection
│   ├── Security.php            # admin check, path sandbox, config sanitiser
│   ├── Logger.php              # file logger + size rotation
│   ├── I18n.php                # language file loader
│   ├── History.php             # SQLite CRUD
│   ├── Recycler.php            # move-to-bin
│   ├── RecycleResult.php       # value object
│   ├── Restorer.php            # restore-from-bin
│   ├── Purger.php              # purge / empty
│   └── Maintenance.php         # age + capacity + log sweeps
├── javascript/
│   ├── recycle.js
│   └── recycle.css
├── languages/
│   ├── en_US.txt
│   └── zh_CN.txt
├── images/
│   ├── dynamix.file.recycle.png
│   └── dynamix.file.recycle.svg
├── scripts/
│   ├── install.sh
│   ├── remove.sh
│   └── recycle-maintain
├── cron/
│   ├── dynamix.file.recycle.cron
│   └── dynamix.file.recycle.logrotate
└── sql/
    └── schema.sql
```

## 7. Risk register

| Risk | Mitigation |
|---|---|
| DFM render function names change in a future Unraid release | Observer fallback keeps the button working; wrapped-function list is configurable in `recycle.js` |
| ZFS `zfs list` is slow / unavailable | Cached per request; absent → falls back to disk-only |
| Unraid upgrade removes plugin directory | `.plg` re-installs via plugin manager; data under `/boot` survives |
| User deletes `.RecycleBin` by hand | History row stays, marked `purged` with reason `manual` when restore fails to find the file |
| CSRF token rotation mid-session | Token read at every Browse render, never cached in JS source |
| SQLite contention between web + cron | WAL mode + short transactions; purges batch by row, not by sweep lock |
