#!/usr/bin/env bash
#
# Enforce ADR-005: the LMS and social plugins never reference each other's
# namespace. The bridge may reference both.
#
# Exit 1 with the offending lines when a boundary is crossed.

set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
STATUS=0

check() {
	local dir="$1" pattern="$2" label="$3"

	if [ ! -d "$dir" ]; then
		return
	fi

	local hits
	hits="$(grep -rnE "$pattern" "$dir" --include='*.php' --include='*.js' --include='*.jsx' --include='*.ts' --include='*.tsx' || true)"

	if [ -n "$hits" ]; then
		echo "ADR-005 violation: $label"
		echo "$hits"
		STATUS=1
	fi
}

check "$ROOT/plugins/odsi-lms"    'ODSI\\+Social|odsi_social_|odsi-social/v1' 'odsi-lms references the social plugin'
check "$ROOT/plugins/odsi-social" 'ODSI\\+LMS|odsi_lms_|odsi-lms/v1'          'odsi-social references the LMS plugin'

if [ "$STATUS" -eq 0 ]; then
	echo "Plugin boundaries intact."
fi

exit "$STATUS"
