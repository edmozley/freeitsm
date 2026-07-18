<?php
/**
 * Shared self-service portal helpers.
 */

/**
 * Is self-service self-registration allowed? Defaults to FALSE (disabled) — an
 * install must deliberately turn it on under System → Security. Off by default
 * because open sign-up is an attack surface most desks don't need; when on, the
 * email-confirmation flow (register.php) still applies.
 */
function selfServiceRegistrationEnabled(PDO $conn): bool {
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'self_service_registration_enabled'");
    $stmt->execute();
    return $stmt->fetchColumn() === '1';
}
