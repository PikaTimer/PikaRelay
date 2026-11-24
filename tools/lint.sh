#!/bin/sh
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
