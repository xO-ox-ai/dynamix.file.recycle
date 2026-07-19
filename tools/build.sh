#!/bin/bash
# tools/build.sh — package the plugin into a versioned .txz and patch the
# .plg file's <MD5> placeholder with the package hash. Run from a Linux/WSL
# environment that has tar + xz + md5sum.
#
# Usage:
#   PLUGIN_VERSION=1.0.0 tools/build.sh
#
# Output:
#   build/dynamix.file.recycle-1.0.0.txz     (the package)
#   build/dynamix.file.recycle.plg           (the .plg with MD5 patched)
set -euo pipefail

VERSION="${PLUGIN_VERSION:-1.0.0}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

BUILD_DIR="$ROOT/build"
PKG_NAME="dynamix.file.recycle"
SRC="$ROOT/source/$PKG_NAME"
STAGE="$BUILD_DIR/stage/$PKG_NAME"
OUT_TXZ="$BUILD_DIR/$PKG_NAME-$VERSION.txz"
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

# Make scripts executable.
chmod 0755 "$STAGE"/scripts/*.sh "$STAGE"/scripts/recycle-maintain 2>/dev/null || true
chmod 0755 "$STAGE"/cron/dynamix.file.recycle.cron 2>/dev/null || true

# Drop any developer-only files from staging.
rm -f "$STAGE/images/README.md" 2>/dev/null || true

echo "==> Creating $OUT_TXZ"
# Tarball paths must be relative so they extract to
# /usr/local/emhttp/plugins/dynamix.file.recycle/ when unpacked from /.
# We pack from build/stage so the leading path is dynamix.file.recycle/.
( cd "$BUILD_DIR/stage" && tar -cJf "$OUT_TXZ" "$PKG_NAME" )

echo "==> Computing MD5"
MD5="$(md5sum "$OUT_TXZ" | awk '{print $1}')"
echo "    MD5 = $MD5"

echo "==> Patching .plg"
# Substitute the version and MD5 placeholders.
sed \
    -e "s|<!ENTITY version   \"[^\"]*\">|<!ENTITY version   \"$VERSION\">|g" \
    -e "s|<MD5>[^<]*</MD5>|<MD5>$MD5</MD5>|g" \
    "$ROOT/$PKG_NAME.plg" > "$OUT_PLG"

# Also update the version in plugins.json.
python3 - <<PY
import json, sys
p = "plugins.json"
data = json.load(open(p, encoding="utf-8"))
data["version"] = "$VERSION"
data["last_update"] = "$(date +%F)"
json.dump(data, open(p, "w", encoding="utf-8"), indent=2, ensure_ascii=False)
PY

echo
echo "Done. Release artefacts:"
echo "  $OUT_TXZ"
echo "  $OUT_PLG"
echo
echo "Next steps:"
echo "  1. Create a git tag v$VERSION and push it."
echo "  2. Create a GitHub release v$VERSION and attach the .txz and the .plg."
echo "  3. Submit $OUT_PLG URL to Community Applications."
