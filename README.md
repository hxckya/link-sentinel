# Link Sentinel – Broken Link Checker for WordPress

Finds broken links and images across a WordPress site and helps fix them in
place. Runs on the site's own server — no cloud account — and tells
bot-blocked sites (403/429/999) apart from dead ones (404/410), so the list
stays trustworthy.

- Scans posts, pages, custom post types, menu links, and optionally comments.
- Internal links are answered from the database (rewrite rules, uploads on
  disk); external ones are fetched in parallel, HEAD first, GET on refusal.
- Fix actions: edit URL everywhere, use a redirect's final URL, unlink
  keeping the text, dismiss, re-check. Edits keep revisions.
- Background scanning in short steps (WP-Cron plus the admin page), so it
  works on shared hosting.

See [`readme.txt`](readme.txt) for the WordPress.org description.

## Development

```bash
.docker/up.sh        # WordPress 7.x + MariaDB in Docker, plugin mounted, admin/admin
.docker/seed.sh      # posts whose links cover every verdict
docker compose -f .docker/docker-compose.yml run --rm -T cli eval-file wp-content/plugins/link-sentinel/tests/run.php
```

Open http://localhost:8089/wp-admin → Link Sentinel.

## License

GPL-2.0-or-later.
