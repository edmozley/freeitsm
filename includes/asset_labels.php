<?php
/**
 * QR asset labels — the tag people read, and the token machines read.
 *
 * TWO IDENTIFIERS, ON PURPOSE
 * ---------------------------
 * `asset_tag` is the number printed in human-readable text on the label
 * ("LT0001"). It belongs to the company that owns the asset, so two companies
 * on one install may each legitimately run their own LT0001 — the same reason
 * hostname uniqueness is per-company here.
 *
 * `qr_token` is what the QR code actually encodes, as a URL. It is opaque and
 * install-wide unique, which is what makes the tag collision above a non-issue:
 * whatever the label says, the scan resolves to exactly one asset.
 *
 * WHY NOT ENCODE THE ID
 * ---------------------
 * `…/a/4711` invites somebody to try 4712. The token isn't a secret — the scan
 * page requires a login and enforces company scope like every other asset read —
 * but there is no reason to hand out an enumerable index of the estate to
 * anyone who photographs one label.
 *
 * WHY THE URL IS SHORT
 * --------------------
 * `/a/<token>` rather than `/asset-management/scan.php?token=<token>`: every
 * character is another QR module, and these get printed at 15mm square on the
 * side of a laptop that then spends three years being knocked about. Fewer
 * characters is a bigger, more forgiving code.
 */

require_once __DIR__ . '/functions.php';

/** Token length in bytes (hex-encoded to 20 chars). Short enough to keep the QR
 *  coarse, long enough that guessing is pointless. */
const ASSET_TOKEN_BYTES = 10;

/** Does this database have the label columns yet? Cached per request. */
function assetLabelsSchemaReady(PDO $conn): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $cols = $conn->query("SHOW COLUMNS FROM `assets` LIKE 'qr_token'")->fetch(PDO::FETCH_ASSOC);
        $ready = (bool)$cols;
    } catch (Exception $e) {
        $ready = false;
    }
    return $ready;
}

/**
 * The token for an asset, minting one if it has never been labelled.
 *
 * Minting on demand rather than at creation keeps the column empty for the
 * thousands of auto-discovered assets nobody will ever print a label for, and
 * means an asset's token comes into existence at the moment it acquires meaning.
 */
function assetEnsureToken(PDO $conn, int $assetId): ?string {
    if (!assetLabelsSchemaReady($conn) || $assetId <= 0) return null;

    $stmt = $conn->prepare("SELECT qr_token FROM assets WHERE id = ?");
    $stmt->execute([$assetId]);
    $existing = $stmt->fetchColumn();
    if ($existing === false) return null;              // no such asset
    if (!empty($existing)) return (string)$existing;

    // Retry on the (astronomically unlikely) collision rather than trusting luck;
    // the unique index is the real guard and this just avoids a hard failure.
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $token = bin2hex(random_bytes(ASSET_TOKEN_BYTES));
        try {
            $upd = $conn->prepare("UPDATE assets SET qr_token = ? WHERE id = ? AND (qr_token IS NULL OR qr_token = '')");
            $upd->execute([$token, $assetId]);
            if ($upd->rowCount() > 0) return $token;
            // Somebody else minted one first — use theirs.
            $stmt->execute([$assetId]);
            $now = $stmt->fetchColumn();
            if (!empty($now)) return (string)$now;
        } catch (Exception $e) {
            // Unique violation: go round again with a new token.
        }
    }
    return null;
}

/** Resolve a scanned token to an asset id. Returns null for unknown tokens. */
function assetIdForToken(PDO $conn, string $token): ?int {
    if (!assetLabelsSchemaReady($conn)) return null;
    $token = trim($token);
    // Cheap shape check first: the column is indexed, but there is no reason to
    // send junk from a mis-scan to the database.
    if ($token === '' || !preg_match('/^[a-f0-9]{8,64}$/i', $token)) return null;
    $stmt = $conn->prepare("SELECT id FROM assets WHERE qr_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int)$id;
}

/**
 * Is this asset tag free within its company?
 *
 * Application-level because a UNIQUE (tenant_id, asset_tag) index would NOT
 * hold for the Default company: MySQL treats NULLs as distinct in a unique
 * index, so two NULL-tenant assets could both be LT0001 while the index looked
 * like it was guarding them. Same reason hostname is checked here rather than
 * by the schema. `<=>` is the null-safe equality operator, so the comparison
 * behaves for the Default company as well as a named one.
 */
function assetTagAvailable(PDO $conn, ?int $tenantId, string $tag, ?int $exceptAssetId = null): bool {
    if (!assetLabelsSchemaReady($conn)) return true;
    $tag = trim($tag);
    if ($tag === '') return true;                       // blank is always allowed
    $sql = "SELECT COUNT(*) FROM assets WHERE tenant_id <=> ? AND asset_tag = ?";
    $args = [$tenantId, $tag];
    if ($exceptAssetId !== null) { $sql .= " AND id <> ?"; $args[] = $exceptAssetId; }
    $stmt = $conn->prepare($sql);
    $stmt->execute($args);
    return (int)$stmt->fetchColumn() === 0;
}

/**
 * The URL a label's QR encodes.
 *
 * Absolute, because the code is scanned by a phone that has no idea what the
 * app's base path is. Derived from the install's configured public base URL so
 * that labels printed today still resolve when the app moves.
 */
function assetLabelUrl(string $token): string {
    return rtrim(assetPublicBaseUrl(), '/') . '/a/' . $token;
}

/**
 * The install's public base, including any sub-folder.
 *
 * REUSES `messagingPublicBaseUrl()` and its `messaging_public_base_url`
 * setting rather than adding a second one. That setting answers the question
 * "how does the outside world reach this install?", which is exactly what a
 * printed label needs to know — and an install that already told us for
 * WhatsApp webhooks should not have to tell us again for asset labels.
 *
 * ⚠️ Divergence worth flagging rather than hiding: the setting is *named* for
 * messaging, and it is now doing install-wide work. It wants renaming to a
 * generic key (with a read-both-fall-back) the next time settings are touched.
 *
 * Why the configured value wins over the current request: a label is printed
 * once and lives on a laptop for years, so deriving it from whichever hostname
 * the printing analyst happened to be using would bake that in permanently.
 */
function assetPublicBaseUrl(): string {
    static $base = null;
    if ($base !== null) return $base;

    require_once __DIR__ . '/messaging/messaging.php';
    $host = '';
    try {
        $host = messagingPublicBaseUrl(connectToDatabase());
    } catch (Exception $e) {
        $scheme = requestScheme();
        $host = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    // The app root — the same derivation messagingWebhookUrl() uses, so a
    // sub-folder install ("/freeitsm-app/") is handled identically.
    $root = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
    $host = rtrim($host, '/');

    // ⚠️ The setting is documented as scheme://host, but people paste the URL
    // they actually use — which on a sub-folder install carries the folder, and
    // on a tunnel (ngrok et al) is copied wholesale from the address bar. Adding
    // the root again would encode …/freeitsm-app/freeitsm-app/a/<token> into a
    // code that then gets printed onto physical labels. Accept both forms.
    if ($root !== '' && substr($host, -strlen($root)) === $root) {
        $root = '';
    }

    $base = $host . $root;
    return $base;
}
