<?php
/**
 * Generates a sample Risk department .docx for the same secure data
 * room procurement. Designed to overlap with the IT and Finance
 * versions on themes (auth, residency, certifications) but with
 * stricter / sometimes contradictory specifics — useful for
 * exercising conflict detection in Phase 3.
 *
 * Output: samples/risk-department-secure-data-room.docx
 *
 * Usage (Windows / WAMP):
 *     "C:\wamp64\bin\php\php8.4.0\php.exe" samples/generate-risk-data-room-sample.php
 */

$content = [
    ['h1', 'Risk Department Requirements: New Secure Data Room System'],

    ['h2', 'Background and scope'],
    ['p', "The Risk function reviews all third-party platforms that handle sensitive corporate, customer or transactional data. The data room platform is in scope for our supplier risk programme and is rated Tier 1 — meaning the highest assurance level applies. This document sets out Risk's mandatory and recommended controls for the replacement platform, alongside contractual safeguards that must be present in the engagement."],
    ['p', "We have particular concerns following last year's near-miss incident with the current vendor, where a misconfigured permission allowed unauthorised access to a deal workspace for 11 hours before being remediated. This incident shapes several of the requirements below."],

    ['h2', 'Information security controls'],
    ['p', "Authentication, authorisation and access:"],
    ['ul', [
        "Phishing-resistant multi-factor authentication is mandatory for all users. FIDO2 hardware keys or platform authenticators are the only acceptable factors. SMS, email OTP and TOTP-based MFA must not be enabled, even as fallback options.",
        "The platform must enforce a maximum session length of 8 hours regardless of activity, with idle timeout of 15 minutes.",
        "Privileged access (admin, workspace manager) must require step-up authentication on every session and must be re-authenticated on any sensitive action (permission change, bulk download, deletion).",
        "Just-in-time elevation must be available so that admin rights can be granted for a defined window with full audit trail.",
        "The platform must support conditional access policies based on device posture (managed vs unmanaged), location (geofencing), and risk score from our identity provider.",
    ]],

    ['h2', 'Penetration testing and security assurance'],
    ['p', "The current vendor's annual pen test cycle is insufficient given the sensitivity of the data:"],
    ['ul', [
        "The vendor must commission an independent third-party penetration test at minimum every quarter (i.e. four full assessments per calendar year). An annual frequency is not acceptable.",
        "Executive summaries of every pen test must be made available to us under NDA within 30 days of the test report.",
        "Critical and high-severity findings must be remediated within agreed SLAs (30 days for critical, 60 days for high) with closure evidence shared with us.",
        "The vendor must operate a public bug bounty programme or vulnerability disclosure policy.",
        "Annual SOC 2 Type II report and ISO 27001 certificate are required, but additionally we require ISAE 3402 Type II for any vendor handling financial data on our behalf.",
    ]],

    ['h2', 'Data residency and sovereignty'],
    ['p', "Risk's position on data residency is the most stringent of any department contributing to this RFP:"],
    ['ul', [
        "All customer data must reside in the United Kingdom. Storage, processing, backup and DR copies must all be UK-only. EU/EEA hosting is not acceptable.",
        "The vendor must provide a written attestation, signed by a named officer, that no data leaves UK jurisdiction at any point in the data lifecycle.",
        "Subprocessors must also operate within the UK. Cross-border subprocessor relationships are not permitted for this engagement, even where they are otherwise GDPR-adequate.",
        "The vendor must immediately notify us of any proposed change to the data location or subprocessor list, with a minimum of 60 days for us to evaluate before the change takes effect.",
    ]],

    ['h2', 'Tenancy and isolation'],
    ['ul', [
        "The platform must be deployed on a single-tenant or dedicated-instance basis. Shared multi-tenant infrastructure is not acceptable for Tier 1 data, even with logical isolation.",
        "Underlying compute and storage must not be shared with other customers. Where this is delivered via a hyperscaler (AWS, Azure, GCP), dedicated host or dedicated VPC architecture is required.",
        "The vendor must provide network architecture documentation showing our deployment is isolated from other tenants at the network, compute and storage layers.",
    ]],

    ['h2', 'Incident response and breach notification'],
    ['ul', [
        "The vendor must notify us of any actual or suspected security incident affecting our data within 4 hours of discovery. The 72-hour GDPR notification window is not acceptable for our internal escalation process.",
        "A named incident response lead must be available 24/7 during active incidents.",
        "The vendor's incident response plan must be available for review during procurement, and must align with our own playbook for evidence preservation and law enforcement liaison.",
        "Annual joint incident response exercises (tabletop minimum) must be included as a contractual obligation.",
    ]],

    ['h2', 'Resilience and continuity'],
    ['ul', [
        "The platform must offer a contractual uptime SLA of 99.95% measured monthly, with service credits for any breach. The current vendor's 99.5% SLA is not acceptable.",
        "Recovery time objective (RTO) must be no greater than 2 hours and recovery point objective (RPO) no greater than 15 minutes for any individual document or workspace.",
        "The vendor must perform documented DR exercises at least twice per year and share the test results with us.",
        "Backups must be immutable for the duration of the retention period and held on infrastructure logically and physically separated from the primary platform.",
    ]],

    ['h2', 'Audit rights'],
    ['p', "We require a contractual right to audit, including:"],
    ['ul', [
        "The right to commission an independent on-site audit of the vendor's facilities and controls at least annually, at our discretion. The vendor must support up to 10 person-days of auditor time per year without additional charge.",
        "The right to receive copies of any third-party audit reports on the vendor's environment (SOC 2, ISO 27001, ISAE 3402) on request.",
        "Audit findings raised by us must be remediated to an agreed SLA, with closure evidence furnished to our risk team.",
    ]],

    ['h2', 'Insurance and indemnity'],
    ['ul', [
        "The vendor must maintain cyber liability insurance of not less than £25 million per occurrence and in aggregate, naming our group as an additional insured.",
        "The vendor's professional indemnity cover must be at least £10 million.",
        "Certificates of insurance must be provided annually and on any material change to coverage.",
        "Indemnification provisions in the contract must be uncapped for breaches involving personal data, regulatory fines, or wilful misconduct.",
    ]],

    ['h2', 'Pain points with the current platform'],
    ['p', "For context, the most significant Risk concerns we have with the current vendor:"],
    ['ul', [
        "Last year's incident — a misconfigured permission gave unauthorised users read access to a live deal workspace for 11 hours. Detection only occurred when a user noticed the unfamiliar names in the activity feed. The vendor's monitoring failed to alert.",
        "The current MFA implementation accepts SMS as a factor. We have repeatedly requested this be disabled and have been refused.",
        "Pen tests are conducted annually and reports are not made available to us, only an executive summary that is light on technical detail.",
        "The current SLA is 99.5% with no meaningful service credits, and we have observed several outages in the last 12 months that exceeded the cumulative outage budget without any commercial recourse.",
        "Subprocessor changes have happened without notice, in breach of the contract, and we discovered them only via the vendor's public trust portal.",
        "The vendor refuses to permit on-site audits, citing customer confidentiality, despite this being a contractual right.",
    ]],

    ['p', "This document is the Risk function's input to the joint requirements review. We will provide a separate scoring weighting that reflects the relative importance of these controls during supplier evaluation."],
];

// ---------------------------------------------------------------
// Renderer (same shape as the IT and Finance generators)
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

$outFile = __DIR__ . DIRECTORY_SEPARATOR . 'risk-department-secure-data-room.docx';
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
