# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added (planned 1.0.0)
- First public release.
- "Move to Recycle Bin" button injected into the Dynamix File Manager Browse
  page via the official `Menu='Buttons'` channel — no Unraid core file is
  modified.
- Per-volume `.RecycleBin` directory for `/mnt/disk*` data drives and ZFS
  datasets.
- SQLite history store with `state` field (`active | restored | purged`).
- Settings page (English + 中文) covering: feature toggle, log level, log
  retention, age-based eviction, capacity threshold eviction (percent or
  absolute), scheduled cleanup interval, optional auto-empty, history toggle,
  language.
- Tools → Recycle Bin browser page for browsing / restoring / purging items.
- `scripts/recycle-maintain` invoked by Unraid cron for background
  maintenance.
- CSRF protection delegated to Unraid's `auto_prepend`; the plugin never
  re-implements CSRF.
