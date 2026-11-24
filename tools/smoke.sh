#!/bin/sh
# End-to-end smoke test over real HTTP.
#
# Starts PHP's built-in web server on this checkout with a throwaway config and SQLite
# database (PIKA_CONFIG points outside the repo, so config/ is untouched), then walks through
# setup, admin login, API key creation and every API endpoint with curl.
#
# Usage: tools/smoke.sh            (env: PHP=path/to/php, PORT=8089)
set -u
cd "$(dirname "$0")/.."
ROOT=$(pwd)
PHP="${PHP:-php}"
PORT="${PORT:-8089}"
BASE="http://127.0.0.1:$PORT"
WORK=$(mktemp -d "${TMPDIR:-/tmp}/pikarelay-smoke.XXXXXX")
JAR="$WORK/cookies"
OUT="$WORK/body"
PASS=0
FAIL=0

PIKA_CONFIG="$WORK/config.php" "$PHP" -S "127.0.0.1:$PORT" -t "$ROOT" "$ROOT/index.php" >"$WORK/server.log" 2>&1 &
SERVER=$!
cleanup() {
    kill "$SERVER" 2>/dev/null
    wait "$SERVER" 2>/dev/null
    rm -rf "$WORK"
}
trap cleanup EXIT INT TERM

i=0
until curl -s -o /dev/null "$BASE/assets/app.css"; do
    i=$((i + 1))
    if [ $i -gt 50 ]; then echo "Server did not start:"; cat "$WORK/server.log"; exit 1; fi
    sleep 0.1
done

