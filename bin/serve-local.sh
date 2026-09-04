#!/usr/bin/env bash
#
# Run a throwaway WordPress on PHP's built-in server, without Docker, for
# manual testing and the E2E suite in environments where wp-env cannot run.
#
# Reuses the core checkout from bin/install-wp-tests.sh and the same database
# settings. Installs WordPress, symlinks the three plugins into it, activates
# them, sets pretty permalinks, and serves on http://127.0.0.1:8080.
#
#   bin/serve-local.sh            # start (foreground)
#   bin/serve-local.sh --install  # install only, do not serve

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SITE_DIR="${ODSI_SITE_DIR:-/tmp/odsi-site}"
CORE_DIR="${ODSI_CORE_DIR:-/tmp/wordpress}"
PORT="${ODSI_SITE_PORT:-8080}"
DB_NAME="${ODSI_SITE_DB_NAME:-odsi_site}"
DB_USER="${WP_TESTS_DB_USER:-wp}"
DB_PASS="${WP_TESTS_DB_PASS:-wp}"
DB_HOST="${WP_TESTS_DB_HOST:-127.0.0.1}"
WP_CLI="${ROOT}/.cache/wp-cli.phar"

if [ ! -f "$CORE_DIR/wp-includes/version.php" ]; then
	echo "WordPress core not found at $CORE_DIR; run bin/install-wp-tests.sh first." >&2
	exit 1
fi

mkdir -p "$ROOT/.cache"
if [ ! -f "$WP_CLI" ]; then
	echo "Fetching wp-cli"
	curl -sSL -o "$WP_CLI" https://github.com/wp-cli/wp-cli/releases/latest/download/wp-cli.phar
fi
wp() { php "$WP_CLI" --path="$SITE_DIR" --allow-root "$@"; }

if [ ! -f "$SITE_DIR/wp-config.php" ]; then
	echo "Installing site into $SITE_DIR"
	mkdir -p "$SITE_DIR"
	cp -r "$CORE_DIR"/. "$SITE_DIR"/
	rm -rf "$SITE_DIR/.git"

	mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`;"

	wp config create --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASS" --dbhost="$DB_HOST" \
		--extra-php <<'PHP'
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_DEBUG_LOG', true );
define( 'SCRIPT_DEBUG', true );
define( 'ODSI_E2E', true );
PHP

	wp core install --url="http://127.0.0.1:${PORT}" --title="ODSI Dev" \
		--admin_user=admin --admin_password=password --admin_email=admin@example.org --skip-email

	for slug in odsi-lms odsi-social odsi-bridge; do
		if [ -f "$ROOT/plugins/$slug/$slug.php" ]; then
			ln -sfn "$ROOT/plugins/$slug" "$SITE_DIR/wp-content/plugins/$slug"
		fi
	done

	wp plugin activate odsi-lms || true
	wp plugin activate odsi-social || true
	wp plugin activate odsi-bridge || true
	wp rewrite structure '/%postname%/' --hard
fi

if [ "${1:-}" = "--install" ]; then
	echo "Site ready at $SITE_DIR"
	exit 0
fi

echo "Serving http://127.0.0.1:${PORT}  (admin / password)"
cd "$SITE_DIR"
exec php -S "127.0.0.1:${PORT}" -t "$SITE_DIR" "$ROOT/bin/router.php"
