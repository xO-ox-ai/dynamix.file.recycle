#!/bin/bash
# Install hook for Dynamix File Recycle Bin.
# Invoked by the Unraid plugin manager AFTER the .txz has been extracted into
# the root filesystem. Idempotent — safe to re-run.
set -euo pipefail

PLUGIN_DIR="/usr/local/emhttp/plugins/dynamix.file.recycle"
CFG_DIR="/boot/config/plugins/dynamix.file.recycle"
STATE_DIR="/usr/local/emhttp/state/plugins/dynamix.file.recycle"
LOG_DIR="$STATE_DIR/logs"
AUDIT_DIR="$CFG_DIR/logs"
RUN_DIR="/run/dynamix.file.recycle"

# 1. Persistent config dir (under /boot so it survives reboot).
mkdir -p "$CFG_DIR"
if [[ ! -f "$CFG_DIR/dynamix.file.recycle.cfg" ]]; then
  cp -f "$PLUGIN_DIR/dynamix.file.recycle.cfg.default" \
        "$CFG_DIR/dynamix.file.recycle.cfg" 2>/dev/null || true
fi

# 2. Volatile cache/runtime plus bounded persistent audit directory.
install -d -m 0700 "$STATE_DIR" "$LOG_DIR" "$RUN_DIR" "$AUDIT_DIR"

# 3. Initialise runtime state and reconcile any currently available shards.
if [[ -x /usr/local/bin/php ]]; then
  /usr/local/bin/php "$PLUGIN_DIR/include/Bootstrap.php" init
fi

# 4. Make the scheduled-cleanup wrapper executable.
if [[ -f "$PLUGIN_DIR/scripts/recycle-maintain" ]]; then
  chmod 0755 "$PLUGIN_DIR/scripts/recycle-maintain"
fi

# 5. Remove unconditional jobs left by pre-2026.07.19b builds.
rm -f /etc/cron.hourly/dynamix.file.recycle
rm -f /etc/logrotate.d/dynamix.file.recycle

# 6. Generate a cron entry only when the user configured a cleanup schedule.
if [[ -x /usr/local/bin/php ]]; then
  /usr/local/bin/php "$PLUGIN_DIR/include/Bootstrap.php" sync-cron
fi

echo "Dynamix File Recycle Bin installed."
echo "  Open Tools -> Recycle Bin to browse the bin."
echo "  Open Settings -> Dynamix File Recycle Bin to enable the feature."
