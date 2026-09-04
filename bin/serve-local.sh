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
	curl -sSL -o "$WP_CLI.sha512" https://github.com/wp-cli/wp-cli/releases/latest/download/wp-cli.phar.sha512
	if ! echo "$(cat "$WP_CLI.sha512")  $WP_CLI" | sha512sum -c --quiet -; then
		echo "wp-cli.phar checksum mismatch; refusing to run it." >&2
		rm -f "$WP_CLI" "$WP_CLI.sha512"
		exit 1
	fi
fi
wp() { php "$WP_CLI" --path="$SITE_DIR" --allow-root "$@"; }

MARKER="$SITE_DIR/.odsi-local-site"

# Install only into an empty directory or one this script created earlier.
# A real site (anything else) is never overwritten.
if [ ! -f "$MARKER" ]; then
	if [ -e "$SITE_DIR" ] && [ -n "$(ls -A "$SITE_DIR" 2>/dev/null)" ]; then
		echo "$SITE_DIR exists and was not created by this script; refusing to overwrite it." >&2
		echo "Point ODSI_SITE_DIR at an empty directory." >&2
		exit 1
	fi

	echo "Installing site into $SITE_DIR"
	mkdir -p "$SITE_DIR"
	cp -r "$CORE_DIR"/. "$SITE_DIR"/
	rm -rf "$SITE_DIR/.git"
	touch "$MARKER"

	MYSQL_PWD="$DB_PASS" mysql -h "$DB_HOST" -u "$DB_USER" -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`;"

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
