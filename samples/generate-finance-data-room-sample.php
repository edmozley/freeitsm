<?php
/**
 * Generates a sample Finance department .docx for the same secure data
 * room procurement, with deliberate overlaps and a couple of direct
 * conflicts vs the IT department version (e.g. data residency, audit
 * retention) — useful for testing the consolidation flow in Phase 3.
 *
 * Output: samples/finance-department-secure-data-room.docx
 *
 * Usage (Windows / WAMP):
 *     "C:\wamp64\bin\php\php8.4.0\php.exe" samples/generate-finance-data-room-sample.php
 */

$content = [
    ['h1', 'Finance Department Requirements: New Secure Data Room System'],

    ['h2', 'Background'],
    ['p', "The Finance team uses data rooms extensively during M&A transactions, lender due diligence, and our annual statutory audit. Our requirements have evolved significantly since the current platform was procured in 2019, particularly around regulatory reporting, cost transparency, and integration with our financial systems. This document captures the Finance perspective on the replacement system, with a particular focus on commercial terms and audit fitness."],

    ['h2', 'Commercial and contractual'],
    ['p', "Cost predictability is the single most important factor for our budgeting cycle. Specifically:"],
    ['ul', [
        "The pricing model must be transparent and stable, with no usage-based or per-document fees that introduce billing volatility.",
        "A flat per-user-per-month or per-deal pricing model is strongly preferred. We need to be able to forecast spend within ±5% over a 12-month horizon.",
        "The contract must allow us to predict cost per deal so that data room costs can be charged back to the relevant business unit and ultimately to the deal P&L.",
        "Pricing must be quoted and invoiced in pounds sterling (GBP). Multi-currency invoicing capability would be a nice-to-have for our international subsidiaries.",
        "Payment terms must be net 30 days minimum.",
        "The contract must include a clear termination clause with no early-exit penalty after the initial 12 months.",
        "The contract minimum must not exceed 12 months. We will not sign multi-year deals up front, even at discount, given how rapidly the market is moving.",
        "Auto-renewal must be explicit opt-in, not opt-out. We have been caught out by silent renewals in the past.",
        "The vendor must provide audited financial statements as part of due diligence — we will not engage with a supplier that cannot demonstrate financial stability over at least three full reporting years.",
    ]],

    ['h2', 'Regulatory and audit retention'],
    ['p', "Our statutory audit, SOX-equivalent internal control framework, and HMRC requirements drive these:"],
    ['ul', [
        "Audit logs must be retained for a minimum of 10 years to satisfy our financial records retention policy. This is non-negotiable and reflects external auditor requirements.",
        "The system must be able to produce audit packs for our external auditors on demand, exported as PDF with cryptographic timestamping where possible.",
        "Document retention policies must be configurable at the workspace level, with optional legal hold that overrides any standard deletion schedule.",
        "Automatic deletion of expired documents must be auditable — a deletion event must record what was deleted, by whom, and under which retention rule.",
        "The platform must support evidence preservation for ongoing or anticipated litigation. Setting a litigation hold must freeze deletion across an entire workspace immediately, regardless of other rules.",
    ]],

    ['h2', 'Cost reporting and chargeback'],
    ['ul', [
        "The system must provide a per-workspace cost report each month, broken down by user, document storage, and any per-action charges.",
        "Reports must be exportable as CSV and ideally pushed to our finance system (Sage Intacct) via API or scheduled email.",
        "We need visibility into the per-deal cost so that costs can be attributed to specific transactions for management accounting.",
        "Storage costs must be itemised separately from access costs so that we can challenge the vendor on usage patterns.",
    ]],

    ['h2', 'Document and workflow features'],
    ['p', "Finance-specific document types and behaviours:"],
    ['ul', [
        "Excel files (xlsx, xlsm) must be supported in view-only mode with formula visibility preserved. Currently our staff have to download spreadsheets to inspect formulas, which defeats the point of view-only.",
        "The system must support large multi-tab spreadsheets (up to 50 sheets, individual sheets up to 100,000 rows) without timing out or losing formatting.",
        "Watermarking on Excel views must include the username, timestamp and a deal identifier.",
        "Bulk download of an entire deal workspace to a structured zip with manifest and audit trail must be supported, both for our own archive and for handover to legal counsel.",
        "The system should allow bulk redaction of selected text fields across a document set — this is currently a manual process that consumes significant junior staff time.",
    ]],

    ['h2', 'Data residency and protection'],
    ['p', "We have specific Finance-driven views on data residency that are stricter than the wider IT position:"],
    ['ul', [
        "Customer data must be hosted within the United Kingdom only. Hosting in any other jurisdiction, including the European Economic Area, is not acceptable for documents covered by our SOX-equivalent controls.",
        "Encryption keys must be held in a UK-based key management service. Cross-border key access is not acceptable.",
        "Backups, DR copies, and any temporary processing locations must also be UK-only. We require a written attestation as part of the contract.",
        "The vendor must declare and maintain a list of all subprocessors with access to our data. Any change to this list requires 30 days notice and our written consent.",
    ]],

    ['h2', 'Pain points with the current platform'],
    ['p', "For context, the most common Finance complaints with the current system:"],
    ['ul', [
        "Costs are unpredictable — the per-document upload fee and per-download charge make month-to-month spend extremely variable, sometimes ±40%.",
        "We have no visibility on which deal a given cost item relates to. Our chargeback process is largely manual reconciliation against the vendor's invoice line items.",
        "Excel files render poorly in view-only mode. Numeric formatting is lost and formulas are not visible. Deal teams routinely download files and edit locally as a workaround.",
        "Audit log exports are limited to 90 days, which is incompatible with our 10-year retention obligation. We have been forced to maintain a parallel local archive at additional cost.",
        "The auto-renewal terms in the current contract were missed during our last review, resulting in an additional 24-month term we could not exit. Future contracts must have unambiguous renewal provisions.",
        "Currency on invoices is USD only, requiring our AP team to recharge against fluctuating exchange rates. Invoicing in GBP would resolve this.",
    ]],

    ['p', "This document represents Finance's input. We have shared it with IT, Legal and Risk to align on overlapping themes ahead of the joint procurement workshop."],
];

