#!/usr/bin/env bash
# Build a clean, WordPress.org-ready stats-umami.zip from this dev tree.
# Copies everything except the patterns in .distignore, then zips it up
# under a top-level stats-umami/ folder (the shape WP.org and wp-admin's
# "Upload Plugin" both expect). Zero runtime dependencies are shipped.
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="stats-umami"
ZIP_PATH="$PLUGIN_DIR/$SLUG.zip"

BUILD_ROOT="$(mktemp -d)"
trap 'rm -rf "$BUILD_ROOT"' EXIT
BUILD_DIR="$BUILD_ROOT/$SLUG"
mkdir -p "$BUILD_DIR"

rsync -a --exclude-from="$PLUGIN_DIR/.distignore" "$PLUGIN_DIR/" "$BUILD_DIR/"

rm -f "$ZIP_PATH"
( cd "$BUILD_ROOT" && zip -r -q "$ZIP_PATH" "$SLUG" )

echo "Built: $ZIP_PATH"
echo
echo "Contents:"
unzip -l "$ZIP_PATH"
