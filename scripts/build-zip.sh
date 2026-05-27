#!/usr/bin/env bash
# Build a WordPress-installable zip of rolepod-wp (the WordPress arm of the
# Rolepod ecosystem: https://github.com/nuttaruj/rolepod).
#
# Usage:
#   ./scripts/build-zip.sh              # writes dist/rolepod-wp.zip
#   ./scripts/build-zip.sh --upload     # also uploads to the matching gh release
#
# The zip filename intentionally has no version suffix so the GitHub release
# URL `releases/latest/download/rolepod-wp.zip` stays stable across versions —
# that URL is hard-coded in the MCP-side install guidance
# (rolepod-wplab/src/companion/constants.ts → COMPANION_INSTALL_URL).
#
# The zip contains only the runtime files a WordPress install needs.
# Excluded: .git, tests/, .github/, scripts/, CHANGELOG.md, README.md (the
# README + CHANGELOG live on github; including them in every install bloats
# wp-content/plugins/ for no user benefit).
set -euo pipefail

cd "$(dirname "$0")/.."
PLUGIN_DIR="rolepod-wp"
VERSION=$(grep -E "ROLEPOD_WP_VERSION'," rolepod-wp.php | sed -E "s/.*'([0-9]+\.[0-9]+\.[0-9]+)'.*/\1/")
if [ -z "$VERSION" ]; then
  echo "error: could not read version from rolepod-wp.php" >&2
  exit 1
fi

DIST_DIR="dist"
STAGE_DIR="$DIST_DIR/$PLUGIN_DIR"
ZIP_PATH="$DIST_DIR/$PLUGIN_DIR.zip"

echo "Building $PLUGIN_DIR v$VERSION → $ZIP_PATH"

rm -rf "$DIST_DIR"
mkdir -p "$STAGE_DIR"

# Copy runtime files into a clean top-level dir so the zip unpacks as
# wp-content/plugins/rolepod-wp/* (standard WP plugin layout).
cp rolepod-wp.php "$STAGE_DIR/"
cp uninstall.php "$STAGE_DIR/"
cp LICENSE "$STAGE_DIR/"
cp -R src "$STAGE_DIR/"
cp -R guardian "$STAGE_DIR/"

# Sanity check: must contain the main bootstrap + Pair endpoint + guardian.
test -f "$STAGE_DIR/rolepod-wp.php" || { echo "missing bootstrap"; exit 1; }
test -f "$STAGE_DIR/src/Endpoint/Pair.php" || { echo "missing Pair.php"; exit 1; }
test -f "$STAGE_DIR/src/Security/PairToken.php" || { echo "missing PairToken.php"; exit 1; }
test -f "$STAGE_DIR/guardian/rolepod-wp-guardian.php" || { echo "missing guardian"; exit 1; }
test -f "$STAGE_DIR/src/Guardian.php" || { echo "missing Guardian controller"; exit 1; }

(cd "$DIST_DIR" && zip -rq "$(basename "$ZIP_PATH")" "$PLUGIN_DIR")
SIZE=$(wc -c < "$ZIP_PATH" | tr -d ' ')
echo "✓ built $ZIP_PATH ($SIZE bytes) — version $VERSION"

if [ "${1:-}" = "--upload" ]; then
  TAG="v$VERSION"
  echo "Uploading to gh release $TAG..."
  gh release upload "$TAG" "$ZIP_PATH" --clobber
  echo "✓ uploaded $ZIP_PATH to release $TAG"
fi
