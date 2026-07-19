#!/bin/bash
# tools/build.sh — package the plugin into a versioned .txz and patch the
# .plg file's <SHA256> value with the package hash. Run from a Linux/WSL
# environment that has GNU tar + xz + sha256sum.
#
# Usage:
#   PLUGIN_VERSION=2026.07.19c tools/build.sh
#
# Output:
#   build/dynamix.file.recycle-YYYY.MM.DDx-x86_64-1.txz
#   build/dynamix.file.recycle.plg
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
VERSION="${PLUGIN_VERSION:-$(tr -d '[:space:]' < "$ROOT/VERSION")}"

if [[ ! "$VERSION" =~ ^[0-9]{4}\.[0-9]{2}\.[0-9]{2}[a-z]$ ]]; then
    echo "Invalid release version: $VERSION" >&2
    exit 1
fi

BUILD_DIR="$ROOT/build"
PKG_NAME="dynamix.file.recycle"
SRC="$ROOT/source/$PKG_NAME"
STAGE_ROOT="$BUILD_DIR/stage"
STAGE="$STAGE_ROOT/usr/local/emhttp/plugins/$PKG_NAME"
OUT_TXZ="$BUILD_DIR/$PKG_NAME-$VERSION-x86_64-1.txz"
OUT_PLG="$BUILD_DIR/$PKG_NAME.plg"

if [[ ! -d "$SRC" ]]; then
    echo "Source directory not found: $SRC" >&2
    exit 1
fi

echo "==> Cleaning build dir"
rm -rf "$BUILD_DIR"
mkdir -p "$STAGE"

echo "==> Staging plugin files"
# Copy the plugin directory as it should appear on the device under
# /usr/local/emhttp/plugins/dynamix.file.recycle/.
cp -a "$SRC/." "$STAGE/"
cp -f "$ROOT/VERSION" "$STAGE/VERSION"

# Make scripts executable.
chmod 0755 "$STAGE"/scripts/*.sh "$STAGE"/scripts/recycle-maintain 2>/dev/null || true
chmod 0755 "$STAGE"/cron/dynamix.file.recycle.cron 2>/dev/null || true

# Drop any developer-only files from staging.
rm -f "$STAGE/images/README.md" 2>/dev/null || true
rm -rf "$STAGE/cron"

# Minimal Slackware package metadata so upgradepkg/removepkg can track the
# installed version cleanly.
mkdir -p "$STAGE_ROOT/install"
cat > "$STAGE_ROOT/install/slack-desc" <<EOF
dynamix.file.recycle: Dynamix File Recycle Bin
dynamix.file.recycle:
dynamix.file.recycle: Conservative per-volume recycle support for Unraid DFM.
dynamix.file.recycle:
dynamix.file.recycle: Version $VERSION
EOF

echo "==> Creating $OUT_TXZ"
# Include the complete filesystem path and normalize metadata so identical
# sources produce identical packages.
( cd "$STAGE_ROOT" && tar \
    --sort=name \
    --mtime='UTC 1970-01-01' \
    --owner=0 --group=0 --numeric-owner \
    -cJf "$OUT_TXZ" install usr )

echo "==> Computing SHA256"
SHA256="$(sha256sum "$OUT_TXZ" | awk '{print $1}')"
echo "    SHA256 = $SHA256"

echo "==> Patching .plg"
# Substitute the version and SHA256 value. We write the patched copy to
# build/ AND overwrite the in-repo .plg so what gets committed matches the
# published release exactly.
sed \
    -e "s|<!ENTITY version   \"[^\"]*\">|<!ENTITY version   \"$VERSION\">|g" \
    -e "s|<SHA256>[^<]*</SHA256>|<SHA256>$SHA256</SHA256>|g" \
    -e "s|EXPECTED_SHA256=\"[0-9a-f]*\"|EXPECTED_SHA256=\"$SHA256\"|g" \
    -e "s|dynamix\.file\.recycle-[0-9]\{4\}\.[0-9]\{2\}\.[0-9]\{2\}[a-z]-x86_64-1\.txz|dynamix.file.recycle-$VERSION-x86_64-1.txz|g" \
    -e "s|dynamix\.file\.recycle-[0-9]\{4\}\.[0-9]\{2\}\.[0-9]\{2\}[a-z]-x86_64-1\"|dynamix.file.recycle-$VERSION-x86_64-1\"|g" \
    "$ROOT/$PKG_NAME.plg" > "$OUT_PLG"
cp -f "$OUT_PLG" "$ROOT/$PKG_NAME.plg"

# Also update the version in plugins.json. Find a working Python: try `python`
# first (the Windows Store `python3` stub is a no-op), then fall back.
PY_BIN=""
for cand in python python3 python2; do
    if command -v "$cand" >/dev/null 2>&1 && "$cand" -c 'print("ok")' >/dev/null 2>&1; then
        PY_BIN="$cand"
        break
    fi
done
if [ -n "$PY_BIN" ]; then
    "$PY_BIN" - <<PY
import json
p = "plugins.json"
data = json.load(open(p, encoding="utf-8"))
data["version"] = "$VERSION"
data["last_update"] = "$(date +%F)"
with open(p, "w", encoding="utf-8") as fh:
    json.dump(data, fh, indent=2, ensure_ascii=False)
    fh.write("\n")
PY
else
    echo "WARNING: no working python interpreter; plugins.json not updated" >&2
fi

echo
echo "Done. Release artefacts:"
echo "  $OUT_TXZ"
echo "  $OUT_PLG"
echo
echo "Next steps:"
echo "  1. Create a git tag $VERSION and push it."
echo "  2. Create a GitHub release $VERSION and attach the .txz and the .plg."
echo "  3. Submit $OUT_PLG URL to Community Applications."
