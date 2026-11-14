<?php
/**
 * Where an asset's assignment to a person came from - users_assets.assigned_source
 * (GH #335).
 *
 * It decides one thing that matters: which holders Intune may move when a
 * device's primary user changes ("Follow changes of primary user"). Intune moves
 * only a holder it put there itself - never one an analyst chose, nor one the API
 * or anything else set.
 *
 * NULL in the column means "from before the column existed". For those, an
 * Intune assignment is still told apart the way it always was: no analyst, and
 * the custody note intuneAssignPrimaryUsers() writes (assetAssignedByIntuneSql()).
 *
 * @wiki Asset-Reconciliation-Developer-Guide
 */

require_once __DIR__ . '/service_context.php';

enum AssetAssignmentSource: string
{
    case Analyst = 'analyst';   // somebody in the app
    case Intune  = 'intune';    // the Intune sync: a device's primary user
    case Api     = 'api';       // the REST API
    case System  = 'system';    // anything else running on its own

    /** The note intuneAssignPrimaryUsers() writes - how an Intune assignment was told apart before this column. */
    public const INTUNE_NOTE = 'Primary user of the device in Intune';

    /** The source an actor implies when the caller does not say. */
    public static function fromContext(ActorContext $ctx): self
    {
        return match ($ctx->source) {
            'ui'    => self::Analyst,
            'api'   => self::Api,
            default => self::System,
        };
    }

    /**
     * A value from the caller - this enum, its string, or nothing - else the
     * actor's. An unknown string is the actor's too, never stored as given.
     */
    public static function resolve($value, ActorContext $ctx): self
    {
        if ($value instanceof self) return $value;
        if (is_string($value) && ($v = self::tryFrom($value))) return $v;
        return self::fromContext($ctx);
    }
}

/**
 * Fills users_assets.assigned_source in for the rows Intune made before the column
 * existed - its note, no analyst - and returns how many. Run by Database
 * Verification on every verify; a new row always has its source, so this only
 * ever finds rows from before. Every other old row stays NULL ("from before the
 * column"), which Intune never moves.
 */
function assetAssignmentSourceBackfill(PDO $conn): int
{
    $st = $conn->prepare("UPDATE users_assets SET assigned_source = ?
                           WHERE assigned_source IS NULL AND assigned_by_analyst_id IS NULL AND notes = ?");
    $st->execute([AssetAssignmentSource::Intune->value, AssetAssignmentSource::INTUNE_NOTE]);
    return $st->rowCount();
}

/**
 * SQL: this users_assets row was put there by Intune - by its column, or, for a
 * row from before the column, by the note and the missing analyst.
 *
 * @param string $ua  the users_assets alias
 */
function assetAssignedByIntuneSql(string $ua = 'ua'): string
{
    return "($ua.assigned_source = 'intune' OR ($ua.assigned_source IS NULL AND $ua.assigned_by_analyst_id IS NULL AND $ua.notes = '"
         . AssetAssignmentSource::INTUNE_NOTE . "'))";
}
