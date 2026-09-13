=== Link Sentinel – Broken Link Checker ===
Contributors: hxckya
Tags: broken links, link checker, 404, dead links, seo
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Finds broken links and images and helps you fix them in place. Runs on your own server, no cloud account, and tells bot-blocked from dead.

== Description ==

Link Sentinel scans your posts, pages, custom post types, menus and (optionally) comments for links and images, checks every one of them, and gives you a table of what is broken — with the tools to fix it without opening each post.

**Runs where your content is.** Nothing is sent to a third-party service. No account, no API key, no monthly quota. The scan runs in small steps in the background, so it also works on shared hosting.

**Knows the difference between dead and defensive.** LinkedIn, Cloudflare-protected sites and many shops answer automated requests with 403 or 429 while working fine for visitors. Other checkers report those as broken, and you stop trusting the list. Link Sentinel lists them under *Blocked* instead, and only calls a link *Broken* on a 404/410 or when it has been unreachable for three checks in a row.

**Fix in place.**

* **Edit URL** — change a link everywhere it appears, in one go.
* **Use final URL** — replace a redirecting link with its destination.
* **Unlink** — remove the link and keep the text.
* **Dismiss** — hide what you have decided to leave alone.
* **Re-check** — one link, or a selection.

**Tells you when it matters.** After a scheduled scan that finds broken links you get one plain email listing them, and nothing when all is well. From the command line, `wp link-sentinel scan` exits non-zero when something is broken, so it fits a cron job or CI.

Every edit goes through the normal WordPress save, so revisions keep the previous version.

**Fast.** Internal links are answered from the database without a request. External links are fetched in parallel, HEAD first, GET only where a server refuses HEAD. A link checked recently is not fetched again until the interval you set.

**Free means free.** Everything above is in this plugin. There is no cloud tier hiding the results.


= Link Sentinel Pro =

The free plugin is complete and stays that way. Pro adds what agencies and larger sites asked for: redirects for dead URLs on your own site (visitors and search engines land on a page that works instead of a 404), custom-field scanning (ACF, Elementor and other page-builder data, SEO plugin fields, all fixable in place), Slack, Discord and webhook alerts, CSV export of any view, and hourly or twice-daily scans.
== Installation ==

1. Install and activate the plugin.
2. Go to *Link Sentinel* in the admin menu and click *Scan now*.
3. You can leave the page — the scan continues through WP-Cron.
4. Set the automatic schedule under *Link Sentinel → Settings*.

== Frequently Asked Questions ==

= Does it slow down my site? =

Scanning runs in short background steps (a few seconds each) and never on the front end. Parallel requests default to 8; lower it under *Settings* on a very small hosting plan.

= Why is a link listed as Blocked rather than Broken? =

The server answered 401, 403, 429 or 999: it refuses automated visitors. That is not evidence the page is gone. Open it in a browser to be sure. If you would rather treat these as broken, there is a setting for that.

= What does "Unreachable" mean? =

A timeout, DNS failure, TLS problem or 5xx. These are often temporary, so a link only becomes *Broken* after three consecutive failures.

= Which content is scanned? =

Post types you select (posts and pages by default), custom links in navigation menus, block widgets, category/tag/taxonomy descriptions, and approved comments if enabled. Links inside `<a>`, `<img>`, `srcset`, `<iframe>`, `<video>`, `<audio>`, `<source>` and `<embed>` are found.

= Can I import my settings from the Broken Link Checker plugin by WPMU DEV? =

Yes. While that plugin's settings are still on the site, *Link Sentinel → Settings* offers a one-time import. It first shows what would change, item by item, and you can untick any of them:

* the content and post statuses to scan;
* the scan and re-check interval: the re-check interval, the schedule of its Cloud scanner if it used one, and manual scans if its background checking was turned off (otherwise your automatic scan schedule stays as it is);
* exclusion entries that name a domain or URL, as "Never check" rules (plain words, and entries with `*`, which that plugin matched as literal text, are left out);
* the timeout;
* the email report, on or off, and its address;
* dismissed links. That plugin showed a dismissed link again when its status changed; Link Sentinel stops checking a dismissed link until you restore it, so this item starts unticked.

Links marked "Not broken" are not imported; Link Sentinel checks them like any other link. A change that would scan more often while the email report is on also starts unticked, because the report is sent after every scheduled scan that finds broken links. What has no equivalent here is listed and left out. That plugin's own settings and data are only read, never changed. Run the import before you delete that plugin, because deleting it removes its data.

= Does it send my links anywhere? =

No. Requests go from your server directly to the linked sites, with a user agent you can change under *Settings*.

== Screenshots ==

1. Broken links, with where each one appears and the fix actions (edit URL everywhere, unlink, dismiss, re-check).
2. Redirecting links show their final destination; one click swaps the link for it.
3. Sites that refuse automated requests are listed as Blocked, not Broken.
4. Settings: what to scan, schedule, timeouts, exclusions.

== Changelog ==

= 0.1.1 =
* Email report after a scheduled scan finds broken links (Settings → Email report).
* Block widgets and category/tag/taxonomy descriptions are scanned and fixable too.
* WP-CLI: `wp link-sentinel scan|status|list`.
* Edit URL opens a dialog instead of a browser prompt.

= 0.1.0 =
* First release.
