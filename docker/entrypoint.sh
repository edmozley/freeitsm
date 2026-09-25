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

# Start Apache in foreground
exec apache2-foreground
