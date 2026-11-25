#!/usr/bin/env bash
# Build the release folder and zip that WordPress.org receives, leaving out what .distignore lists.
#   bin/build-zip.sh   ->  build/didit-verify/ and build/didit-verify.zip
set -euo pipefail
cd "$(dirname "$0")/.."
slug=didit-verify
rm -rf build
mkdir -p "build/$slug"
rsync -a --exclude-from=.distignore ./ "build/$slug/"
(cd build && zip -qr "$slug.zip" "$slug")
echo "Built build/$slug.zip with $(find "build/$slug" -type f | wc -l) files."