// ---------------------------------------------------------------
// Renderer (same shape as the IT generator)
// ---------------------------------------------------------------

function escapeXml(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function makePara(string $text, ?string $style = null): string {
    $pPr = $style ? '<w:pPr><w:pStyle w:val="' . $style . '"/></w:pPr>' : '';
    return '<w:p>' . $pPr . '<w:r><w:t xml:space="preserve">' . escapeXml($text) . '</w:t></w:r></w:p>';
}

function makeListItem(string $text): string {
    return '<w:p><w:pPr><w:pStyle w:val="ListBullet"/></w:pPr>'
         . '<w:r><w:t xml:space="preserve">• ' . escapeXml($text) . '</w:t></w:r></w:p>';
}

$body = '';
foreach ($content as $item) {
    [$type, $value] = $item;
    if ($type === 'h1')      { $body .= makePara($value, 'Heading1'); }
    elseif ($type === 'h2')  { $body .= makePara($value, 'Heading2'); }
    elseif ($type === 'p')   { $body .= makePara($value); }
    elseif ($type === 'ul')  { foreach ($value as $li) { $body .= makeListItem($li); } }
}

$documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
    . '<w:body>' . $body
    . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/></w:sectPr>'
    . '</w:body></w:document>';

$stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
    . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/><w:sz w:val="22"/></w:rPr></w:rPrDefault></w:docDefaults>'
    . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:pPr><w:spacing w:after="120"/></w:pPr></w:style>'
    . '<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:pPr><w:spacing w:before="320" w:after="160"/><w:outlineLvl w:val="0"/></w:pPr><w:rPr><w:b/><w:sz w:val="36"/></w:rPr></w:style>'
    . '<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:pPr><w:spacing w:before="240" w:after="120"/><w:outlineLvl w:val="1"/></w:pPr><w:rPr><w:b/><w:sz w:val="28"/></w:rPr></w:style>'
    . '<w:style w:type="paragraph" w:styleId="ListBullet"><w:name w:val="List Bullet"/><w:basedOn w:val="Normal"/><w:pPr><w:spacing w:after="60"/><w:ind w:left="360"/></w:pPr></w:style>'
    . '</w:styles>';

$contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
    . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
    . '</Types>';

$rootRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
    . '</Relationships>';

$docRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
    . '</Relationships>';

$outFile = __DIR__ . DIRECTORY_SEPARATOR . 'finance-department-secure-data-room.docx';
@unlink($outFile);

$zip = new ZipArchive();
if ($zip->open($outFile, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Could not create $outFile\n");
    exit(1);
}
$zip->addFromString('[Content_Types].xml', $contentTypesXml);
$zip->addFromString('_rels/.rels', $rootRelsXml);
$zip->addFromString('word/document.xml', $documentXml);
$zip->addFromString('word/_rels/document.xml.rels', $docRelsXml);
$zip->addFromString('word/styles.xml', $stylesXml);
$zip->close();

$bytes = filesize($outFile);
echo "Wrote $outFile ($bytes bytes)\n";
