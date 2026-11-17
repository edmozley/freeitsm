<?php
/**
 * Equipment handover document (discussion #56).
 *
 * A printable record that a named person received a named list of equipment,
 * with somewhere for both of them to sign. The layout comes from a template
 * built in Assets → Settings → Handover; an install that has never opened the
 * designer gets the shipped default, which is why HandoverTemplates::effective()
 * always returns something.
 *
 * ⚠️ RENDERED SERVER-SIDE, ON PURPOSE. This is the one page in the module that
 * somebody signs and files, so it must not depend on JavaScript having run, on a
 * fetch having succeeded, or on a library being present. What the printer puts
 * on paper is what the server sent.
 *
 * Printing is the browser's own dialogue rather than a generated PDF: it gives a
 * true PDF via "Save as PDF" everywhere, keeps the text selectable, and inherits
 * the logo and fonts already on the page. A jsPDF re-implementation would be a
 * second layout to keep in step with this one.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/services/assets.php';
require_once '../includes/services/handover_templates.php';
require_once '../includes/handover_styles.php';
require_once '../includes/tenancy.php';
I18n::initFromSession();

requireModuleAccess('assets');
Tz::init();   // the analyst's zone, for the assigned date (fmt_local)

$userId     = (int)($_GET['user_id'] ?? 0);
$templateId = isset($_GET['template_id']) ? (int)$_GET['template_id'] : null;

$conn = connectToDatabase();
$data = $userId > 0 ? AssetsService::assetsForUser($conn, ActorContext::fromSession($conn), $userId) : null;

if ($data === null) {
    http_response_code(404);
    $notFound = true;
} else {
    $notFound = false;
    $user   = $data['user'];
    $assets = $data['assets'];

    // The company logo Branding manages, so a handover looks like the rest of
    // the organisation's paperwork.
    $logoPath = null;
    try {
        $s = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'branding_logo_path'");
        $s->execute();
        $p = (string)($s->fetchColumn() ?: '');
        if ($p !== '' && file_exists(__DIR__ . '/../' . $p)) {
            $logoPath = '../' . $p;
        }
    } catch (Exception $e) {
        // A missing logo is cosmetic.
    }

    $template = HandoverTemplates::effective($conn, $templateId);
    $body = HandoverTemplates::renderBlocks($template['blocks'], $user, $assets, [
        'logo_path'    => $logoPath,
        'analyst_name' => $_SESSION['analyst_name'] ?? null,
    ]);
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('assets', t('asset-management.handover.page_title'))); ?></title>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <style>
        body { margin: 0; padding: 32px; background: #f4f6f8; }
        .sheet {
            max-width: 820px; margin: 0 auto; background: #fff;
            padding: 40px 44px; border-radius: 6px; box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        }
        .toolbar { max-width: 820px; margin: 0 auto 14px; display: flex; gap: 8px; justify-content: flex-end; }
        .tb-btn {
            padding: 8px 16px; border: 1px solid #ccd2d8; border-radius: 5px;
            background: #fff; color: #1a1a1a; font-size: 13px; font-weight: 600; cursor: pointer;
            font-family: inherit;
        }
        .tb-btn.primary { background: #0078d4; border-color: #0078d4; color: #fff; }
        .tb-btn:active { transform: scale(0.97); }
        .tb-msg { align-self: center; font-size: 13px; color: #2f7d32; }
        <?php echo handoverDocumentCss(); ?>
        <?php echo handoverPrintCss(); ?>
    </style>
</head>
<body>

<?php if ($notFound): ?>
    <div class="sheet hb-doc">
        <h1 class="doc-title"><?php echo htmlspecialchars(t('asset-management.handover.not_found')); ?></h1>
        <p class="doc-sub"><?php echo htmlspecialchars(t('asset-management.handover.not_found_hint')); ?></p>
    </div>
<?php else: ?>

<div class="toolbar">
    <span class="tb-msg" id="tbMsg"></span>
    <button class="tb-btn" onclick="window.close()"><?php echo htmlspecialchars(t('asset-management.handover.close')); ?></button>
    <?php if (!empty($user['email'])): ?>
        <button class="tb-btn" id="emailBtn"><?php echo htmlspecialchars(t('asset-management.handover.email')); ?></button>
    <?php endif; ?>
    <button class="tb-btn primary" onclick="window.print()"><?php echo htmlspecialchars(t('asset-management.handover.print')); ?></button>
</div>

<div class="sheet hb-doc"><?php echo $body; ?></div>

<script>
const emailBtn = document.getElementById('emailBtn');
if (emailBtn) {
    emailBtn.addEventListener('click', async function () {
        emailBtn.disabled = true;
        const msg = document.getElementById('tbMsg');
        msg.textContent = '';
        try {
            const r = await fetch('../api/assets/email_handover.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    user_id: <?php echo (int)$user['id']; ?>,
                    template_id: <?php echo $templateId ? (int)$templateId : 'null'; ?>
                })
            });
            const d = await r.json();
            msg.style.color = d.success ? '#2f7d32' : '#c62828';
            msg.textContent = d.success
                ? <?php echo json_encode(t('asset-management.handover.email_sent')); ?>
                : (d.error || <?php echo json_encode(t('asset-management.handover.email_failed')); ?>);
        } catch (e) {
            msg.style.color = '#c62828';
            msg.textContent = <?php echo json_encode(t('asset-management.handover.email_failed')); ?>;
        }
        emailBtn.disabled = false;
    });
}
</script>

<?php endif; ?>
</body>
</html>
