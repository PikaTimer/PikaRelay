#!/bin/sh
# Builds build/pikarelay-<version>.zip from the committed HEAD, ready to upload to a web host.
# Development-only files are left out via export-ignore in .gitattributes.
set -e
cd "$(dirname "$0")/.."
if [ -n "$(git status --porcelain)" ]; then
    echo "Warning: uncommitted changes are NOT included; the zip is built from HEAD." >&2
fi
VERSION="${1:-$(git describe --tags --always)}"
mkdir -p build
git archive --format=zip --prefix=pikarelay/ -o "build/pikarelay-$VERSION.zip" HEAD
echo "build/pikarelay-$VERSION.zip"
