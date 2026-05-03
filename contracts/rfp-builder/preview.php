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

if (!isset($_SESSION['analyst_id'])) {
    http_response_code(401);
    echo 'Not authenticated';
    exit;
}

$rfpId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($rfpId <= 0) {
    http_response_code(400);
    echo 'Missing id';
    exit;
}

$conn = connectToDatabase();

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

$today = date('j F Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Preview · <?= htmlspecialchars($rfp['name'], ENT_QUOTES) ?></title>
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
            font-size: 14px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .pv-toolbar .pv-title { flex: 1; font-weight: 600; }
        .pv-toolbar a, .pv-toolbar button {
            background: rgba(255,255,255,0.1); color: white;
            border: 1px solid rgba(255,255,255,0.2); padding: 6px 12px;
            border-radius: 6px; cursor: pointer; font-size: 13px;
            font-family: inherit; text-decoration: none;
        }
        .pv-toolbar a:hover, .pv-toolbar button:hover {
            background: rgba(255,255,255,0.2);
        }
        .pv-toolbar .pv-print-hint {
            font-size: 12px; color: rgba(255,255,255,0.6);
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
            font-size: 13px; color: #6b7280;
            text-transform: uppercase; letter-spacing: 2px;
            margin-bottom: 14px;
        }
        .pv-cover h1 {
            margin: 0; font-size: 30px; font-weight: 700;
            color: #111827; line-height: 1.25;
        }
        .pv-cover .pv-meta {
            margin-top: 18px; font-size: 13px; color: #6b7280;
        }

        .pv-toc {
            border: 1px solid #e5e7eb; border-radius: 6px;
            padding: 18px 24px; margin-bottom: 40px;
            background: #fafbfc;
            font-family: 'Segoe UI', Tahoma, Geneva, sans-serif;
        }
        .pv-toc h2 {
            margin: 0 0 12px 0; font-size: 14px; font-weight: 700; color: #374151;
            text-transform: uppercase; letter-spacing: 0.5px;
        }
        .pv-toc ol {
            margin: 0; padding-left: 22px;
            font-size: 13px; line-height: 1.9;
        }
        .pv-toc a { color: #1f2937; text-decoration: none; }
        .pv-toc a:hover { color: #f59e0b; }

        h2.pv-section-title {
            font-family: 'Segoe UI', Tahoma, Geneva, sans-serif;
            font-size: 22px; font-weight: 700; color: #111827;
            margin: 48px 0 14px 0;
            padding-bottom: 6px; border-bottom: 1px solid #e5e7eb;
        }
        h2.pv-section-title:first-of-type { margin-top: 20px; }

        .pv-section-body h3 {
            font-family: 'Segoe UI', Tahoma, Geneva, sans-serif;
            font-size: 15px; font-weight: 700; color: #1f2937;
            margin: 22px 0 10px 0;
        }
        .pv-section-body h4 {
            font-family: 'Segoe UI', Tahoma, Geneva, sans-serif;
            font-size: 13px; font-weight: 700; color: #374151;
            margin: 16px 0 8px 0;
        }
        .pv-section-body p { margin: 0 0 12px 0; font-size: 14px; }
        .pv-section-body ul, .pv-section-body ol {
            margin: 0 0 14px 22px; font-size: 14px;
        }
        .pv-section-body li { margin-bottom: 6px; }
        .pv-section-body strong { color: #111827; }
        .pv-section-body table {
            border-collapse: collapse; margin: 14px 0; width: 100%; font-size: 13px;
        }
        .pv-section-body th, .pv-section-body td {
            border: 1px solid #e5e7eb; padding: 7px 10px; text-align: left;
        }
        .pv-section-body th { background: #f9fafb; font-weight: 600; }

        .pv-empty-section {
            color: #999; font-style: italic;
            padding: 14px 0; font-family: 'Segoe UI', Tahoma, sans-serif;
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
        <a href="document.php?id=<?= (int)$rfpId ?>">&larr; Back to document</a>
        <div class="pv-title"><?= htmlspecialchars($rfp['name'], ENT_QUOTES) ?></div>
        <span class="pv-print-hint">Use Ctrl+P / Cmd+P to print or save as PDF</span>
        <button onclick="window.print()">Print / PDF</button>
    </div>

    <div class="pv-page">
        <div class="pv-cover">
            <div class="pv-label">Request for Proposal</div>
            <h1><?= htmlspecialchars($rfp['name'], ENT_QUOTES) ?></h1>
            <div class="pv-meta">Issued <?= htmlspecialchars($today) ?></div>
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
                <h2>Contents</h2>
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
                <div class="pv-empty-section">(Not yet drafted.)</div>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php foreach ($categories as $c): ?>
            <h2 class="pv-section-title" id="sec-cat-<?= (int)$c['id'] ?>">
                <?= htmlspecialchars($c['name']) ?>
            </h2>
            <?php if (!empty($c['section_content'])): ?>
                <div class="pv-section-body"><?= $c['section_content'] /* trusted */ ?></div>
            <?php else: ?>
                <div class="pv-empty-section">(Not yet generated.)</div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</body>
</html>
