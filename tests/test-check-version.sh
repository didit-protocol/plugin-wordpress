#!/usr/bin/env bash
# The release check refuses a tag that does not match the plugin version.
set -euo pipefail
cd "$(dirname "$0")/.."
version=$(sed -n "s/^define('DIDIT_VERIFY_VERSION',[[:space:]]*'\([^']*\)');.*/\1/p" didit-verify.php)
if ! bin/check-version.sh "v$version" >/dev/null; then echo "FAIL the matching tag v$version was refused"; exit 1; fi
echo "PASS the matching tag v$version is accepted"
if bin/check-version.sh "v0.0.1" >/dev/null 2>&1; then echo "FAIL a wrong tag was accepted"; exit 1; fi
echo "PASS a wrong tag is refused"
if bin/check-version.sh "latest" >/dev/null 2>&1; then echo "FAIL a non-version tag was accepted"; exit 1; fi
echo "PASS a tag that is not a version is refused"
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
mkdir -p "$tmp/bin"
cp bin/check-version.sh "$tmp/bin/"
sed "s/^Stable tag:.*/Stable tag: 9.9.9/" readme.txt > "$tmp/readme.txt"
cp didit-verify.php "$tmp/"
if "$tmp/bin/check-version.sh" >/dev/null 2>&1; then echo "FAIL a stale Stable tag was accepted"; exit 1; fi
echo "PASS a readme Stable tag that differs from the plugin header is refused"
