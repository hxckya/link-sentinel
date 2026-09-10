=== Link Sentinel – Broken Link Checker ===
Contributors: hxckya
Tags: broken links, link checker, 404, dead links, seo
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Finds broken links and images across your site and helps you fix them. Runs on your own server, no cloud account, and tells bot-blocked sites apart from dead ones.

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

Every edit goes through the normal WordPress save, so revisions keep the previous version.

**Fast.** Internal links are answered from the database without a request. External links are fetched in parallel, HEAD first, GET only where a server refuses HEAD. A link checked recently is not fetched again until the interval you set.

**Free means free.** Everything above is in this plugin. There is no cloud tier hiding the results.

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

Post types you select (posts and pages by default), custom links in navigation menus, and approved comments if enabled. Links inside `<a>`, `<img>`, `srcset`, `<iframe>`, `<video>`, `<audio>`, `<source>` and `<embed>` are found.

= Does it send my links anywhere? =

No. Requests go from your server directly to the linked sites, with a user agent you can change under *Settings*.

== Screenshots ==

1. The broken links table with fix actions.
2. Scan progress panel.
3. Settings.

== Changelog ==

= 0.1.0 =
* First release.
