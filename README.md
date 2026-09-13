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

## Pro

The free plugin is complete and stays that way. Link Sentinel Pro adds, for
agencies and larger sites:

- **Redirects for dead URLs on your own site.** A broken internal link usually
  means a page that moved; one click sends visitors and search engines from the
  old address to the new one. Rules apply only to addresses that would 404, so
  they can never shadow a page that exists. Hit counts, a Redirects page.
  Sites that used the closed Quick Page/Post Redirect Plugin get an importer
  for its Quick and per-page redirects: a dry run first (conflicts, pages that
  still exist, what cannot be mapped and why), then import; its data is left
  untouched.
- **Custom-field scanning.** ACF fields, Elementor and other page-builder data,
  SEO plugin fields — serialized and JSON values included — and the same
  in-place fixes write back through the structure without corrupting it.
- **Slack, Discord and webhook alerts** after scheduled scans.
- **CSV export** of any view.
- **Hourly and twice-daily scans.**

Pro is sold through Freemius (checkout, licences and updates); the link will
appear here and in the plugin once the free version is listed on
WordPress.org. Source for both lives in this repository; the premium files
sit under `includes/pro__premium_only/` and are left out of the free build.

## Development

```bash
.docker/up.sh        # WordPress 7.x + MariaDB in Docker, plugin mounted, admin/admin
.docker/seed.sh      # posts whose links cover every verdict
docker compose -f .docker/docker-compose.yml run --rm -T cli eval-file wp-content/plugins/link-sentinel/tests/run.php
```

Open http://localhost:8089/wp-admin → Link Sentinel.

## License

GPL-2.0-or-later.
