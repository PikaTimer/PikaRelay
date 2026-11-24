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

# Syntax-checks every PHP file with the PHP binary in $PHP (default: php), then runs the
# PHPCompatibility checks for PHP 7.4-8.4.
set -e
cd "$(dirname "$0")/.."
PHP="${PHP:-php}"
status=0
for f in $(find index.php src tests tools -name '*.php' -not -path './vendor/*'); do
    out=$("$PHP" -l "$f" 2>&1) || { echo "$out"; status=1; }
done
[ $status -eq 0 ] && echo "php -l: all files OK ($("$PHP" -r 'echo PHP_VERSION;'))"
"$PHP" vendor/bin/phpcs --standard=phpcs.xml || status=1
exit $status
