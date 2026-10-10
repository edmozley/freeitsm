<?php
/**
 * The page a QR asset label opens: /a/<token>
 *
 * MOBILE-FIRST, NOT MOBILE-ADAPTED. This is the one page in FreeITSM whose
 * primary device is a phone — you are standing in a store room holding the
 * laptop. So it is built narrow-first with big touch targets and no reliance on
 * the desktop asset module being responsive; it is a purpose-built surface, not
 * the asset editor squeezed down. (Making the rest of Assets mobile-friendly is
 * a separate, worthwhile job — this deliberately does not wait for it.)
 *
 * It carries the actions you actually want while walking around — set status,
 * set location — so a phone user is never bounced into a desktop form. Anything
 * heavier links out to the full record.
 *
 * SECURITY. The token is not a password: it is an unguessable name for a row.
 * This page requires a login and enforces module access + company scope exactly
 * as any other asset read does, so photographing a label gains you nothing you
 * could not already see.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
require_once '../includes/tenancy.php';
require_once '../includes/asset_labels.php';
I18n::initFromSession();
Tz::init();

$token = isset($_GET['token']) ? trim((string)$_GET['token']) : '';

/**
 * Logged out? Say so plainly rather than bouncing to a login that would land
 * them on the dashboard having lost the scan. The label is in their hand — the
 * shortest route back is to sign in and scan again, and saying that is more
 * honest than a redirect that silently drops what they asked for.
 */
$loggedIn = isset($_SESSION['analyst_id']);

$asset = null;
$problem = null;      // 'unknown' | 'no_access' | 'not_ready'
$statuses = [];
$locations = [];

if ($loggedIn) {
    try {
        $conn = connectToDatabase();
        $analystId = (int)$_SESSION['analyst_id'];

        if (!analystCanAccessModule($conn, $analystId, 'assets')) {
            $problem = 'no_access';
        } elseif (!assetLabelsSchemaReady($conn)) {
            $problem = 'not_ready';
        } else {
            $assetId = assetIdForToken($conn, $token);
            // An unknown token and one in another company give the SAME answer.
            // Distinguishing them would turn the label into an oracle for
            // "does this asset exist somewhere on this install?".
            if ($assetId === null || !analystCanAccessAsset($conn, $analystId, $assetId)) {
                $problem = 'unknown';
            } else {
                $stmt = $conn->prepare(
                    "SELECT a.id, a.asset_tag, a.hostname, a.manufacturer, a.model, a.service_tag,
                            a.operating_system, a.warranty_expiry, a.purchase_date, a.last_seen,
                            a.asset_status_id, a.location_id, a.logged_in_user,
                            t.name AS type_name, s.name AS status_name, l.name AS location_name
                       FROM assets a
                       LEFT JOIN asset_types        t ON t.id = a.asset_type_id
                       LEFT JOIN asset_status_types s ON s.id = a.asset_status_id
                       LEFT JOIN asset_locations    l ON l.id = a.location_id
                      WHERE a.id = ?"
                );
                $stmt->execute([$assetId]);
                $asset = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

                // The status and location lists are NOT queried here. Both are
                // company-scoped, and by different rules: locations are scoped
                // DATA (activeTenantFilter), while status types are a config
                // list — global rows plus the company's own, minus anything it
                // has hidden via tenant_config_hidden. Reimplementing either
                // would be a second copy destined to drift, so the page fetches
                // them from the module's own endpoints, which already do it.

                // Who currently holds it — the latest checkout with no matching return.
                $co = $conn->prepare(
                    "SELECT user_name, action FROM asset_checkout_log
                      WHERE asset_id = ? ORDER BY action_datetime DESC, id DESC LIMIT 1"
                );
                $co->execute([$assetId]);
                $last = $co->fetch(PDO::FETCH_ASSOC);
                $asset['held_by'] = ($last && strtolower($last['action']) === 'out') ? $last['user_name'] : null;
            }
        }
    } catch (Exception $e) {
        $problem = 'not_ready';
    }
}

