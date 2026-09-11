#!/bin/sh
# Build both distributables:
#   dist/link-sentinel-<ver>.zip      free, exactly as WordPress.org will see it (no *__premium_only* files)
#   dist/link-sentinel-pro-<ver>.zip  Pro, for Freemius (folder link-sentinel-pro, same main file)
set -u
cd "$(dirname "$0")/.." || exit 1
VERSION=$(sed -n 's/^ \* Version: *//p' link-sentinel.php | tr -d '[:space:]')
rm -rf dist && mkdir -p dist/link-sentinel dist/link-sentinel-pro
# .distignore (also used by the WordPress.org deploy) already leaves the premium files out; the Pro build lifts that one rule.
rsync -a --exclude-from=.distignore --exclude=dist --exclude=bin ./ dist/link-sentinel/
grep -v premium_only .distignore > dist/.distignore-pro
rsync -a --exclude-from=dist/.distignore-pro --exclude=dist --exclude=bin ./ dist/link-sentinel-pro/
( cd dist && zip -qr "link-sentinel-${VERSION}.zip" link-sentinel && zip -qr "link-sentinel-pro-${VERSION}.zip" link-sentinel-pro )
# Freemius deployments take the full source under the free slug's folder name and derive both versions themselves.
rm -rf dist/upload && mkdir -p dist/upload && cp -R dist/link-sentinel-pro dist/upload/link-sentinel
( cd dist/upload && zip -qr "../link-sentinel-freemius-${VERSION}.zip" link-sentinel ) && rm -rf dist/upload
if unzip -l "dist/link-sentinel-${VERSION}.zip" | grep -q premium_only; then echo "free build contains premium files" >&2; exit 1; fi
for z in "dist/link-sentinel-${VERSION}.zip" "dist/link-sentinel-pro-${VERSION}.zip" "dist/link-sentinel-freemius-${VERSION}.zip"; do
  echo "$z"; unzip -l "$z" | tail -1
done
