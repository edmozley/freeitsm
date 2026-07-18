<?php
/**
 * API Endpoint: create/update a website chat widget.
 *
 * A widget is stored as two rows kept in step inside one transaction:
 *   messaging_channels  — the channel spine (name, company routing, active flag).
 *                         channel_type='webchat', provider='freeitsm' (no external
 *                         provider, no credentials).
 *   webchat_widgets     — the browser-facing config (public key, origins, greeting,
 *                         colour, launcher text, offline message, email gate).
 *
 * The widget_key is generated once on create and never changes (it's baked into the
 * customer's embed snippet). There are no secrets here, so nothing is encrypted.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/webchat/webchat.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Web chat settings tab.
requireModuleAccessJson('tickets');
requireCapabilityJson(Cap::TICKETS_WEBCHAT);

try {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        throw new Exception('Invalid request data');
    }

    $id        = $data['id'] ?? null;              // webchat_widgets.id (edit only)
    $name      = trim((string) ($data['name'] ?? ''));
    $greeting  = trim((string) ($data['greeting'] ?? ''));
    $accent    = trim((string) ($data['accent_colour'] ?? ''));
    $launcher  = trim((string) ($data['launcher_text'] ?? ''));
    $offline   = trim((string) ($data['offline_message'] ?? ''));
    $origins   = trim((string) ($data['allowed_origins'] ?? ''));
    $reqEmail  = !empty($data['require_email']) ? 1 : 0;
    $isActive  = !empty($data['is_active']) ? 1 : 0;

    // Availability + email + AI controls.
    $calendarId  = $data['business_calendar_id'] ?? null;
    $calendarId  = ($calendarId === '' || $calendarId === 0 || $calendarId === '0') ? null : (int) $calendarId;
    $emailAway   = !empty($data['email_when_away']) ? 1 : 0;
    $aiEnabled   = !empty($data['ai_enabled']) ? 1 : 0;
    $aiMode      = ($data['ai_mode'] ?? 'assist') === 'deflect' ? 'deflect' : 'assist';
    $aiOfferAgent = !empty($data['ai_offer_agent']) ? 1 : 0;
    $aiOfferEmail = !empty($data['ai_offer_email']) ? 1 : 0;

    if ($name === '') {
        throw new Exception('Name is required');
    }
    // Accent, if given, must look like a hex colour so it's safe to drop into CSS later.
    if ($accent !== '' && !preg_match('/^#?[0-9a-fA-F]{3,8}$/', $accent)) {
        throw new Exception('Accent colour must be a hex value like #2563eb');
    }
    if ($accent !== '' && $accent[0] !== '#') {
        $accent = '#' . $accent;
    }
    // Normalise the origin list before storing, so the public gate can match exactly.
    $origins = implode("\n", webchatParseOrigins($origins));

    $conn = connectToDatabase();

    // Validate the chosen business-hours calendar actually exists (else treat as none).
    if ($calendarId !== null) {
        try {
            $ck = $conn->prepare("SELECT COUNT(*) FROM sla_calendars WHERE id = ?");
            $ck->execute([$calendarId]);
            if ((int) $ck->fetchColumn() === 0) {
                $calendarId = null;
            }
        } catch (Exception $e) { $calendarId = null; }
    }

    // Pinned company (NULL = shared / single-company install). Validate it's real.
    $tenantId = $data['tenant_id'] ?? null;
    if ($tenantId === '' || $tenantId === 0 || $tenantId === '0') {
        $tenantId = null;
    }
    if ($tenantId !== null) {
        $tenantId = (int) $tenantId;
        $chk = $conn->prepare("SELECT COUNT(*) FROM tenants WHERE id = ?");
        $chk->execute([$tenantId]);
        if ((int) $chk->fetchColumn() === 0) {
            $tenantId = null;
        }
    }
    // ...and it must be a company this analyst can reach. Refuse rather than
    // silently falling back to shared, which would widen the widget instead of
    // failing — a widget is embedded on one client's website.
    if (!analystCanAssignTenant($conn, (int) $_SESSION['analyst_id'], $tenantId)) {
        echo json_encode(['success' => false, 'error' => 'You do not have access to that company']);
        exit;
    }

    $conn->beginTransaction();

    if ($id) {
        // Edit: find the existing widget + its channel, then update both rows.
        $cur = $conn->prepare("SELECT channel_id FROM webchat_widgets WHERE id = ?");
        $cur->execute([(int) $id]);
        $channelId = $cur->fetchColumn();
        if (!$channelId) {
            throw new Exception('Widget not found');
        }
        $channelId = (int) $channelId;

        // The widget being edited must belong to a company this analyst may
        // administer, or they could re-point another client's live widget.
        if (!analystCanAccessChannel($conn, (int) $_SESSION['analyst_id'], $channelId)) {
            throw new Exception('Widget not found');
        }

        $conn->prepare(
            "UPDATE messaging_channels
             SET name = ?, tenant_id = ?, is_active = ?
             WHERE id = ? AND channel_type = 'webchat'"
        )->execute([$name, $tenantId, $isActive, $channelId]);

        $conn->prepare(
            "UPDATE webchat_widgets
             SET allowed_origins = ?, greeting = ?, accent_colour = ?,
                 launcher_text = ?, offline_message = ?, require_email = ?,
                 business_calendar_id = ?, email_when_away = ?, ai_enabled = ?,
                 ai_mode = ?, ai_offer_agent = ?, ai_offer_email = ?
             WHERE id = ?"
        )->execute([
            $origins ?: null, $greeting ?: null, $accent ?: null,
            $launcher ?: null, $offline ?: null, $reqEmail,
            $calendarId, $emailAway, $aiEnabled, $aiMode, $aiOfferAgent, $aiOfferEmail,
            (int) $id,
        ]);

        $conn->commit();
        echo json_encode(['success' => true, 'id' => (int) $id, 'message' => 'Widget saved']);
    } else {
        // Create: the channel spine first, then the widget config with a fresh key.
        $conn->prepare(
            "INSERT INTO messaging_channels
                 (name, channel_type, provider, tenant_id, is_active)
             VALUES (?, 'webchat', 'freeitsm', ?, ?)"
        )->execute([$name, $tenantId, $isActive]);
        $channelId = (int) $conn->lastInsertId();

        // Vanishingly unlikely, but loop until the generated key is unique.
        do {
            $widgetKey = webchatGenerateKey();
            $exists = $conn->prepare("SELECT 1 FROM webchat_widgets WHERE widget_key = ?");
            $exists->execute([$widgetKey]);
        } while ($exists->fetchColumn());

        $conn->prepare(
            "INSERT INTO webchat_widgets
                 (channel_id, widget_key, allowed_origins, greeting, accent_colour,
                  launcher_text, offline_message, require_email, business_calendar_id,
                  email_when_away, ai_enabled, ai_mode, ai_offer_agent, ai_offer_email)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $channelId, $widgetKey, $origins ?: null, $greeting ?: null,
            $accent ?: null, $launcher ?: null, $offline ?: null, $reqEmail,
            $calendarId, $emailAway, $aiEnabled, $aiMode, $aiOfferAgent, $aiOfferEmail,
        ]);
        $newId = (int) $conn->lastInsertId();

        $conn->commit();
        echo json_encode([
            'success'       => true,
            'id'            => $newId,
            'widget_key'    => $widgetKey,
            'embed_snippet' => webchatEmbedSnippet($conn, $widgetKey),
            'message'       => 'Widget created',
        ]);
    }

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
