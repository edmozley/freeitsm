#!/bin/sh
# Which address does FreeITSM record for a sign-in, behind the proxy?
# Reads it back from system_logs, the rows System -> Logs shows. See README.md.
#
#   sh docker/proxy-test/check-ip.sh [admin-password]
#
# With TRUSTED_PROXIES naming the proxy (the stack's default), a sign-in through
# the proxy must record the caller, not the proxy, and an X-Forwarded-For the
# caller wrote itself must be ignored, both through the proxy and straight to
# the app. Run with EXPECT=proxy after starting the app with TRUSTED_PROXIES=
# (empty) to check that it is off: every sign-in is then the proxy's.
BASE=https://localhost:8453
FAKE=203.0.113.9
APP=proxytest-app-1
DB=proxytest-db-1
PROXY=$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' proxytest-proxy-1)
PW=${1:-freeitsm}
FAIL=0

last_ip() {
    docker exec "$DB" mysql -N -ufreeitsm -pfreeitsm freeitsm 2>/dev/null -e \
        "SELECT JSON_UNQUOTE(JSON_EXTRACT(details, '\$.ip_address')) FROM system_logs
         WHERE log_type = 'login' ORDER BY id DESC LIMIT 1"
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
    if { [ "$3" = is ] && [ "$2" = "$4" ]; } || { [ "$3" = not ] && [ -n "$2" ] && [ "$2" != "$4" ]; }; then
        echo "  ok    $1: $2"
    else
        echo "  FAIL  $1: $2 (expected $3 $4)"; FAIL=1
    fi
}

echo "Sign-ins through the proxy:"
sign_in
ip=$(last_ip)
if [ "$EXPECT" = proxy ]; then
    expect "plain                     " "$ip" is "$PROXY"
else
    expect "plain, not the proxy      " "$ip" not "$PROXY"
    CLIENT=$ip
fi
sign_in -H "X-Forwarded-For: $FAKE"
ip=$(last_ip)
expect "caller's own XFF ignored  " "$ip" not "$FAKE"
[ -n "$CLIENT" ] && expect "  ...and still the caller " "$ip" is "$CLIENT"

echo "Straight to the app, not through the proxy:"
docker exec "$APP" sh -c "curl -s -o /dev/null -c /tmp/j -b /tmp/j http://127.0.0.1/login;
    curl -s -o /dev/null -c /tmp/j -b /tmp/j -H 'X-Forwarded-For: $FAKE' \
    --data-urlencode username=admin --data-urlencode 'password=$PW' http://127.0.0.1/login; rm -f /tmp/j"
expect "XFF from a non-proxy      " "$(last_ip)" is 127.0.0.1

[ $FAIL = 0 ] && echo "All as expected." || echo "Something is wrong - see above."
exit $FAIL
