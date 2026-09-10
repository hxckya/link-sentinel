#!/bin/sh
# Build the distributable ZIP exactly as WordPress.org will see it:
# everything not listed in .distignore, under a link-sentinel/ folder.
set -u
cd "$(dirname "$0")/.." || exit 1
VERSION=$(sed -n 's/^ \* Version: *//p' link-sentinel.php | tr -d '[:space:]')
rm -rf dist && mkdir -p dist/link-sentinel
rsync -a --exclude-from=.distignore --exclude=dist --exclude=bin ./ dist/link-sentinel/
( cd dist && zip -qr "link-sentinel-${VERSION}.zip" link-sentinel )
echo "dist/link-sentinel-${VERSION}.zip"
unzip -l "dist/link-sentinel-${VERSION}.zip" | tail -1
