#!/usr/bin/env bash
# Build a WordPress-installable zip of rolepod-wplab-companion.
#
# Usage:
#   ./scripts/build-zip.sh              # writes dist/rolepod-wplab-companion-<version>.zip
#   ./scripts/build-zip.sh --upload     # also uploads to the matching gh release
#
# The zip contains only the runtime files a WordPress install needs.
# Excluded: .git, tests/, .github/, scripts/, CHANGELOG.md, README.md (the
# README + CHANGELOG live on github; including them in every install bloats
# wp-content/plugins/ for no user benefit).
set -euo pipefail

cd "$(dirname "$0")/.."
PLUGIN_DIR="rolepod-wplab-companion"
VERSION=$(grep -E "ROLEPOD_WPLAB_COMPANION_VERSION'," rolepod-wplab-companion.php | sed -E "s/.*'([0-9]+\.[0-9]+\.[0-9]+)'.*/\1/")
if [ -z "$VERSION" ]; then
  echo "error: could not read version from rolepod-wplab-companion.php" >&2
  exit 1
fi

DIST_DIR="dist"
STAGE_DIR="$DIST_DIR/$PLUGIN_DIR"
ZIP_PATH="$DIST_DIR/$PLUGIN_DIR-$VERSION.zip"

echo "Building $PLUGIN_DIR v$VERSION → $ZIP_PATH"

rm -rf "$DIST_DIR"
mkdir -p "$STAGE_DIR"

# Copy runtime files into a clean top-level dir so the zip unpacks as
# wp-content/plugins/rolepod-wplab-companion/* (standard WP plugin layout).
cp rolepod-wplab-companion.php "$STAGE_DIR/"
cp uninstall.php "$STAGE_DIR/"
cp LICENSE "$STAGE_DIR/"
cp -R src "$STAGE_DIR/"

# Sanity check: must contain the main bootstrap + Pair endpoint.
test -f "$STAGE_DIR/rolepod-wplab-companion.php" || { echo "missing bootstrap"; exit 1; }
test -f "$STAGE_DIR/src/Endpoint/Pair.php" || { echo "missing Pair.php"; exit 1; }
test -f "$STAGE_DIR/src/Security/PairToken.php" || { echo "missing PairToken.php"; exit 1; }

(cd "$DIST_DIR" && zip -rq "$(basename "$ZIP_PATH")" "$PLUGIN_DIR")
SIZE=$(wc -c < "$ZIP_PATH" | tr -d ' ')
echo "✓ built $ZIP_PATH ($SIZE bytes)"

if [ "${1:-}" = "--upload" ]; then
  TAG="v$VERSION"
  echo "Uploading to gh release $TAG..."
  gh release upload "$TAG" "$ZIP_PATH" --clobber
  echo "✓ uploaded $ZIP_PATH to release $TAG"
fi
