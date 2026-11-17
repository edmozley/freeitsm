<?php
/**
 * Per-person settings for SELF-SERVICE PORTAL users.
 *
 * 🔑 WHY THIS EXISTS RATHER THAN REUSING user_preferences.
 *
 * `user_preferences` is keyed by `analyst_id`, so it cannot hold anything for a
 * portal user — they have no analyst row. That gap has been patched once
 * already, when the portal's colour palette was given its own column on the
 * `users` table. A second one-off column is where that stops being a patch and
 * starts being a habit, so this is the generic twin: same shape, same idea,
 * keyed by the portal user instead.
 *
 * 🔴 EVERY FUNCTION HERE WORKS BEFORE DATABASE VERIFICATION HAS RUN. An upgrade
 * ships the code before the operator runs the schema check, so on that install
 * the table does not exist yet. A read returns the default and a write is
 * quietly dropped; nothing throws, and the portal renders. Proved by dropping
 * the table and loading the page.
 */

/**
 * One preference for one portal user, or $default.
 *
 * @param int $userId  the portal user (users id); 0 or less gives $default
 * @param string $key  the preference_key, e.g. 'kb_layout'; '' gives $default
 * @param string $default returned when unset, unreadable, or the table is absent
 */
function portalPrefGet(PDO $conn, int $userId, string $key, string $default = ''): string
{
    if ($userId <= 0 || $key === '') return $default;
    try {
        $st = $conn->prepare(
            "SELECT preference_value FROM portal_user_preferences
              WHERE user_id = ? AND preference_key = ? LIMIT 1"
        );
        $st->execute([$userId, $key]);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? $default : (string)$v;
    } catch (Throwable) {
        // Missing table on a part-upgraded install, or the database is
        // unreachable. Either way the honest answer is "they have not chosen",
        // which is what the default means.
        return $default;
    }
}

/**
 * Store one preference. Returns false if it could not be stored — the caller
 * decides whether that is worth telling anybody, and for a layout toggle it is
 * not: the layout still changed, only the memory of it failed.
 *
 * @param int $userId  the portal user (users id); 0 or less stores nothing
 * @param string $key  the preference_key, e.g. 'kb_layout'; '' stores nothing
 * @param string $value  the value to store, as text
 */
function portalPrefSet(PDO $conn, int $userId, string $key, string $value): bool
{
    if ($userId <= 0 || $key === '') return false;
    try {
        // No unique index is assumed: this table is created by Database
        // Verification, which does not add indexes, so ON DUPLICATE KEY would
        // silently insert a second row instead of updating the first.
        $st = $conn->prepare("SELECT id FROM portal_user_preferences WHERE user_id = ? AND preference_key = ? LIMIT 1");
        $st->execute([$userId, $key]);
        $id = $st->fetchColumn();

        if ($id !== false && $id !== null) {
            $conn->prepare("UPDATE portal_user_preferences SET preference_value = ?, updated_datetime = NOW() WHERE id = ?")
                 ->execute([$value, (int)$id]);
        } else {
            $conn->prepare(
                "INSERT INTO portal_user_preferences (user_id, preference_key, preference_value, updated_datetime)
                 VALUES (?, ?, ?, NOW())"
            )->execute([$userId, $key, $value]);
        }
        return true;
    } catch (Throwable $e) {
        error_log('portalPrefSet(' . $key . '): ' . $e->getMessage());
        return false;
    }
}

/**
 * The portal knowledge layouts, and the one to use when nobody has chosen.
 *
 * ⚠️ These names match the analyst side's (assets/js/knowledge.js) on purpose.
 * A customer describing "the tree one" to an analyst should be describing the
 * same thing, and an install that sees both screens should not have to learn
 * two vocabularies for one idea.
 */
const PORTAL_KB_LAYOUTS = ['cards', 'list', 'tree', 'table'];
const PORTAL_KB_LAYOUT_DEFAULT = 'cards';
const PORTAL_KB_LAYOUT_KEY = 'kb_layout';

/**
 * The layout this portal user should see. Always one of PORTAL_KB_LAYOUTS.
 *
 * @param int $userId  the portal user (users id)
 */
function portalKbLayout(PDO $conn, int $userId): string
{
    $v = portalPrefGet($conn, $userId, PORTAL_KB_LAYOUT_KEY, PORTAL_KB_LAYOUT_DEFAULT);
    return in_array($v, PORTAL_KB_LAYOUTS, true) ? $v : PORTAL_KB_LAYOUT_DEFAULT;
}

/**
 * The portal user's interface language (GH #368). '' (or unset) = no choice: the
 * browser's language decides, as before. Any other value is a key of
 * I18n::SUPPORTED_LOCALES - checked by api/self-service/preference.php when
 * stored, and again by I18n::initFromSession() when read.
 */
const PORTAL_LANGUAGE_KEY = 'interface_language';
