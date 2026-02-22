<?php
/**
 * MFA Helper - Shared authentication context for both analysts and self-service users.
 * Used by api/myaccount/ MFA endpoints to support both user types without duplication.
 */

/**
 * Determine which user type is authenticated and return their context.
 * Returns null if no valid session exists.
 *
 * @return array|null ['id' => int, 'table' => string, 'type' => 'analyst'|'user']
 */
function getMfaAuthContext() {
    if (isset($_SESSION['analyst_id'])) {
        return [
            'id' => (int)$_SESSION['analyst_id'],
            'table' => 'analysts',
            'type' => 'analyst'
        ];
    }
    if (isset($_SESSION['ss_user_id'])) {
        return [
            'id' => (int)$_SESSION['ss_user_id'],
            'table' => 'users',
            'type' => 'user'
        ];
    }
    return null;
}
