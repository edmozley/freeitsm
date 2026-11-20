<?php
/**
 * RFP Builder — single-page document preview.
 * Renders the full document — framing sections (introduction / scope /
 * response_instructions) followed by per-category sections — as a clean
 * print-friendly document. No editing UI. PDF export is browser print
 * (Ctrl+P / Cmd+P) for now; Phase 4d will add a dedicated export button
 * and any final styling polish.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/../../includes/i18n.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

if (!isset($_SESSION['analyst_id'])) {
    http_response_code(401);
    echo 'Not authenticated';
    exit;
}
requireModuleAccess('contracts');

$rfpId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($rfpId <= 0) {
    http_response_code(400);
    echo 'Missing id';
    exit;
}

$conn = connectToDatabase();

// The RFP's company (3.5.0): one this analyst cannot reach does not exist.
require_once '../../includes/rfp_scope.php';
if (!rfpCanView($conn, (int)$_SESSION['analyst_id'], $rfpId)) {
    http_response_code(404);
    echo 'RFP not found';
    exit;
}

$rfpStmt = $conn->prepare("SELECT name, status FROM rfps WHERE id = ?");
$rfpStmt->execute([$rfpId]);
$rfp = $rfpStmt->fetch(PDO::FETCH_ASSOC);
if (!$rfp) {
    http_response_code(404);
    echo 'RFP not found';
    exit;
}

$framingStmt = $conn->prepare(
    "SELECT section_key, section_title, section_content
       FROM rfp_document_sections
      WHERE rfp_id = ?
   ORDER BY sort_order, id"
);
$framingStmt->execute([$rfpId]);
$framing = $framingStmt->fetchAll(PDO::FETCH_ASSOC);

$catStmt = $conn->prepare(
    "SELECT c.id, c.name, c.description, s.section_content
       FROM rfp_categories c
  LEFT JOIN rfp_output_sections s ON s.category_id = c.id
      WHERE c.rfp_id = ?
   ORDER BY c.sort_order, c.id"
);
$catStmt->execute([$rfpId]);
$categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

$today = DateFmt::render(new DateTime('now', new DateTimeZone(Tz::current())), 'D MONTH YYYY');
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars(pageTitle('contracts', t('contracts.nav.rfp_builder'), t('contracts.rfp.preview.title_prefix') . ' · ' . $rfp['name'])); ?></title>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Georgia', 'Times New Roman', serif;
            background: #e5e7eb;
            margin: 0; padding: 24px 0;
            color: #1f2937;
            line-height: 1.6;
        }

        .pv-toolbar {
            position: sticky; top: 0; z-index: 10;
            background: #1f2937; color: white;
            padding: 10px 20px;
            display: flex; align-items: center; gap: 14px;
            font-family: 'Segoe UI', Tahoma, Geneva, sans-serif;
            font-size: var(--font-size-lg, 14px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .pv-toolbar .pv-title { flex: 1; font-weight: 600; }
        .pv-toolbar a, .pv-toolbar button {
            background: rgba(255,255,255,0.1); color: white;
            border: 1px solid rgba(255,255,255,0.2); padding: 6px 12px;
            border-radius: 6px; cursor: pointer; font-size: var(--font-size-md, 13px);
            font-family: inherit; text-decoration: none;
        }
        .pv-toolbar a:hover, .pv-toolbar button:hover {
            background: rgba(255,255,255,0.2);
        }
        .pv-toolbar .pv-print-hint {
            font-size: var(--font-size-sm, 12px); color: rgba(255,255,255,0.6);
        }

        .pv-page {
            max-width: 880px; margin: 24px auto;
            background: white;
            box-shadow: 0 4px 24px rgba(0,0,0,0.1);
            padding: 60px 70px;
        }

        .pv-cover {
            text-align: center; padding: 40px 0 30px;
            border-bottom: 2px solid #1f2937;
            margin-bottom: 40px;
        }
        .pv-cover .pv-label {
            font-size: var(--font-size-md, 13px); color: #6b7280;
            text-transform: uppercase; letter-spacing: 2px;
            margin-bottom: 14px;
        }
        .pv-cover h1 {
            margin: 0; font-size: 30px; font-weight: 700;
            color: #111827; line-height: 1.25;
        }
        .pv-cover .pv-meta {
            margin-top: 18px; font-size: var(--font-size-md, 13px); color: #6b7280;
        }

        .pv-toc {
            border: 1px solid #e5e7eb; border-radius: 6px;
            padding: 18px 24px; margin-bottom: 40px;
            background: #fafbfc;
            font-family: 'Segoe UI', Tahoma, Geneva, sans-serif;
        }
        .pv-toc h2 {
            margin: 0 0 12px 0; font-size: var(--font-size-lg, 14px); font-weight: 700; color: #374151;
            text-transform: uppercase; letter-spacing: 0.5px;
        }
        .pv-toc ol {
            margin: 0; padding-left: 22px;
            font-size: var(--font-size-md, 13px); line-height: 1.9;
        }
        .pv-toc a { color: #1f2937; text-decoration: none; }
        .pv-toc a:hover { color: #f59e0b; }

        h2.pv-section-title {
            font-family: 'Segoe UI', Tahoma, Geneva, sans-serif;
            font-size: var(--font-size-4xl, 24px); font-weight: 700; color: #111827;
            margin: 48px 0 14px 0;
            padding-bottom: 6px; border-bottom: 1px solid #e5e7eb;
        }
        h2.pv-section-title:first-of-type { margin-top: 20px; }

        .pv-section-body h3 {
            font-family: 'Segoe UI', Tahoma, Geneva, sans-serif;
            font-size: var(--font-size-xl, 16px); font-weight: 700; color: #1f2937;
            margin: 22px 0 10px 0;
        }
        .pv-section-body h4 {
            font-family: 'Segoe UI', Tahoma, Geneva, sans-serif;
            font-size: var(--font-size-md, 13px); font-weight: 700; color: #374151;
            margin: 16px 0 8px 0;
        }
        .pv-section-body p { margin: 0 0 12px 0; font-size: var(--font-size-lg, 14px); }
        .pv-section-body ul, .pv-section-body ol {
            margin: 0 0 14px 22px; font-size: var(--font-size-lg, 14px);
        }
        .pv-section-body li { margin-bottom: 6px; }
        .pv-section-body strong { color: #111827; }
        .pv-section-body table {
            border-collapse: collapse; margin: 14px 0; width: 100%; font-size: var(--font-size-md, 13px);
        }
        .pv-section-body th, .pv-section-body td {
            border: 1px solid #e5e7eb; padding: 7px 10px; text-align: left;
        }
        .pv-section-body th { background: #f9fafb; font-weight: 600; }

        .pv-empty-section {
            color: #999; font-style: italic;
            padding: 14px 0; font-family: 'Segoe UI', Tahoma, sans-serif;
        }


        /* ---- Mobile (#1362) ----------------------------------------------
           Its own @media block rather than a link to assets/css/mobile.css,
           for the same reason the equipment report has one: this is a plain
           scrolling document with no app shell, and LAYER 2's
           `body { height: 100dvh; display: flex }` would clip it.

           It was the ONLY one of the twenty-two pages already contained at
           360px before this round (docScrollW=345), which is what a page
           built as a document rather than as an application looks like. So
           nothing here is a repair — it is the difference between a page that
           fits and a page that reads. 70px of side padding is 39% of a 360px
           screen, and a `.pv-section-body` table is AI-generated content of
           unknown width, contained here for the same reason a Knowledge
           article's author HTML is. */
        @media (max-width: 768px) {
            body { padding: 0; }
            .pv-toolbar {
                flex-wrap: wrap;
                gap: 8px;
                padding: 8px 12px;
            }
            .pv-toolbar .pv-title { flex: 1 1 100%; font-size: var(--font-size-xl, 16px); }
            .pv-toolbar a, .pv-toolbar button {
                flex: 1 1 auto;
                min-height: 40px;
                text-align: center;
            }
            /* "Use your browser's Print to save a PDF" — true on a desktop,
               and on a phone it is the share sheet. It is guidance rather
               than a control, so it takes its own line instead of competing
               with the buttons for one. */
            .pv-toolbar .pv-print-hint { flex: 1 1 100%; }
            .pv-page {
                max-width: none;
                margin: 12px;
                padding: 22px 16px;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            }
            .pv-cover { padding: 20px 0 16px; margin-bottom: 24px; }
            /* The document body is generated prose and can contain a table of
               any width. Contain it so it cannot take the page with it. */
            .pv-section-body table {
                display: block;
                overflow-x: auto;
                overscroll-behavior-x: contain;
                -webkit-overflow-scrolling: touch;
                max-width: 100%;
            }
            .pv-section-body ul, .pv-section-body ol { margin-left: 18px; }
        }
        @media print {
            body { background: white; padding: 0; }
            .pv-toolbar { display: none; }
            .pv-page {
                box-shadow: none; margin: 0; padding: 30px 0;
                max-width: none;
            }
            .pv-cover { page-break-after: avoid; }
            h2.pv-section-title { page-break-after: avoid; }
            .pv-section-body { page-break-before: auto; }
        }
    </style>
