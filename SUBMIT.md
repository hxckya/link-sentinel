# Submitting Link Sentinel to WordPress.org

Steps that only the account holder can do are marked **(you)**. Everything
else is scripted or already done.

## 1. Account **(you)**
Create a WordPress.org account at https://login.wordpress.org/register if you
do not have one. The username becomes the plugin's `Contributors:` entry
(currently `hxckya` in `readme.txt` — change it there if the account name differs).

## 2. Submit the ZIP **(you)**
1. Download `link-sentinel-<version>.zip` from the latest GitHub release
   (or run `bin/build.sh`).
2. Go to https://wordpress.org/plugins/developers/add/ and upload it.
3. The review queue answers by email, usually within a few days to two weeks.
   Reply to any review email from the same address. If they ask for code
   changes, paste the email here and the fix will be prepared.

## 3. After approval
You receive an SVN URL like `https://plugins.svn.wordpress.org/link-sentinel`.

1. **(you)** In the GitHub repository → Settings → Secrets and variables →
   Actions, add `SVN_USERNAME` (your wordpress.org username) and
   `SVN_PASSWORD` (an SVN/application password from your wordpress.org
   profile, https://profiles.wordpress.org/me/profile/edit/group/3/?screen=svn-password).
2. Pushing a `v*` tag then publishes the release and the listing assets
   (`.wporg-assets/` — banners, icon, screenshots) automatically via
   `.github/workflows/deploy.yml`. The workflow refuses a tag unless the
   `Version` header, `LINKSENTINEL_VERSION` and readme `Stable tag` all equal
   the tag without its `v`.
3. To rehearse first: Actions → Deploy to WordPress.org → Run workflow, enter an
   existing tag, keep "Dry run" ticked. The log ends with `svn status` (what
   would be committed) and "Dry run: Files not committed." It needs no secrets,
   only the SVN repository that approval creates. Unticked, the same run
   publishes that tag (for example `v0.1.1` if 0.2.0 has to wait).

## 4. Paid tier (Freemius) — done 2026-09-11
Product 39272 in store 19938 (slug `link-sentinel`, paid slug
`link-sentinel-premium`). Plan "Pro": $29 / $59 / $99 per year for 1 / 3 /
unlimited sites, 14-day money-back, features kept after expiry (updates and
support stop), plans released, version 0.1.1 deployed and released. The plugin
id and public key live in `includes/class-linksentinel-license.php`.

Still yours:
- **Payout details** (PayPal or Wise) under the Freemius account menu, needed
  only before the first payout.
- **Support**: the plan's "Provide Support" switch is off, so buyers get no
  contact form. Turn it on if you want Claude to draft replies to support
  emails for you.

Release flow for a new version: `bin/build.sh`, upload
`dist/link-sentinel-freemius-<ver>.zip` on the Deployment page (the input is the
`.zip` file input; the first file input on that page is an image uploader), then
set the row to Released. `uninstall.php` must stay absent: Freemius refuses it.

Do not merge PR #1 into `main` before the WordPress.org review finishes; the
reviewed 0.1.1 stays untouched, and 0.2.0 (free build with the SDK and the
upsell box) ships right after approval.
