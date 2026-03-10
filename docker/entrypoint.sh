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

# Start Apache in foreground
exec apache2-foreground
