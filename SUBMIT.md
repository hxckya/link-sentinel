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

## 4. Paid tier (Freemius) **(you: 10 minutes)**
The Pro code is built (branch `pro`, PR #1) and waits only for a Freemius
product to exist. Steps:

1. Register at https://dashboard.freemius.com/register/ with hxckya@gmail.com
   and confirm the email.
2. "Add Product" → type **WordPress plugin**, name **Link Sentinel**, slug
   **link-sentinel** (must match the WordPress.org slug).
3. Open the product → **Settings** (or the SDK integration page) and copy two
   public values: the numeric **Plugin ID** and the **Public Key** (`pk_…`).
   Do not copy the Secret Key anywhere. Send the two values to Claude; they go
   into `includes/class-linksentinel-license.php` (`FS_ID`, `FS_PUBLIC_KEY`).
4. **Plans**: create one paid plan "Pro" with yearly pricing, suggested
   1 site $29 / 3 sites $59 / unlimited $99, renewals with a 20–30% discount,
   14-day money-back. A free plan already exists by default; leave it.
5. **Payouts** are only needed before the first payout: PayPal or Wise (South
   Korea is supported). Nothing to do until money arrives.
6. Uploading the Pro ZIP (`bin/build.sh` → `dist/link-sentinel-freemius-<ver>.zip`)
   to the Freemius "Deployment" page is a Claude step once the IDs are in.

Do not merge PR #1 into `main` before the WordPress.org review finishes; the
reviewed 0.1.1 stays untouched, and 0.2.0 (free build with the SDK and the
upsell box) ships right after approval.
