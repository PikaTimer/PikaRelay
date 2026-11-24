#!/bin/sh
# Copyright (C) 2026 John Garner
#
# This program is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with this program.  If not, see <https://www.gnu.org/licenses/>.

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
