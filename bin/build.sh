#!/bin/sh
# Build both distributables:
#   dist/link-sentinel-<ver>.zip      free, exactly as WordPress.org will see it (no *__premium_only* files)
#   dist/link-sentinel-premium-<ver>.zip  Pro, folder link-sentinel-premium (Freemius' paid-version slug), same main file
#   dist/link-sentinel-freemius-<ver>.zip full source under link-sentinel/, for the Freemius deployment upload
set -u
cd "$(dirname "$0")/.." || exit 1
VERSION=$(sed -n 's/^ \* Version: *//p' link-sentinel.php | tr -d '[:space:]')
rm -rf dist && mkdir -p dist/link-sentinel dist/link-sentinel-premium
# .distignore (also used by the WordPress.org deploy) already leaves the premium files out; the Pro build lifts that one rule.
rsync -a --exclude-from=.distignore --exclude=dist --exclude=bin ./ dist/link-sentinel/
grep -v premium_only .distignore > dist/.distignore-pro
rsync -a --exclude-from=dist/.distignore-pro --exclude=dist --exclude=bin ./ dist/link-sentinel-premium/
( cd dist && zip -qr "link-sentinel-${VERSION}.zip" link-sentinel && zip -qr "link-sentinel-premium-${VERSION}.zip" link-sentinel-premium )
# Freemius deployments take the full source under the free slug's folder name and derive both versions themselves.
rm -rf dist/upload && mkdir -p dist/upload && cp -R dist/link-sentinel-premium dist/upload/link-sentinel
( cd dist/upload && zip -qr "../link-sentinel-freemius-${VERSION}.zip" link-sentinel ) && rm -rf dist/upload
if unzip -l "dist/link-sentinel-${VERSION}.zip" | grep -q premium_only; then echo "free build contains premium files" >&2; exit 1; fi
for z in "dist/link-sentinel-${VERSION}.zip" "dist/link-sentinel-premium-${VERSION}.zip" "dist/link-sentinel-freemius-${VERSION}.zip"; do
  echo "$z"; unzip -l "$z" | tail -1
done
