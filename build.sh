#!/bin/sh
# Build releases/scholars-bowl-registration-<version>.zip from the source folder,
# with the version read from the plugin header. Prints the zip's SHA-256.
set -e
cd "$(dirname "$0")"
V=$(sed -n 's/^ \* Version: *//p' scholars-bowl-registration/scholars-bowl-registration.php | tr -d '\r' | head -1)
[ -n "$V" ] || { echo "Could not read the plugin version"; exit 1; }
mkdir -p releases
OUT="releases/scholars-bowl-registration-${1:-$V}.zip"
rm -f "$OUT"
zip -qrX "$OUT" scholars-bowl-registration -x '*.DS_Store' '__MACOSX/*'
shasum -a 256 "$OUT" | tee "$OUT.sha256"
