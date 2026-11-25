#!/usr/bin/env bash
# Check that every place naming the plugin version agrees, and with the release tag if given.
#   bin/check-version.sh            # header, constant and readme "Stable tag" agree
#   bin/check-version.sh v0.4.0     # ...and they equal the tag
set -euo pipefail
cd "$(dirname "$0")/.."

header=$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' didit-verify.php | head -n1)
constant=$(sed -n "s/^define('DIDIT_VERIFY_VERSION',[[:space:]]*'\([^']*\)');.*/\1/p" didit-verify.php | head -n1)
stable=$(sed -n 's/^Stable tag:[[:space:]]*\([^[:space:]]*\).*/\1/p' readme.txt | head -n1)

status=0
report() { echo "::error::$1"; status=1; }

[ -n "$header" ] || report "No 'Version:' in the didit-verify.php header."
[ -n "$constant" ] || report "No DIDIT_VERIFY_VERSION constant in didit-verify.php."
[ -n "$stable" ] || report "No 'Stable tag:' in readme.txt."
[ "$header" = "$constant" ] || report "Plugin header Version ($header) and DIDIT_VERIFY_VERSION ($constant) differ."
[ "$header" = "$stable" ] || report "Plugin header Version ($header) and readme.txt Stable tag ($stable) differ."
if ! grep -q "^= $header =" readme.txt; then
  report "readme.txt has no changelog entry '= $header ='."
fi

if [ "$#" -gt 0 ]; then
  tag="$1"
  if ! printf '%s' "$tag" | grep -Eq '^v?[0-9]+\.[0-9]+\.[0-9]+$'; then
    report "Tag '$tag' is not a version like v1.2.3."
  elif [ "${tag#v}" != "$header" ]; then
    report "Tag $tag does not match the plugin version $header."
  fi
fi

[ "$status" -eq 0 ] && echo "Version $header agrees everywhere${1:+ and with tag $1}."
exit "$status"
