#!/bin/sh
# Ask the app, through the proxy, for every URL it builds from the request and
# report any that came back http://. See README.md for the setup this needs.
#
#   sh docker/proxy-test/check.sh [admin-password]
BASE=https://localhost:8453
JAR=$(mktemp)
C="curl -sk --ssl-no-revoke -b $JAR -c $JAR"
FAIL=0

$C -o /dev/null "$BASE/login"
$C -o /dev/null --data-urlencode username=admin --data-urlencode "password=${1:-freeitsm}" "$BASE/login"

# $1 label, $2 what the app produced
report() {
    case "$2" in
        https://*) echo "  ok    $1: $2" ;;
        "")        echo "  ??    $1: nothing found (not signed in, or no provider?)"; FAIL=1 ;;
        *)         echo "  FAIL  $1: $2"; FAIL=1 ;;
    esac
}

page_url() {  # first absolute localhost:8453 URL on a page, optionally ending in $2
    $C "$BASE/$1" | grep -o "https\?://localhost:8453[^\"'< ]*$2" | head -1
}

echo "Pages that show an address to copy:"
report "SSO settings, redirect URI " "$(page_url system/sso/ oidc_callback.php)"
report "SSO help, redirect URI     " "$(page_url system/help/sso.php oidc_callback.php)"
report "API page, base URL         " "$(page_url system/api/ api/v1)"
report "API docs, base URL         " "$(page_url system/api/docs.php api/v1)"
report "Webhooks, cron URL         " "$(page_url system/webhooks/ 'webhook_deliveries.php')"

echo "Redirects:"
loc=$($C -o /dev/null -w '%{redirect_url}' "$BASE/api/auth/oidc_login.php?provider=1")
report "SSO sign-in redirect_uri   " "$(printf '%s' "$loc" | grep -o 'redirect_uri=[^&]*' | sed 's/redirect_uri=//; s/%3A/:/g; s/%2F/\//g')"
report "/login.php -> /login       " "$($C -o /dev/null -w '%{redirect_url}' "$BASE/login.php")"
report "/system/sso -> /system/sso/" "$($C -o /dev/null -w '%{redirect_url}' "$BASE/system/sso")"

rm -f "$JAR"
[ $FAIL = 0 ] && echo "All https." || echo "Something came back http:// - see above."
exit $FAIL
