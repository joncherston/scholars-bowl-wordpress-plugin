#!/bin/sh
# Build releases/scholars-bowl-registration-<version>.zip from the source folder,
# with the version read from the plugin header, and the child theme's
# releases/generatepress-scholars-bowl-<version>.zip (version from its style.css).
# Prints each zip's SHA-256. An argument overrides the plugin version in the file name.
set -e
cd "$(dirname "$0")"
V=$(sed -n 's/^ \* Version: *//p' scholars-bowl-registration/scholars-bowl-registration.php | tr -d '\r' | head -1)
[ -n "$V" ] || { echo "Could not read the plugin version"; exit 1; }
mkdir -p releases
OUT="releases/scholars-bowl-registration-${1:-$V}.zip"
rm -f "$OUT"
zip -qrX "$OUT" scholars-bowl-registration -x '*.DS_Store' '__MACOSX/*'
shasum -a 256 "$OUT" | tee "$OUT.sha256"

if [ -d theme/generatepress-scholars-bowl ]; then
	TV=$(sed -n 's/^Version: *//p' theme/generatepress-scholars-bowl/style.css | tr -d '\r' | head -1)
	[ -n "$TV" ] || { echo "Could not read the theme version"; exit 1; }
	TOUT="releases/generatepress-scholars-bowl-$TV.zip"
	rm -f "$TOUT"
	(cd theme && zip -qrX "../$TOUT" generatepress-scholars-bowl -x '*.DS_Store' '__MACOSX/*')
	shasum -a 256 "$TOUT" | tee "$TOUT.sha256"
fi
