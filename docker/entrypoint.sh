#!/bin/bash
# FreeITSM Docker Entrypoint
# Auto-generates the encryption key on first boot if one doesn't exist

KEY_PATH="${ENCRYPTION_KEY_PATH:-/var/www/encryption_keys/freeitsm.key}"
KEY_DIR=$(dirname "$KEY_PATH")

# Create key directory if needed
if [ ! -d "$KEY_DIR" ]; then
    mkdir -p "$KEY_DIR"
    chown www-data:www-data "$KEY_DIR"
    chmod 700 "$KEY_DIR"
fi

# Generate encryption key if it doesn't exist
if [ ! -f "$KEY_PATH" ]; then
    echo "Generating encryption key at $KEY_PATH ..."
    openssl rand -hex 32 > "$KEY_PATH"
    chown www-data:www-data "$KEY_PATH"
    chmod 600 "$KEY_PATH"
    echo "Encryption key generated."
else
    echo "Encryption key already exists at $KEY_PATH"
fi

# HTTPS, using the certificate made on System -> Docker (includes/docker_tls.php).
# Checked on every start, and HTTPS is only turned on when everything checks out:
# a missing, unreadable or mismatched certificate must leave the container on
# plain HTTP, never stop Apache starting - otherwise the screen that fixes it is
# unreachable too.
TLS_DIR=/var/www/tls
mkdir -p "$TLS_DIR"
chown www-data:www-data "$TLS_DIR"
chmod 700 "$TLS_DIR"
rm -f "$TLS_DIR/loaded.txt"

tls_off() {
    a2dissite -q freeitsm-ssl >/dev/null 2>&1
    a2dismod -q ssl >/dev/null 2>&1
}

if [ -s "$TLS_DIR/server.crt" ] && [ -s "$TLS_DIR/server.key" ]; then
    CERT_PUB=$(openssl x509 -in "$TLS_DIR/server.crt" -noout -pubkey 2>/dev/null)
    KEY_PUB=$(openssl pkey -in "$TLS_DIR/server.key" -pubout 2>/dev/null)
    if [ -n "$CERT_PUB" ] && [ "$CERT_PUB" = "$KEY_PUB" ]; then
        a2enmod -q ssl >/dev/null 2>&1
        a2ensite -q freeitsm-ssl >/dev/null 2>&1
        if apache2ctl configtest >/dev/null 2>&1; then
            # Tells System -> Docker which certificate Apache started with, so it
            # can say whether a restart is still needed.
            openssl x509 -in "$TLS_DIR/server.crt" -noout -fingerprint -sha256 > "$TLS_DIR/loaded.txt"
            chown www-data:www-data "$TLS_DIR/loaded.txt"
            echo "HTTPS enabled with the certificate in $TLS_DIR."
        else
            echo "HTTPS NOT enabled: Apache rejected the configuration. Staying on HTTP."
            tls_off
        fi
    else
        echo "HTTPS NOT enabled: server.key does not match server.crt. Staying on HTTP."
        tls_off
    fi
else
    tls_off
fi

# Behind a reverse proxy that terminates HTTPS: Apache's own redirects must say
# https too. Decided on every start, like HTTPS above, so unsetting the variable
# and restarting really turns it off. See docker/apache-proxy.conf (GH #152).
# Empty and 0 mean off, exactly as PHP's getenv() test in docker/config.php reads them.
case "$TRUST_PROXY_HTTPS" in
    ""|0)
        rm -f /etc/apache2/freeitsm-proxy.conf
        ;;
    *)
        cp /etc/apache2/freeitsm-proxy.conf.available /etc/apache2/freeitsm-proxy.conf
        echo "TRUST_PROXY_HTTPS is set: Apache's own redirects will use https."
        ;;
esac

