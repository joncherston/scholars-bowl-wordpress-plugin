#!/bin/sh
# Create a local WordPress at http://localhost:8089 with the plugin active and fictional data.
# Dev-only login: admin / dev-admin-pass   (never used anywhere real)
set -e
cd "$(dirname "$0")"
docker compose up -d --wait db wordpress
wp() { docker compose run --rm -T cli wp "$@"; }
until wp core is-installed >/dev/null 2>&1 || wp db check >/dev/null 2>&1; do sleep 2; done
if ! wp core is-installed >/dev/null 2>&1; then
	wp core install --url=http://localhost:8089 --title="SBR Dev" --admin_user=admin --admin_password=dev-admin-pass --admin_email=admin@example.test --skip-email
	wp rewrite structure '/%postname%/' --hard
fi
wp plugin activate scholars-bowl-registration
docker compose exec -T db mariadb -uwp -pwp-dev-only wordpress < seed.sql
wp post list --post_type=page --name=register --field=ID | grep -q . || \
	wp post create --post_type=page --post_status=publish --post_title=Register --post_name=register --post_content='[scholars_bowl_registration event="hs-2026-dev"]'
echo "Ready: http://localhost:8089 (admin / dev-admin-pass), form at /register/"
