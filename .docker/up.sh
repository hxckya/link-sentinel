#!/bin/sh
# Bring up a throwaway WordPress with the plugin mounted, install it, and
# seed posts whose links cover every verdict the checker can give.
#
# Compose project: LSN_PROJECT, else COMPOSE_PROJECT_NAME, else "docker" (the name
# compose derives from this directory, which CI and the shared dev site use).
# Anyone running a second copy alongside it (another worktree, a parallel agent)
# must pick their own, or they share and overwrite the same containers and volumes:
#   LSN_PROJECT=lsmine LSN_PORT=8123 .docker/up.sh && LSN_PROJECT=lsmine .docker/seed.sh
# LSN_PORT is the host port (default 8089); two stacks cannot both publish the same one.
set -u
cd "$(dirname "$0")" || exit 1
LSN_PROJECT="${LSN_PROJECT:-${COMPOSE_PROJECT_NAME:-docker}}"
LSN_PORT="${LSN_PORT:-8089}"
export LSN_PORT
dc() { docker compose -p "$LSN_PROJECT" "$@"; }
dc up -d db wp || exit 1
for i in $(seq 1 40); do
  if dc run --rm -T cli core is-installed >/dev/null 2>&1; then echo "WordPress already installed"; break; fi
  if dc exec -T wp test -f /var/www/html/wp-config.php 2>/dev/null; then
    dc run --rm -T cli core install --url="http://localhost:${LSN_PORT}" --title="Link Sentinel Dev" --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email && break
  fi
  sleep 3
done
# The site URL is localhost:$LSN_PORT as seen from the host; let Apache answer on that port inside too, so loopback checks behave like production.
dc exec -T wp sh -c "grep -q 'Listen ${LSN_PORT}' /etc/apache2/ports.conf || { echo 'Listen ${LSN_PORT}' >> /etc/apache2/ports.conf; apache2ctl graceful; }"
dc run --rm -T cli plugin activate link-sentinel
dc run --rm -T cli option update permalink_structure '/%postname%/'
dc run --rm -T cli rewrite flush --hard >/dev/null 2>&1
echo "http://localhost:${LSN_PORT}/wp-admin  (admin / admin), compose project ${LSN_PROJECT}"
