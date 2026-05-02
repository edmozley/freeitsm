<?php
/**
 * Generates a sample .docx file for testing the RFP Builder upload + extract flow.
 *
 * Output: samples/it-department-secure-data-room.docx
 *
 * Usage (Windows / WAMP):
 *     "C:\wamp64\bin\php\php8.4.0\php.exe" samples/generate-it-data-room-sample.php
 *
 * Edit the $content array below to produce different sample docs. The
 * script writes a minimal but valid .docx (Content_Types + relationships
 * + document + styles) so Word and our DOCX parser will both accept it.
 */

$content = [
    ['h1', 'IT Department Requirements: New Secure Data Room System'],

    ['h2', 'Background'],
    ['p', "The Legal and Finance teams have requested IT support to procure a new secure data room solution to replace the current platform we use for M&A due diligence and external supplier audits. The existing provider is US-based and the data residency arrangement no longer aligns with our updated information security policy. We have also struggled with audit reporting quality and the platform's MFA implementation, which relies on SMS one-time codes — these no longer meet our internal controls."],
    ['p', "This document captures the IT-side requirements for a replacement system. Some items are mandatory (referred to using \"must\"), others are desirable (referred to using \"should\"). We have also captured a number of current pain points to provide context for the review."],

    ['h2', 'Authentication and access control'],
    ['p', "The new system must support modern authentication standards. Specifically:"],
    ['ul', [
        "Multi-factor authentication must be enforced for all users. SMS-based MFA is not acceptable. App-based authenticators (TOTP) and FIDO2 hardware keys must both be supported.",
        "The platform must support SAML 2.0 single sign-on, integrating with our Microsoft Entra ID tenant. We do not want any local accounts in production.",
        "SCIM 2.0 user provisioning is required so that joiner-mover-leaver workflows can be automated against Entra ID.",
        "The system must allow IP whitelisting at the workspace level, and at the user level for high-risk users.",
        "Idle sessions must time out after no more than 30 minutes; this should be configurable per workspace.",
        "Granular role-based access control is required, with a minimum of: viewer, downloader, contributor, manager, administrator.",
        "Permissions must be assignable at workspace, folder, and individual document level.",
    ]],

    ['h2', 'Data protection'],
    ['p', "We are particularly sensitive on data residency and encryption following recent feedback from our DPO. The chosen system:"],
    ['ul', [
        "Must store all customer data in data centres located within the United Kingdom or European Economic Area. US-based hosting is unacceptable, even for backups or DR copies.",
        "Must encrypt data at rest using AES-256 or stronger.",
        "Must encrypt data in transit using TLS 1.2 or higher; TLS 1.0 and 1.1 must be disabled.",
        "Should support customer-managed encryption keys (CMK) so that key rotation is under our control.",
        "Must provide a documented data deletion process at end of contract, including a written certificate of destruction for any backups.",
        "Must support GDPR data subject rights, including the right to erasure on individual documents.",
        "Should provide a documented disaster recovery plan with RPO of less than 1 hour and RTO of less than 4 hours.",
    ]],

    ['h2', 'Audit, monitoring and reporting'],
    ['p', "A frequent complaint with the existing platform is the lack of audit detail. The new system:"],
    ['ul', [
        "Must record an immutable audit log of every user action: login, document view, download, print, share, permission change, deletion. Audit retention must be at least 7 years.",
        "Audit events must be exportable as CSV or JSON, ideally via a documented API.",
        "Should support streaming of audit events to an external SIEM (e.g. Microsoft Sentinel) via webhook or syslog.",
        "Must produce a per-deal activity report on demand, listing every user, every document accessed, and the timestamp of each access.",
        "Should allow watermarking of viewed documents with username and timestamp, applied dynamically at view time.",
        "Must allow administrators to revoke access retrospectively. (We accept that already-downloaded files are out of scope.)",
    ]],

    ['h2', 'Document and workflow features'],
    ['p', "The user-facing functionality also needs to step up. Specifically:"],
    ['ul', [
        "The platform must support drag-and-drop bulk upload, including folder hierarchies, with single uploads of up to 1 GB.",
        "Document versioning must be supported, with a clear visual indicator of the latest version and access to prior versions for users with appropriate permission.",
        "A Q&A workflow is required for due diligence questions: requesters submit a question against a document or folder, the data owner answers, and the entire thread is captured in audit. The current process via email is unmanageable at scale.",
        "View-only mode must support PDF, Word, Excel, PowerPoint and common image formats without requiring a download. Print and download must be blockable per document.",
        "Full-text search across all documents in a workspace is required.",
        "The system must offer an automatic table of contents and document index for each workspace, regenerated on demand.",
        "Notifications: users must receive email alerts on document upload, Q&A activity, and access expiry, with the ability to opt out per category.",
        "Should provide a mobile experience — either responsive web or dedicated apps for iOS and Android — with the same authentication controls as the web client.",
    ]],

    ['h2', 'Compliance and assurance'],
    ['p', "Vendor evidence we will require during procurement:"],
    ['ul', [
        "Current SOC 2 Type II report (within last 12 months).",
        "ISO/IEC 27001 certification (in scope including the data room product).",
        "Cyber Essentials Plus (UK).",
        "Annual penetration testing report from an independent third party. Latest report must be available under NDA before contract signature.",
        "A completed supplier security questionnaire (we will provide our own template).",
    ]],

    ['h2', 'Operations and integration'],
    ['ul', [
        "The system must expose a documented REST API covering: user management, workspace management, document upload, audit retrieval. The API must use OAuth 2.0 client credentials.",
        "24/7 phone and email support is required during active deal periods. Out of deal periods, business-hours support is acceptable.",
        "Single-tenant or dedicated-instance deployment must be available as an option, even if shared-tenant is the default.",
        "Should integrate with Microsoft Information Protection sensitivity labels so that documents inherit existing classifications when uploaded.",
        "Should integrate with Microsoft Office for in-place editing or check-out / check-in workflows.",
    ]],

    ['h2', 'Pain points with the current platform'],
    ['p', "For context, the most common complaints we hear from users and have observed ourselves:"],
    ['ul', [
        "SMS-based MFA is the only option, and it is no longer compliant with our policy following the 2025 review.",
        "Data is hosted in US data centres. Following our DPO's review, this is no longer acceptable for sensitive M&A material.",
        "Audit reports are limited to a 30-day rolling window and are produced as PDFs only, requiring manual data extraction for any analysis.",
        "Q&A is conducted entirely outside the platform via email, leading to lost threads and inconsistent answers.",
        "The mobile experience is broken — users frequently lose authentication state mid-session.",
        "Bulk upload is capped at 100 MB per file, which is impractical for large signed agreements and presentation decks.",
        "There is no integration with our SSO provider, so a separate set of credentials must be created and managed for every external party.",
        "Watermarking is static (only username, no timestamp) and cannot be enforced for view-only documents.",
    ]],

    ['p', "This concludes the IT department's requirements. Final selection will involve Legal, Finance and Procurement input alongside this technical baseline."],
];

