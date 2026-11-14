<?php
/**
 * API: one catalogue form and its fields, for the portal.
 * GET ?id=
 *
 * A deliberately NARROWER shape than the analyst's api/forms/get_form.php,
 * which also returns created_by_name, modified_by_name, version_number and
 * is_leaf. None of that is a customer's business: who wrote the form and how
 * many times it has been revised is internal detail.
 *
 * The visibility gate is in the QUERY, so a form that isn't in the catalogue is
 * indistinguishable from one that doesn't exist — knowing its id gets you
 * nothing.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$formId = (int)($_GET['id'] ?? 0);
if (!$formId) {
    echo json_encode(['success' => false, 'error' => 'Form not found']);
    exit;
}

try {
    $conn = connectToDatabase();
    // GH #217: nothing from a module switched off for the install.
    requireModuleOnJson($conn, 'forms');

    /* 🔴 The AUDIENCE gate too (GH #145). The catalogue list already hides a
       restricted form, and a list hiding a card has never been a check — this
       is the path a bookmarked link or a colleague's URL takes. In the QUERY,
       so "not for you" and "does not exist" are the same answer. */
    require_once __DIR__ . '/../../includes/services/forms.php';

    $stmt = $conn->prepare(
        "SELECT f.id, f.title, f.description
         FROM forms f
         WHERE f.id = :fid
           AND f.is_portal_visible = 1
           AND f.is_active = 1
           AND NOT EXISTS (SELECT 1 FROM forms ch WHERE ch.parent_form_id = f.id)
           AND " . FormsService::portalAudienceSql($conn, 'f')
    );
    $stmt->execute([':fid' => $formId, ':audUser' => (int)$_SESSION['ss_user_id']]);
    $form = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$form) {
        echo json_encode(['success' => false, 'error' => 'Form not found']);
        exit;
    }

    // The price, beside the title (GH #348): what the person is asking for costs this.
    require_once __DIR__ . '/../../includes/catalogue_card.php';
    $form['price_label'] = '';
    if (catalogueCardAvailable($conn)) {
        $ps = $conn->prepare('SELECT catalogue_price, catalogue_currency, catalogue_price_period FROM forms WHERE id = ?');
        $ps->execute([$formId]);
        $p = $ps->fetch(PDO::FETCH_ASSOC) ?: [];
        $form['price_label'] = cataloguePriceLabel($p['catalogue_price'] ?? null, $p['catalogue_currency'] ?? null, $p['catalogue_price_period'] ?? null);
    }

    // Retired fields are excluded — they exist only to keep old submissions readable.
    $fStmt = $conn->prepare(
        "SELECT id, field_type, label, options, is_required, sort_order, config
         FROM form_fields WHERE form_id = ? AND is_deleted = 0 ORDER BY sort_order, id"
    );
    $fStmt->execute([$formId]);
    $form['fields'] = $fStmt->fetchAll(PDO::FETCH_ASSOC);

    /* The same resolved layout the analyst side gets, from the same service —
       a customer must see the form laid out the way it was designed, not a
       portal-specific guess. ⚠️ Guarded, because before Database Verification
       the column does not exist and the catalogue must still open. */
    require_once __DIR__ . '/../../includes/services/forms.php';
    $storedLayout = null;
    if (FormsService::layoutAvailable($conn)) {
        $ls = $conn->prepare("SELECT layout FROM forms WHERE id = ?");
        $ls->execute([$formId]);
        $storedLayout = $ls->fetchColumn();
        if ($storedLayout === false) $storedLayout = null;
    }
    $form['layout'] = FormsService::layoutFor($storedLayout, $form['fields']);

    echo json_encode(['success' => true, 'form' => $form]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
