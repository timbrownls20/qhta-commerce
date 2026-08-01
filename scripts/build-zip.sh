#!/usr/bin/env bash
#
# Produce the deploy zip for wp-admin -> Plugins -> Add New -> Upload Plugin.
#
# Reads the version out of the plugin header rather than taking it as an
# argument, and refuses to build unless the header and QHTA_COMMERCE_VERSION
# agree. Same guard as qhta-theme-extras: a mismatch means the two sources of
# truth for "what version is live" have drifted.
#
# Usage: ./scripts/build-zip.sh

set -euo pipefail

PLUGIN_SLUG="qhta-commerce"
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PARENT_DIR="$(dirname "$PLUGIN_DIR")"
BOOTSTRAP="$PLUGIN_DIR/$PLUGIN_SLUG.php"

# Version: 1.0.0  ->  1.0.0
header_version="$(sed -n "s/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([0-9][^[:space:]]*\).*/\1/p" "$BOOTSTRAP")"

# define( 'QHTA_COMMERCE_VERSION', '1.0.0' );  ->  1.0.0
const_version="$(sed -n "s/^define([[:space:]]*'QHTA_COMMERCE_VERSION',[[:space:]]*'\([^']*\)'.*/\1/p" "$BOOTSTRAP")"

if [[ -z "$header_version" || -z "$const_version" ]]; then
	echo "error: could not read the version from $BOOTSTRAP" >&2
	echo "       header='$header_version' constant='$const_version'" >&2
	exit 1
fi

if [[ "$header_version" != "$const_version" ]]; then
	echo "error: version mismatch — bump both before deploying." >&2
	echo "       plugin header:          $header_version" >&2
	echo "       QHTA_COMMERCE_VERSION:  $const_version" >&2
	exit 1
fi

VERSION="$header_version"
ZIP_PATH="$PLUGIN_DIR/$PLUGIN_SLUG-$VERSION.zip"

# Syntax-check every PHP file so a typo cannot reach the live site. This plugin
# hooks template_redirect on every front-end page, so a parse error here takes
# the whole site down, not just one stylesheet.
if command -v php >/dev/null 2>&1; then
	while IFS= read -r -d '' php_file; do
		php -l "$php_file" >/dev/null
	done < <(find "$PLUGIN_DIR" -name '*.php' -not -path '*/.git/*' -print0)
else
	echo "note: php not on PATH, skipping syntax check" >&2
fi

rm -f "$ZIP_PATH"

# WordPress needs the plugin folder as the top level inside the archive, so zip
# has to run from the parent. The output lands in the plugin root, which is
# inside the tree being zipped — build to a temp file and move it in afterwards
# so the archive cannot swallow itself.
staging_dir="$(mktemp -d)"
trap 'rm -rf "$staging_dir"' EXIT
staging_zip="$staging_dir/$PLUGIN_SLUG-$VERSION.zip"

# Excludes: editor cruft, git metadata, local Claude settings (permission
# allowlist, not for the web server), previous builds, the handover notes and
# this build tooling.
cd "$PARENT_DIR"
zip -rq "$staging_zip" "$PLUGIN_SLUG" \
	-x "*.DS_Store" \
	   "*.git*" \
	   "*.claude*" \
	   "*.zip" \
	   "$PLUGIN_SLUG/scripts/*" \
	   "$PLUGIN_SLUG/$PLUGIN_SLUG-handover.md"

mv "$staging_zip" "$ZIP_PATH"

echo "built $ZIP_PATH ($VERSION)"
unzip -l "$ZIP_PATH"

cat <<EOF

Next:
  1. wp-admin -> Plugins -> Add New -> Upload Plugin -> replace -> activate
  2. hPanel -> Websites -> qhta.com.au -> Advanced -> Cache Manager -> Purge All
  3. Confirm gated pages are excluded from full-page caching, My Account too
  4. Smoke test one gated page: logged out -> redirected; buyer -> renders
EOF