</head>
<body>
    <div class="pv-toolbar">
        <a href="document.php?id=<?= (int)$rfpId ?>">&larr; <?= htmlspecialchars(t('contracts.rfp.preview.back_to_document')) ?></a>
        <div class="pv-title"><?= htmlspecialchars($rfp['name'], ENT_QUOTES) ?></div>
        <span class="pv-print-hint"><?= htmlspecialchars(t('contracts.rfp.preview.print_hint')) ?></span>
        <button onclick="window.print()"><?= htmlspecialchars(t('contracts.rfp.preview.print_pdf')) ?></button>
    </div>

    <div class="pv-page">
        <div class="pv-cover">
            <div class="pv-label"><?= htmlspecialchars(t('contracts.rfp.preview.rfp_label')) ?></div>
            <h1><?= htmlspecialchars($rfp['name'], ENT_QUOTES) ?></h1>
            <div class="pv-meta"><?= htmlspecialchars(t('contracts.rfp.preview.issued', ['date' => $today])) ?></div>
        </div>

        <?php
            // Build a unified TOC: framing sections first, then categories.
            $tocItems = [];
            foreach ($framing as $f) {
                $tocItems[] = [
                    'id'    => 'sec-fr-' . $f['section_key'],
                    'title' => $f['section_title']
                ];
            }
            foreach ($categories as $c) {
                $tocItems[] = [
                    'id'    => 'sec-cat-' . $c['id'],
                    'title' => $c['name']
                ];
            }
        ?>
        <?php if (!empty($tocItems)): ?>
            <div class="pv-toc">
                <h2><?= htmlspecialchars(t('contracts.rfp.preview.contents')) ?></h2>
                <ol>
                    <?php foreach ($tocItems as $t): ?>
                        <li><a href="#<?= htmlspecialchars($t['id'], ENT_QUOTES) ?>"><?= htmlspecialchars($t['title']) ?></a></li>
                    <?php endforeach; ?>
                </ol>
            </div>
        <?php endif; ?>

        <?php foreach ($framing as $f): ?>
            <h2 class="pv-section-title" id="sec-fr-<?= htmlspecialchars($f['section_key'], ENT_QUOTES) ?>">
                <?= htmlspecialchars($f['section_title']) ?>
            </h2>
            <?php if (!empty($f['section_content'])): ?>
                <div class="pv-section-body"><?= $f['section_content'] /* trusted: AI-generated HTML stored in our own DB */ ?></div>
            <?php else: ?>
                <div class="pv-empty-section"><?= htmlspecialchars(t('contracts.rfp.preview.not_drafted')) ?></div>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php foreach ($categories as $c): ?>
            <h2 class="pv-section-title" id="sec-cat-<?= (int)$c['id'] ?>">
                <?= htmlspecialchars($c['name']) ?>
            </h2>
            <?php if (!empty($c['section_content'])): ?>
                <div class="pv-section-body"><?= $c['section_content'] /* trusted */ ?></div>
            <?php else: ?>
                <div class="pv-empty-section"><?= htmlspecialchars(t('contracts.rfp.preview.not_generated')) ?></div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</body>
</html>