$title = $asset ? ($asset['asset_tag'] ?: $asset['hostname'] ?: t('asset-management.scan.asset')) : t('asset-management.scan.asset');
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <?php /* No user-scalable=no: pinch-zoom is how somebody reads a serial number
             off a battered label in a dim store room. */ ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title); ?> · FreeITSM</title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=27">
    <?php echo Tz::scriptTag(); ?>
    <style>
        /* Self-contained: this page shares no layout with the desktop module,
           so it carries its own small sheet rather than importing one built for
           a three-pane inbox. Every colour is a theme token. */
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--surface-2, #f3f6fa);
            color: var(--text, #222);
            -webkit-text-size-adjust: 100%;
        }
        .scan-bar {
            background: var(--accent, #546e7a);
            color: var(--on-accent, #fff);
            padding: 14px 16px;
            font-size: 15px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .scan-wrap { max-width: 640px; margin: 0 auto; padding: 14px; }
        .card {
            background: var(--surface, #fff);
            border: 1px solid var(--border, #e2e6ea);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 14px;
        }
        .asset-tag {
            font-size: 13px;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: var(--text-muted, #667);
            margin: 0 0 4px;
        }
        .asset-name { font-size: 24px; font-weight: 700; margin: 0 0 12px; line-height: 1.2; word-break: break-word; }
        .facts { display: grid; grid-template-columns: auto 1fr; gap: 8px 14px; font-size: 14.5px; }
        .facts dt { color: var(--text-muted, #667); }
        .facts dd { margin: 0; font-weight: 500; word-break: break-word; }
        .field-label { display: block; font-size: 13px; color: var(--text-muted, #667); margin: 0 0 6px; }
        /* 44px minimum: the accepted floor for a touch target, and these get
           pressed with a thumb while holding a laptop in the other hand. */
        select.touch, .btn-touch {
            width: 100%;
            min-height: 46px;
            font-size: 16px;              /* 16px stops iOS zooming the page on focus */
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid var(--border, #cfd6dd);
            background: var(--surface, #fff);
            color: var(--text, #222);
        }
        .btn-touch {
            display: block;
            text-align: center;
            text-decoration: none;
            font-weight: 600;
            border: 0;
            background: var(--accent, #546e7a);
            color: var(--on-accent, #fff);
            margin-top: 10px;
            cursor: pointer;
        }
        .btn-touch.secondary {
            background: var(--surface, #fff);
            color: var(--text, #222);
            border: 1px solid var(--border, #cfd6dd);
        }
        .saved { font-size: 13px; color: var(--success-text, #166534); min-height: 18px; margin-top: 6px; }
        .saved.error { color: var(--danger-text, #991b1b); }
        .empty { text-align: center; padding: 40px 16px; }
        .empty h1 { font-size: 19px; margin: 0 0 8px; }
        .empty p { color: var(--text-muted, #667); font-size: 14.5px; margin: 0 0 18px; line-height: 1.5; }
    </style>
</head>
<body>
<div class="scan-bar">
    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
    <span>FreeITSM · <?php echo htmlspecialchars(t('asset-management.scan.asset')); ?></span>
</div>

<div class="scan-wrap">
<?php if (!$loggedIn): ?>
    <div class="card empty">
        <h1><?php echo htmlspecialchars(t('asset-management.scan.signin_heading')); ?></h1>
        <p><?php echo htmlspecialchars(t('asset-management.scan.signin_body')); ?></p>
        <a class="btn-touch" href="<?php echo htmlspecialchars(BASE_URL); ?>login"><?php echo htmlspecialchars(t('asset-management.scan.signin_btn')); ?></a>
    </div>
<?php elseif ($problem === 'no_access'): ?>
    <div class="card empty">
        <h1><?php echo htmlspecialchars(t('asset-management.scan.noaccess_heading')); ?></h1>
        <p><?php echo htmlspecialchars(t('asset-management.scan.noaccess_body')); ?></p>
    </div>
<?php elseif ($problem === 'not_ready'): ?>
    <div class="card empty">
        <h1><?php echo htmlspecialchars(t('asset-management.scan.notready_heading')); ?></h1>
        <p><?php echo t('asset-management.scan.notready_body'); ?></p>
    </div>
<?php elseif ($problem === 'unknown' || !$asset): ?>
    <div class="card empty">
        <h1><?php echo htmlspecialchars(t('asset-management.scan.unknown_heading')); ?></h1>
        <p><?php echo htmlspecialchars(t('asset-management.scan.unknown_body')); ?></p>
        <a class="btn-touch secondary" href="<?php echo htmlspecialchars(BASE_URL); ?>asset-management/"><?php echo htmlspecialchars(t('asset-management.scan.unknown_btn')); ?></a>
    </div>
<?php else: ?>
    <div class="card">
        <?php if (!empty($asset['asset_tag'])): ?>
            <p class="asset-tag"><?php echo htmlspecialchars($asset['asset_tag']); ?></p>
        <?php endif; ?>
        <h1 class="asset-name"><?php echo htmlspecialchars($asset['hostname'] ?: t('asset-management.scan.asset_hash', ['id' => $asset['id']])); ?></h1>
        <dl class="facts">
            <?php
            $facts = [
                t('asset-management.field.type')      => $asset['type_name'],
                t('asset-management.field.status')    => $asset['status_name'],
                t('asset-management.field.location')  => $asset['location_name'],
                t('asset-management.scan.fact_held_by')  => $asset['held_by'],
                t('asset-management.scan.fact_make')     => trim(($asset['manufacturer'] ?? '') . ' ' . ($asset['model'] ?? '')),
                t('asset-management.scan.fact_serial')   => $asset['service_tag'],
                t('asset-management.scan.fact_os')       => $asset['operating_system'],
                t('asset-management.scan.fact_warranty') => $asset['warranty_expiry'],
                t('asset-management.field.last_seen') => $asset['last_seen'] ? fmt_datetime($asset['last_seen']) : null,
            ];
            foreach ($facts as $label => $value):
                if ($value === null || $value === '') continue; ?>
                <dt><?php echo htmlspecialchars($label); ?></dt>
                <dd><?php echo htmlspecialchars((string)$value); ?></dd>
            <?php endforeach; ?>
        </dl>
    </div>

    <?php /* The two edits worth making while standing next to the thing. Each
             saves on change — no Save button, because the failure mode of a
             forgotten Save on a phone is losing the edit entirely. */ ?>
    <div class="card">
        <label class="field-label" for="scanStatus"><?php echo htmlspecialchars(t('asset-management.field.status')); ?></label>
        <select class="touch" id="scanStatus" disabled onchange="saveField('asset_status_id', this.value)">
            <option><?php echo htmlspecialchars(t('common.loading')); ?></option>
        </select>

        <label class="field-label" for="scanLocation" style="margin-top:16px;"><?php echo htmlspecialchars(t('asset-management.field.location')); ?></label>
        <select class="touch" id="scanLocation" disabled onchange="saveField('location_id', this.value)">
            <option><?php echo htmlspecialchars(t('common.loading')); ?></option>
        </select>
        <div class="saved" id="scanSaved"></div>
    </div>

    <a class="btn-touch secondary" href="<?php echo htmlspecialchars(BASE_URL); ?>asset-management/?asset_id=<?php echo (int)$asset['id']; ?>"><?php echo htmlspecialchars(t('asset-management.scan.open_record')); ?></a>
<?php endif; ?>
</div>

<script>
const SCAN_ASSET_ID  = <?php echo $asset ? (int)$asset['id'] : 0; ?>;
const SCAN_API_BASE  = <?php echo json_encode(BASE_URL . 'api/assets/'); ?>;
const SCAN_API       = SCAN_API_BASE + 'update_asset_field.php';
const SCAN_STATUS_ID = <?php echo $asset ? (int)$asset['asset_status_id'] : 0; ?>;
const SCAN_LOC_ID    = <?php echo $asset ? (int)$asset['location_id'] : 0; ?>;

/**
 * Fill one dropdown from the module's own (already company-scoped) endpoint.
 * Deliberately not rendered server-side: locations and status types are scoped
 * by two different rules, and a second copy of either would drift.
 */
async function fillSelect(elId, url, pick, current) {
    const el = document.getElementById(elId);
    if (!el) return;
    try {
        const res = await fetch(url);
        const data = await res.json();
        const rows = pick(data) || [];
        el.innerHTML = '<option value="">' + escapeHtml(<?php echo json_encode(t('asset-management.scan.not_set')); ?>) + '</option>' + rows.map(r =>
            `<option value="${Number(r.id)}"${Number(r.id) === current ? ' selected' : ''}>${escapeHtml(r.name)}</option>`
        ).join('');
        el.disabled = false;
    } catch (e) {
        el.innerHTML = '<option value="">' + escapeHtml(<?php echo json_encode(t('asset-management.scan.load_failed')); ?>) + '</option>';
    }
}

function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

if (SCAN_ASSET_ID) {
    // Response keys are the endpoints' own: `asset_status_types` and `locations`.
    // Retired statuses are filtered out here — you shouldn't be able to put an
    // asset into a status the desk has stopped using.
    fillSelect('scanStatus',   SCAN_API_BASE + 'get_asset_status_types.php',
               d => (d.asset_status_types || []).filter(s => s.is_active !== false), SCAN_STATUS_ID);
    fillSelect('scanLocation', SCAN_API_BASE + 'get_asset_locations.php',
               d => d.locations || [], SCAN_LOC_ID);
}

/**
 * Save one field. Reuses the module's existing single-field endpoint, so the
 * validation, the asset history entry and the warranty-calendar sync all happen
 * exactly as they do on the desktop — a phone edit is not a lesser edit.
 */
async function saveField(field, value) {
    const out = document.getElementById('scanSaved');
    out.className = 'saved';
    out.textContent = <?php echo json_encode(t('common.saving')); ?>;
    try {
        const res = await fetch(SCAN_API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ asset_id: SCAN_ASSET_ID, field: field, value: value === '' ? null : value })
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || <?php echo json_encode(t('asset-management.scan.save_failed')); ?>);
        const savedMsg = <?php echo json_encode(t('common.saved')); ?>;
        out.textContent = savedMsg;
        setTimeout(() => { if (out.textContent === savedMsg) out.textContent = ''; }, 2500);
    } catch (e) {
        out.className = 'saved error';
        out.textContent = <?php echo json_encode(t('asset-management.scan.save_error')); ?>.replace('{message}', e.message);
    }
}
</script>
</body>
</html>
