#!/bin/sh
# Local WordPress without Docker: WordPress + the SQLite Database Integration plugin, both
# cloned from GitHub, served by `php -S`. Development only; never used on the live site.
#
#   ./dev/setup-local.sh            # creates ./.wp (git-ignored), installs 1.0.6, seeds, upgrades to the source
#   ./dev/setup-local.sh --no-106   # skip the 1.0.6 step (plugin source from the start)
#
# Then run the tests (see README.md, "Developing"). Login: admin / dev-admin-pass.
# Needs: php 8.x with pdo_sqlite, git, curl, unzip.
set -e
cd "$(dirname "$0")/.."
REPO=$(pwd)
WPDIR="$REPO/.wp"
PORT=${PORT:-8089}
WP_TAG=${WP_TAG:-6.9.11}
SQLITE_TAG=${SQLITE_TAG:-v3.1.2}
mkdir -p "$WPDIR"
[ -f "$WPDIR/wp-cli.phar" ] || curl -sSL -o "$WPDIR/wp-cli.phar" https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
wp() { php -d memory_limit=512M "$WPDIR/wp-cli.phar" --allow-root --path="$WPDIR/wp" "$@"; }

if [ ! -d "$WPDIR/wp" ]; then
	git clone -q --depth 1 --branch "$WP_TAG" https://github.com/WordPress/WordPress.git "$WPDIR/wp"
	git clone -q --depth 1 --branch "$SQLITE_TAG" https://github.com/WordPress/sqlite-database-integration.git "$WPDIR/sqlite"
	P="$WPDIR/wp/wp-content/plugins/sqlite-database-integration"
	cp -rL "$WPDIR/sqlite/packages/plugin-sqlite-database-integration" "$P"
	sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$P#" -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#g" "$P/db.copy" > "$WPDIR/wp/wp-content/db.php"
	cat > "$WPDIR/wp/wp-config.php" <<EOF
<?php
define('DB_NAME', 'wordpress'); define('DB_USER', 'x'); define('DB_PASSWORD', 'x'); define('DB_HOST', 'localhost');
define('DB_CHARSET', 'utf8mb4'); define('DB_COLLATE', '');
define('DB_DIR', __DIR__ . '/wp-content/database/'); define('DB_FILE', 'wp.sqlite');
foreach (array('AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT') as \$k) define(\$k, 'dev-only-' . \$k);
\$table_prefix = 'wp_';
define('WP_DEBUG', true); define('WP_DEBUG_LOG', '$WPDIR/debug.log'); define('WP_DEBUG_DISPLAY', false);
define('DISABLE_WP_CRON', true);
if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
require_once ABSPATH . 'wp-settings.php';
EOF
	wp core install --url="http://localhost:$PORT" --title="SU Scholars' Bowl" --admin_user=admin --admin_password=dev-admin-pass --admin_email=admin@example.test --skip-email
	wp option update timezone_string America/Chicago
	wp option update blogdescription "Southern Union State Community College"
	wp rewrite structure '/%postname%/'
	# GeneratePress itself comes from wordpress.org; use it if you have it, else the markup stand-in.
	[ -d "$WPDIR/wp/wp-content/themes/generatepress" ] || cp -r "$REPO/dev/generatepress-standin" "$WPDIR/wp/wp-content/themes/generatepress"
	ln -sfn "$REPO/theme/generatepress-scholars-bowl" "$WPDIR/wp/wp-content/themes/generatepress-scholars-bowl"
	wp theme activate generatepress-scholars-bowl
	mkdir -p "$WPDIR/wp/wp-content/mu-plugins" && cp "$REPO/dev/mail-log-mu-plugin.php" "$WPDIR/wp/wp-content/mu-plugins/"
fi

PLUGIN="$WPDIR/wp/wp-content/plugins/scholars-bowl-registration"
rm -rf "$PLUGIN"
if [ "$1" != "--no-106" ]; then
	# A 1.0.6-shaped database: install the 1.0.6 release, seed, then switch to the source (an upgrade).
	rm -rf "$WPDIR/r106" && mkdir "$WPDIR/r106" && unzip -q releases/scholars-bowl-registration-1.0.6.zip -d "$WPDIR/r106" -x '__MACOSX/*'
	cp -r "$WPDIR/r106/scholars-bowl-registration" "$PLUGIN"
	wp plugin activate scholars-bowl-registration
	wp eval-file dev/seed.php
	rm -rf "$PLUGIN"
fi
ln -s "$REPO/scholars-bowl-registration" "$PLUGIN"
wp plugin activate scholars-bowl-registration >/dev/null 2>&1 || true
[ "$1" = "--no-106" ] && wp eval-file dev/seed.php
wp post list --post_type=page --name=register --field=ID | grep -q . || \
	wp post create --post_type=page --post_status=publish --post_title=Register --post_name=register --post_content='[scholars_bowl_registration event="hs-2026-dev"]'
wp post list --post_type=page --name=complete-payment --field=ID | grep -q . || \
	wp post create --post_type=page --post_status=publish --post_title="Complete Payment" --post_name=complete-payment --post_content='[scholars_bowl_complete_payment]'
echo "Start the site:  SBR_WP_DIR=$WPDIR/wp PHP_CLI_SERVER_WORKERS=4 php -S localhost:$PORT -t $WPDIR/wp dev/php-server-router.php"
echo "wp-cli:          php $WPDIR/wp-cli.phar --allow-root --path=$WPDIR/wp <command>"
