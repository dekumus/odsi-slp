#!/usr/bin/env bash
#
# Build installable plugin zips into dist/: one per plugin, containing only
# what a site needs (no sources for the editor bundles, no dev files).
#
#   bin/package.sh            # builds assets, then zips all three plugins
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIST="${ODSI_DIST_DIR:-$ROOT/dist}"

cd "$ROOT"
npm run -s build
mkdir -p "$DIST"

for plugin in odsi-lms odsi-social odsi-bridge; do
	version="$(sed -n 's/^ \* Version: *\([0-9.]*\).*/\1/p' "plugins/$plugin/$plugin.php" | head -1)"
	out="$DIST/$plugin-${version:-dev}.zip"
	rm -f "$out"
	( cd plugins && zip -qr "$out" "$plugin" \
		-x "$plugin/assets/src/*" \
		-x "$plugin/node_modules/*" \
		-x "$plugin/composer.json" \
		-x "$plugin/.gitignore" \
		-x "*/.DS_Store" )
	echo "$out"
done