// ---------------------------------------------------------------
// Renderer
// ---------------------------------------------------------------

function escapeXml(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function makePara(string $text, ?string $style = null): string {
    $pPr = $style ? '<w:pPr><w:pStyle w:val="' . $style . '"/></w:pPr>' : '';
    return '<w:p>' . $pPr . '<w:r><w:t xml:space="preserve">' . escapeXml($text) . '</w:t></w:r></w:p>';
}

function makeListItem(string $text): string {
    // Simple visual bullet — avoids needing a separate numbering.xml part.
    return '<w:p><w:pPr><w:pStyle w:val="ListBullet"/></w:pPr>'
         . '<w:r><w:t xml:space="preserve">• ' . escapeXml($text) . '</w:t></w:r></w:p>';
}

$body = '';
foreach ($content as $item) {
    [$type, $value] = $item;
    if ($type === 'h1') {
        $body .= makePara($value, 'Heading1');
    } elseif ($type === 'h2') {
        $body .= makePara($value, 'Heading2');
    } elseif ($type === 'p') {
        $body .= makePara($value);
    } elseif ($type === 'ul') {
        foreach ($value as $li) {
            $body .= makeListItem($li);
        }
    }
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

$outFile = __DIR__ . DIRECTORY_SEPARATOR . 'it-department-secure-data-room.docx';
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
