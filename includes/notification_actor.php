<?php
/**
 * Who - or what - is causing the change being made right now, for the bell.
 *
 * GH #365. The bell used to name whoever was signed in for the web request. That
 * is right when a person changes something, and wrong whenever something ELSE
 * changes it inside their request: a workflow assigning a team, a rule run when
 * their open inbox fetched new mail. The bell then said "Handed to your team by
 * Nguyen" about a ticket Nguyen never touched, and Nguyen - being "the actor" -
 * was left out of a notification meant for his own team.
 *
 * Whatever is acting pushes itself here for as long as it acts (the workflow
 * engine around each run; the mailbox check around its import), and the
 * notifications router asks current() first. Only when nothing has pushed does it
 * fall back to the request itself: an API key, a signed-in analyst, a portal user,
 * or - with none of those - FreeITSM on its own (a scheduled job).
 *
 * An actor is an array:
 *   kind        'analyst' | 'portal_user' | 'workflow' | 'system' | 'api'
 *   id          the analyst's (or portal user's) id; null for a workflow or the system
 *   name        what the bell shows after "by" - a person's name, or the workflow's name
 *   source      'screen' | 'portal' | 'api' | 'workflow' | 'mail_import' | 'scheduled'
 *   workflow_id when kind is 'workflow' and it is a saved workflow
 *   api_key_id  when the change came through the REST API
 *
 * TRAP: only kind 'analyst' (and 'api', which acts as its analyst) is "a person did
 * this". The never-tell-me-about-my-own-action rule must not use the id of a
 * workflow's or the system's surroundings - that is how Nguyen lost his own
 * team's notification.
 */

final class NotificationActor
{
    /** @var array<int, array> innermost last */
    private static array $stack = [];

    /** Runs $fn with $actor as the one acting, and always restores the previous one. */
    public static function run(array $actor, callable $fn)
    {
        self::$stack[] = $actor;
        try {
            return $fn();
        } finally {
            array_pop(self::$stack);
        }
    }

    /**
     * For a whole request that acts as something other than its signed-in person -
     * the mailbox import. Nothing pops it: the request ends. Prefer run() anywhere a
     * scope has an end.
     */
    public static function push(array $actor): void
    {
        self::$stack[] = $actor;
    }

    /** The innermost thing acting, or null when nothing has said. */
    public static function pushed(): ?array
    {
        return self::$stack ? self::$stack[count(self::$stack) - 1] : null;
    }

    /**
     * The actor for a notification being written now, never null.
     */
    public static function current(): array
    {
        $base = ['kind' => 'system', 'id' => null, 'name' => null, 'source' => null, 'workflow_id' => null, 'api_key_id' => null];
        if ($p = self::pushed()) {
            return $p + $base;
        }
        // The REST API: a key acts as its analyst, and the change is theirs.
        if (!empty($GLOBALS['FREEITSM_API_KEY']) && is_array($GLOBALS['FREEITSM_API_KEY'])) {
            $k = $GLOBALS['FREEITSM_API_KEY'];
            return ['kind' => 'api', 'id' => !empty($k['analyst_id']) ? (int)$k['analyst_id'] : null,
                    'name' => $k['analyst_name'] ?? null, 'source' => 'api', 'api_key_id' => isset($k['id']) ? (int)$k['id'] : null] + $base;
        }
        if (!empty($_SESSION['analyst_id'])) {
            $name = isset($_SESSION['analyst_name']) ? (string)$_SESSION['analyst_name'] : '';
            return ['kind' => 'analyst', 'id' => (int)$_SESSION['analyst_id'], 'name' => $name !== '' ? $name : null, 'source' => 'screen'] + $base;
        }
        if (!empty($_SESSION['ss_user_id'])) {
            return ['kind' => 'portal_user', 'id' => (int)$_SESSION['ss_user_id'], 'name' => $_SESSION['ss_user_name'] ?? null, 'source' => 'portal'] + $base;
        }
        return ['kind' => 'system', 'source' => PHP_SAPI === 'cli' ? 'scheduled' : null] + $base;
    }

    /** Is this actor a member of staff acting in person (so "your own action" applies)? */
    public static function isPerson(array $actor): bool
    {
        return in_array($actor['kind'] ?? '', ['analyst', 'api'], true) && !empty($actor['id']);
    }
}
