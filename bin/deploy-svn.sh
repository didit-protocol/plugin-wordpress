#!/usr/bin/env bash
# Publish build/didit-verify/ to the WordPress.org plugin SVN: trunk, tags/VERSION and
# assets/ (banners, icons, screenshots from .wordpress-org/).
#
# Environment:
#   VERSION       version to tag (required)
#   DRY_RUN       "true" stages everything and prints the changes without committing
#   SVN_USERNAME  WordPress.org account with commit access (required unless DRY_RUN)
#   SVN_PASSWORD  its SVN password (required unless DRY_RUN)
set -euo pipefail
cd "$(dirname "$0")/.."
slug=didit-verify
url="https://plugins.svn.wordpress.org/$slug"
: "${VERSION:?VERSION is required}"
dry_run="${DRY_RUN:-true}"

[ -d "build/$slug" ] || { echo "::error::Run bin/build-zip.sh first."; exit 1; }
command -v svn >/dev/null || { sudo apt-get update -qq && sudo apt-get install -y -qq subversion >/dev/null; }

rm -rf build/svn
svn checkout -q --depth immediates "$url" build/svn
svn update -q --set-depth infinity build/svn/trunk build/svn/assets
svn update -q --set-depth immediates build/svn/tags

if [ -d "build/svn/tags/$VERSION" ]; then
  echo "::error::tags/$VERSION already exists on WordPress.org. Bump the version before releasing."
  exit 1
fi

rsync -rc --delete --exclude=.svn "build/$slug/" build/svn/trunk/
rsync -rc --delete --exclude=.svn .wordpress-org/ build/svn/assets/

cd build/svn
svn add --force --quiet trunk assets
svn status | awk '/^!/ {print $2}' | while read -r gone; do svn rm --quiet "$gone"; done
for ext in png:image/png jpg:image/jpeg gif:image/gif svg:image/svg+xml; do
  files=$(find assets -maxdepth 1 -name "*.${ext%%:*}" | tr '\n' ' ')
  [ -n "$files" ] && svn propset -q svn:mime-type "${ext#*:}" $files
done
svn cp -q trunk "tags/$VERSION"
echo "Changes for $slug $VERSION:"
svn status

if [ "$dry_run" = "true" ]; then
  echo "Dry run: nothing was committed to WordPress.org."
  exit 0
fi
: "${SVN_USERNAME:?SVN_USERNAME is required}"
: "${SVN_PASSWORD:?SVN_PASSWORD is required}"
svn commit -q -m "Release $VERSION" --non-interactive --no-auth-cache \
  --username "$SVN_USERNAME" --password "$SVN_PASSWORD"
echo "Released $slug $VERSION to WordPress.org."
