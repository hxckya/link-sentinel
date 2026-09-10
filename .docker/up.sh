#!/bin/sh
# Bring up a throwaway WordPress with the plugin mounted, install it, and
# seed posts whose links cover every verdict the checker can give.
set -u
cd "$(dirname "$0")" || exit 1
docker compose up -d db wp || exit 1
for i in $(seq 1 40); do
  if docker compose run --rm -T cli core is-installed >/dev/null 2>&1; then echo "WordPress already installed"; break; fi
  if docker compose exec -T wp test -f /var/www/html/wp-config.php 2>/dev/null; then
    docker compose run --rm -T cli core install --url=http://localhost:8089 --title="Link Sentinel Dev" --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email && break
  fi
  sleep 3
done
# The site URL is localhost:8089 as seen from the host; let Apache answer on that port inside too, so loopback checks behave like production.
docker compose exec -T wp sh -c 'grep -q "Listen 8089" /etc/apache2/ports.conf || { echo "Listen 8089" >> /etc/apache2/ports.conf; apache2ctl graceful; }'
docker compose run --rm -T cli plugin activate link-sentinel
docker compose run --rm -T cli option update permalink_structure '/%postname%/'
docker compose run --rm -T cli rewrite flush --hard >/dev/null 2>&1
echo "http://localhost:8089/wp-admin  (admin / admin)"