# Behind a reverse proxy, every request arrives from the proxy, so the sign-in
# log, the audit trails and API key allow-lists all see the proxy's address.
# TRUSTED_PROXIES names the proxy - IPv4 or IPv6 addresses, ranges such as
# 10.0.0.0/8 or fd00::/8, or host names, which Apache looks up when it starts -
# separated by spaces or commas. Apache's mod_remoteip then takes the client's
# address from the proxy's header (TRUSTED_PROXY_HEADER: X-Forwarded-For unless
# set; CF-Connecting-IP for a Cloudflare Tunnel). PHP's REMOTE_ADDR changes, so
# nothing in FreeITSM needs to.
#
# 🔴 The header is only believed on a connection FROM a listed address: anyone
# else can write it. mod_remoteip reads X-Forwarded-For from the right and stops
# at the first address that is not a listed proxy, so a client's own value at
# the left is never used. Decided on every start; an invalid value turns it off
# and says so, rather than stopping Apache.
REMOTEIP_CONF=/etc/apache2/conf-available/freeitsm-remoteip.conf
remoteip_off() {
    a2disconf -q freeitsm-remoteip >/dev/null 2>&1
    a2dismod -q remoteip >/dev/null 2>&1
    rm -f "$REMOTEIP_CONF"
}
PROXY_LIST=$(printf '%s' "$TRUSTED_PROXIES" | tr ',\t\r\n' '    ' | tr -s ' ' | sed 's/^ //; s/ $//')
PROXY_HEADER="${TRUSTED_PROXY_HEADER:-X-Forwarded-For}"
if [ -z "$PROXY_LIST" ]; then
    remoteip_off
elif printf '%s' "$PROXY_LIST" | grep -q '[^A-Za-z0-9.:/ -]' \
    || printf '%s' "$PROXY_HEADER" | grep -q '[^A-Za-z0-9-]'; then
    remoteip_off
    echo "TRUSTED_PROXIES or TRUSTED_PROXY_HEADER is not valid, so client addresses are not taken from the proxy." >&2
else
    {
        echo "# Written by entrypoint.sh from TRUSTED_PROXIES - do not edit."
        echo "RemoteIPHeader $PROXY_HEADER"
        echo "RemoteIPInternalProxy $PROXY_LIST"
        # %a is the client's address after mod_remoteip; the stock format's %h
        # would keep logging the proxy.
        echo 'LogFormat "%a %l %u %t \"%r\" %>s %O \"%{Referer}i\" \"%{User-Agent}i\"" combined'
    } > "$REMOTEIP_CONF"
    a2enmod -q remoteip >/dev/null 2>&1
    a2enconf -q freeitsm-remoteip >/dev/null 2>&1
    if apache2ctl -t >/dev/null 2>&1; then
        echo "TRUSTED_PROXIES is set: client addresses come from $PROXY_HEADER on requests from $PROXY_LIST."
    else
        remoteip_off
        echo "TRUSTED_PROXIES was refused by Apache ($PROXY_LIST), so client addresses are not taken from the proxy." >&2
    fi
fi

# The built-in scheduler (#233): cron/run.php once a minute, as www-data, so the
# background jobs run with no cron in the container or on the host. A loop rather
# than the cron daemon, because cron starts jobs with an almost empty environment
# and docker/config.php reads DB_* and TZ from it. FREEITSM_SCHEDULER=0 turns it
# off, for an install that runs cron/run.php or the per-job scripts from the host.
# Errors go to `docker logs`.
case "$FREEITSM_SCHEDULER" in
    0|false|no|off)
        echo "FREEITSM_SCHEDULER is off: this container does not start background jobs."
        ;;
    *)
        (
            while true; do
                # Deprecation notices off: php.ini's session.sid_* lines raise two at
                # every PHP start on 8.4, which would be 2,880 log lines a day. Errors
                # still reach the log.
                runuser -u www-data -- php -d "error_reporting=E_ALL & ~E_DEPRECATED" /var/www/html/cron/run.php --quiet
                # Wake at the top of the next minute, however long this run took.
                sleep $((60 - $(date +%s) % 60))
            done
        ) &
        echo "Built-in scheduler started: background jobs run inside this container."
        ;;
esac

# Start Apache in foreground
exec apache2-foreground
