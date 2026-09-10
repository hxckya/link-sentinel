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
   `.github/workflows/deploy.yml`.

## 4. Later: paid tier
Only after the free plugin is live: create a Freemius account
(https://dashboard.freemius.com/register/), add the product, and enter payout
details. The Pro features are built on top of that SDK.
