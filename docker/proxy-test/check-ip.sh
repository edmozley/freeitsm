#!/bin/sh
# Which address does FreeITSM record for a sign-in, behind the proxy?
# Reads it back from system_logs, the rows System -> Logs shows. See README.md.
#
#   sh docker/proxy-test/check-ip.sh [admin-password]
#
# With TRUSTED_PROXIES naming the proxy (the stack's default), a sign-in through
# the proxy must record the caller, not the proxy, and a client-address header
# the caller wrote itself must be ignored, both through the proxy and straight
# to the app. Run with EXPECT=proxy after starting the app with TRUSTED_PROXIES=
# (empty) to check that it is off: every sign-in is then the proxy's.
BASE=https://localhost:8453
FAKE=203.0.113.9
APP=proxytest-app-1
DB=proxytest-db-1
PW=${1:-freeitsm}
FAIL=0
PROXY=$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' proxytest-proxy-1)
# Forge the header the app is actually reading.
HDR=$(docker exec "$APP" printenv TRUSTED_PROXY_HEADER 2>/dev/null)
HDR=${HDR:-X-Forwarded-For}

sql() {
    docker exec "$DB" mysql -N -ufreeitsm -pfreeitsm freeitsm 2>/dev/null -e "$1"
}
max_id() { sql "SELECT COALESCE(MAX(id), 0) FROM system_logs WHERE log_type = 'login'"; }

# The address of the sign-in row written after id $1 - empty if none was.
ip_after() {
    sql "SELECT JSON_UNQUOTE(JSON_EXTRACT(details, '\$.ip_address')) FROM system_logs
         WHERE log_type = 'login' AND id > $1 ORDER BY id DESC LIMIT 1"
}

sign_in() {  # $@ extra curl options
    JAR=$(mktemp)
    curl -sk --ssl-no-revoke -b "$JAR" -c "$JAR" -o /dev/null "$@" "$BASE/login"
    curl -sk --ssl-no-revoke -b "$JAR" -c "$JAR" -o /dev/null "$@" \
        --data-urlencode username=admin --data-urlencode "password=$PW" "$BASE/login"
    rm -f "$JAR"
}

# $1 label, $2 recorded address, $3 "is" or "not", $4 the address
expect() {
    if [ -z "$2" ]; then
        echo "  FAIL  $1: no sign-in was recorded"; FAIL=1
    elif { [ "$3" = is ] && [ "$2" = "$4" ]; } || { [ "$3" = not ] && [ "$2" != "$4" ]; }; then
        echo "  ok    $1: $2"
    else
        echo "  FAIL  $1: $2 (expected $3 $4)"; FAIL=1
    fi
}

echo "Sign-ins through the proxy (forging $HDR):"
before=$(max_id); sign_in; ip=$(ip_after "$before")
if [ "$EXPECT" = proxy ]; then
    expect "plain                     " "$ip" is "$PROXY"
else
    expect "plain, not the proxy      " "$ip" not "$PROXY"
    CLIENT=$ip
fi
before=$(max_id); sign_in -H "$HDR: $FAKE"; ip=$(ip_after "$before")
expect "caller's own header       " "$ip" not "$FAKE"
[ -n "$CLIENT" ] && expect "  ...and still the caller " "$ip" is "$CLIENT"
before=$(max_id); sign_in -H "$HDR: 127.0.0.1"; ip=$(ip_after "$before")
expect "forged 127.0.0.1          " "$ip" not 127.0.0.1

echo "Straight to the app, not through the proxy:"
before=$(max_id)
docker exec "$APP" sh -c "curl -s -o /dev/null -c /tmp/j -b /tmp/j http://127.0.0.1/login;
    curl -s -o /dev/null -c /tmp/j -b /tmp/j -H '$HDR: $FAKE' \
    --data-urlencode username=admin --data-urlencode 'password=$PW' http://127.0.0.1/login; rm -f /tmp/j"
expect "header from a non-proxy   " "$(ip_after "$before")" is 127.0.0.1

echo "Apache's status page:"
code=$(curl -sk --ssl-no-revoke -o /dev/null -w '%{http_code}' -H "$HDR: 127.0.0.1" "$BASE/server-status")
[ "$code" = 403 ] || [ "$code" = 404 ] && echo "  ok    /server-status with forged 127.0.0.1: $code" \
    || { echo "  FAIL  /server-status with forged 127.0.0.1: $code"; FAIL=1; }

[ $FAIL = 0 ] && echo "All as expected." || echo "Something is wrong - see above."
exit $FAIL
