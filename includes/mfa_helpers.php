<?php
/**
 * MFA Helper - Shared authentication context for both analysts and self-service users.
 * Used by api/myaccount/ MFA endpoints to support both user types without duplication.
 */

/**
 * Determine which user type is authenticated and return their context.
 * When both analyst and user sessions coexist, $preferredType selects which to use.
 * Returns null if no valid session exists.
 *
 * @param string|null $preferredType 'analyst' or 'user' to force a specific context
 * @return array|null ['id' => int, 'table' => string, 'type' => 'analyst'|'user']
 */
function getMfaAuthContext($preferredType = null) {
    if ($preferredType === 'user' && isset($_SESSION['ss_user_id'])) {
        return [
            'id' => (int)$_SESSION['ss_user_id'],
            'table' => 'users',
            'type' => 'user'
        ];
    }
    if ($preferredType === 'analyst' && isset($_SESSION['analyst_id'])) {
        return [
            'id' => (int)$_SESSION['analyst_id'],
            'table' => 'analysts',
            'type' => 'analyst'
        ];
    }
    // Default: analyst first (preserves existing analyst behaviour)
    if (!$preferredType && isset($_SESSION['analyst_id'])) {
        return [
            'id' => (int)$_SESSION['analyst_id'],
            'table' => 'analysts',
            'type' => 'analyst'
        ];
    }
    if (!$preferredType && isset($_SESSION['ss_user_id'])) {
        return [
            'id' => (int)$_SESSION['ss_user_id'],
            'table' => 'users',
            'type' => 'user'
        ];
    }
    return null;
}