# req METHOD PATH [curl args...]: sets $CODE, body in $OUT
req() {
    method=$1
    path=$2
    shift 2
    CODE=$(curl -s -o "$OUT" -w '%{http_code}' -b "$JAR" -c "$JAR" -X "$method" "$@" "$BASE$path")
}
# check NAME EXPECTED_CODE [TEXT_EXPECTED_IN_BODY]
check() {
    if [ "$CODE" = "$2" ] && { [ $# -lt 3 ] || grep -qF -- "$3" "$OUT"; }; then
        PASS=$((PASS + 1))
        printf 'ok    %s\n' "$1"
    else
        FAIL=$((FAIL + 1))
        printf 'FAIL  %s (got HTTP %s, wanted %s%s)\n' "$1" "$CODE" "$2" "${3:+ and body containing '$3'}"
        head -c 400 "$OUT"
        echo
    fi
}
csrf() {
    sed -n 's/.*name="csrf" value="\([0-9a-f]*\)".*/\1/p' "$OUT" | head -n 1
}
READER="-H X-Pika-Key:smoke-reader -H X-Pika-Secret:reader-secret-1"
CLIENT="-H X-Pika-Key:smoke-client -H X-Pika-Secret:client-secret-1"
JSON="-H Content-Type:application/json"

echo "== setup"
req GET /
check "setup page shown before configuration" 200 "Set up PikaRelay"
req GET /readerList
check "API unavailable before setup" 503 "not been set up"
req POST /setup --data-urlencode "admin_user=admin" --data-urlencode "password=short" --data-urlencode "password2=short" \
    -d driver=sqlite --data-urlencode "sqlite_path=$WORK/relay.sqlite" -d secure_mode=1
check "setup rejects a short password" 422 "Password: use 8"
req POST /setup --data-urlencode "admin_user=admin" --data-urlencode "password=smoke-pass-1" --data-urlencode "password2=smoke-pass-1" \
    -d driver=sqlite --data-urlencode "sqlite_path=$WORK/shell.php" -d secure_mode=1
check "setup rejects a non-database file name" 422 "SQLite file"
req POST /setup --data-urlencode "admin_user=admin" --data-urlencode "password=smoke-pass-1" --data-urlencode "password2=smoke-pass-1" \
    -d driver=sqlite --data-urlencode "sqlite_path=$WORK/relay.sqlite" -d secure_mode=1
check "setup completes" 200 "Setup complete"
req POST /setup -d admin_user=evil
check "setup is disabled afterwards" 404
req GET /
check "front page is now the admin login" 200 "Admin log in"

echo "== admin"
TOKEN=$(csrf)
req POST /login -d "csrf=$TOKEN" -d username=admin -d password=wrong
check "wrong password refused" 401 "Wrong user name or password"
TOKEN=$(csrf)
req POST /login -d "csrf=$TOKEN" -d username=admin -d password=smoke-pass-1
check "admin logs in" 303
req GET /admin
check "admin page loads" 200 "Create a key"
TOKEN=$(csrf)
req POST /admin/createKey -d "csrf=$TOKEN" -d role=reader -d label=Reader -d api_key=smoke-reader -d api_secret=reader-secret-1
check "reader key created" 303
req GET /admin
check "new secret shown once" 200 "reader-secret-1"
TOKEN=$(csrf)
req POST /admin/createKey -d "csrf=$TOKEN" -d role=client -d label=Client -d api_key=smoke-client -d api_secret=client-secret-1
check "client key created" 303
req POST /admin/createKey -d "csrf=forged" -d role=client -d api_key=x1234 -d api_secret=y1234
check "forged CSRF token refused" 403

echo "== API"
req GET /readerList
check "secure mode requires credentials" 401 "X-Pika-Key"
# shellcheck disable=SC2086
req PUT /readerStatus/pr4-01 $READER $JSON --data '{"unitID":"Finish","reading":true,"battery":91,"totalReads":3,"Raw Tag Reads/s":7}'
check "reader posts status" 204
# shellcheck disable=SC2086
req PUT /readerStatus/pr4-01 $CLIENT $JSON --data '{"unitID":"Finish"}'
check "client cannot post status" 403
# shellcheck disable=SC2086
req POST /putReads/pr4-01 $READER $JSON --data '[{"chip":"1001","epochMilli":1795961730123,"reader":1,"antenna":2,"rssi":-50.5,"tz":"Z"},{"chip":"1002","epochMilli":1795961730456},{"chip":"1001","epochMilli":1795961730123,"reader":1,"antenna":2,"rssi":-50.5,"tz":"Z"}]'
check "reader uploads reads (duplicate ignored)" 200 '{"received":3,"new":2}'
# shellcheck disable=SC2086
req POST /putReads/pr4-01 $READER $JSON --data '[{"chip":"<script>","epochMilli":1}]'
check "invalid read rejected" 422 "chip"
# shellcheck disable=SC2086
req POST /putReads/pr4-01 $READER -H "Content-Type: text/plain" --data '[]'
check "wrong content type rejected" 415
sleep 2.2
# shellcheck disable=SC2086
req GET /getReads/pr4-01/0 $CLIENT
check "client polls reads" 200 '"chip":"1002"'
CURSOR=$(sed -n 's/^{"serverTime":\([0-9]*\).*/\1/p' "$OUT")
# shellcheck disable=SC2086
req GET "/getReads/pr4-01/$CURSOR" $CLIENT
check "next poll from cursor is empty" 200 '"reads":[]'
# shellcheck disable=SC2086
req GET /getReads/pr4-01/1795961730000/1795961730200 $CLIENT
check "rewind by read time" 200 '"chip":"1001"'
# shellcheck disable=SC2086
req GET /readerList $CLIENT
check "reader list" 200 '"name":"Finish"'
# shellcheck disable=SC2086
req GET /readerStatus/pr4-01 $CLIENT
check "reader status" 200 '"Raw Tag Reads/s":7'
# shellcheck disable=SC2086
req POST /commands/pr4-01 $CLIENT $JSON --data '{"command":"stop"}'
check "stop command refused" 422
# shellcheck disable=SC2086
req POST /commands/pr4-01 $CLIENT $JSON --data '{"command":"forceSetProfile","profile":"Start Mats"}'
check "client queues a command" 201 '"id":'
COMMAND=$(sed -n 's/^{"id":\([0-9]*\).*/\1/p' "$OUT")
# shellcheck disable=SC2086
req GET /commands/pr4-01 $READER
check "reader sees the command" 200 '"profile":"Start Mats"'
# shellcheck disable=SC2086
req DELETE "/commands/pr4-01/$COMMAND" $READER
check "reader acknowledges it" 204
# shellcheck disable=SC2086
req GET /commands/pr4-01 $READER
check "queue is empty afterwards" 200 '"commands":[]'
# shellcheck disable=SC2086
req GET /index.php/readerList $CLIENT
check "index.php/route form works without rewriting" 200 '"readers"'

echo "== web UI and files"
req GET /
check "dashboard lists the reader" 200 "pr4-01"
req GET /assets/app.css
check "assets are served" 200 ":root"
req GET /config/probe.txt
check "config folder not served" 404
req GET /src/App.php
check "source not served" 404
req GET /
TOKEN=$(csrf)
req POST /logout -d "csrf=$TOKEN"
check "logout" 303
req GET /admin
check "admin page needs login again" 303

if grep -qiE 'fatal|warning|deprecated|notice' "$WORK/server.log"; then
    FAIL=$((FAIL + 1))
    echo "FAIL  PHP errors in the server log:"
    grep -iE 'fatal|warning|deprecated|notice' "$WORK/server.log"
fi

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
