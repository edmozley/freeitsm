<?php
/**
 * AES-256-GCM Encryption Helper
 *
 * Provides encrypt/decrypt functions for sensitive database values.
 * Key file is stored outside the web root at D:\encryption_keys\sdtickets.key
 *
 * Encrypted values are stored as: ENC: followed by base64(iv + tag + ciphertext)
 * The ENC: prefix allows gradual migration - unencrypted values pass through unchanged.
 */

define('ENCRYPTION_KEY_PATH', 'c:\\wamp64\\encryption_keys\\sdtickets.key');
define('ENCRYPTION_CIPHER', 'aes-256-gcm');
define('ENCRYPTION_IV_LENGTH', 12);   // 96-bit nonce (recommended for GCM)
define('ENCRYPTION_TAG_LENGTH', 16);  // 128-bit auth tag
define('ENCRYPTION_PREFIX', 'ENC:');

/**
 * System settings keys that should be encrypted at rest.
 * Add new sensitive keys here as encryption is rolled out.
 */
define('ENCRYPTED_SETTING_KEYS', [
    'vcenter_server',
    'vcenter_user',
    'vcenter_password',
    'knowledge_ai_api_key',
    'knowledge_openai_api_key',
]);

/**
 * target_mailboxes columns that should be encrypted at rest.
 */
define('ENCRYPTED_MAILBOX_COLUMNS', [
    'azure_tenant_id',
    'azure_client_id',
    'azure_client_secret',
    'oauth_redirect_uri',
    'imap_server',
    'target_mailbox',
]);

/**
 * Check if a system_settings key should be encrypted
 */
function isEncryptedSettingKey($key) {
    return in_array($key, ENCRYPTED_SETTING_KEYS, true);
}

/**
 * Load the encryption key from the key file
 */
function getEncryptionKey() {
    static $key = null;
    if ($key === null) {
        if (!file_exists(ENCRYPTION_KEY_PATH)) {
            throw new Exception('Encryption key file not found at ' . ENCRYPTION_KEY_PATH);
        }
        $hex = trim(file_get_contents(ENCRYPTION_KEY_PATH));
        $key = hex2bin($hex);
        if ($key === false || strlen($key) !== 32) {
            throw new Exception('Invalid encryption key - must be 64 hex characters (256 bits)');
        }
    }
    return $key;
}

/**
 * Encrypt a plaintext value using AES-256-GCM
 *
 * @param string|null $plaintext The value to encrypt
 * @return string|null Encrypted string with ENC: prefix, or null/empty if input is null/empty
 */
function encryptValue($plaintext) {
    if ($plaintext === null || $plaintext === '') {
        return $plaintext;
    }

    // Don't double-encrypt
    if (strpos($plaintext, ENCRYPTION_PREFIX) === 0) {
        return $plaintext;
    }

    $key = getEncryptionKey();
    $iv = random_bytes(ENCRYPTION_IV_LENGTH);
    $tag = '';

    $ciphertext = openssl_encrypt(
        $plaintext,
        ENCRYPTION_CIPHER,
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        '',
        ENCRYPTION_TAG_LENGTH
    );

    if ($ciphertext === false) {
        throw new Exception('Encryption failed: ' . openssl_error_string());
    }

    // Pack as: IV (12 bytes) + Tag (16 bytes) + Ciphertext
    return ENCRYPTION_PREFIX . base64_encode($iv . $tag . $ciphertext);
}

/**
 * Decrypt an encrypted value
 *
 * If the value doesn't have the ENC: prefix, it's returned as-is.
 * This allows gradual migration from plaintext to encrypted values.
 *
 * @param string|null $encrypted The encrypted value (with ENC: prefix)
 * @return string|null Decrypted plaintext, or the original value if not encrypted
 */
function decryptValue($encrypted) {
    if ($encrypted === null || $encrypted === '') {
        return $encrypted;
    }

    // Not encrypted - return as-is (supports migration)
    if (strpos($encrypted, ENCRYPTION_PREFIX) !== 0) {
        return $encrypted;
    }

    $key = getEncryptionKey();
    $data = base64_decode(substr($encrypted, strlen(ENCRYPTION_PREFIX)));

    if ($data === false) {
        throw new Exception('Invalid encrypted data - base64 decode failed');
    }

    $minLength = ENCRYPTION_IV_LENGTH + ENCRYPTION_TAG_LENGTH;
    if (strlen($data) < $minLength) {
        throw new Exception('Invalid encrypted data - too short');
    }

    $iv = substr($data, 0, ENCRYPTION_IV_LENGTH);
    $tag = substr($data, ENCRYPTION_IV_LENGTH, ENCRYPTION_TAG_LENGTH);
    $ciphertext = substr($data, $minLength);

    $plaintext = openssl_decrypt(
        $ciphertext,
        ENCRYPTION_CIPHER,
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($plaintext === false) {
        throw new Exception('Decryption failed - data may be corrupted or wrong key');
    }

    return $plaintext;
}

/**
 * Decrypt all encrypted columns in a target_mailboxes row.
 *
 * @param array|null $row A single row from the target_mailboxes table
 * @return array|null The row with encrypted columns decrypted
 */
function decryptMailboxRow($row) {
    if (!$row) {
        return $row;
    }
    foreach (ENCRYPTED_MAILBOX_COLUMNS as $col) {
        if (isset($row[$col])) {
            $row[$col] = decryptValue($row[$col]);
        }
    }
    return $row;
}
?>
