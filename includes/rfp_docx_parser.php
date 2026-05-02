<?php
/**
 * Extract plain text from a .docx file using ZipArchive + DOMDocument.
 * No external dependencies required.
 *
 * Output is flat text — paragraphs become lines, table rows become
 * pipe-separated lines. Heading hierarchy, lists, images, comments,
 * tracked changes, headers and footers are all dropped. That's enough
 * for sending to AI for requirement extraction.
 *
 * @throws RuntimeException on read or parse failures.
 */
function rfpExtractDocxText(string $filePath): string
{
    if (!file_exists($filePath)) {
        throw new RuntimeException("File not found: {$filePath}");
    }

    $zip = new ZipArchive();
    $result = $zip->open($filePath);
    if ($result !== true) {
        throw new RuntimeException("Could not open .docx file as ZIP archive (error code: {$result})");
    }

    $xml = $zip->getFromName('word/document.xml');
    if ($xml === false) {
        $zip->close();
        throw new RuntimeException('Could not find word/document.xml inside the .docx file');
    }
    $zip->close();

    $dom = new DOMDocument();
    if (!$dom->loadXML($xml, LIBXML_NOERROR | LIBXML_NOWARNING)) {
        throw new RuntimeException('Failed to parse word/document.xml');
    }

    $WORDML = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $text   = '';

    // Walk all paragraphs that are NOT inside a table — tables are handled
    // separately so they come out as pipe-separated rows.
    $paragraphs = $dom->getElementsByTagNameNS($WORDML, 'p');
    foreach ($paragraphs as $p) {
        if (rfpDocxIsInsideTable($p)) {
            continue;
        }
        $line = rfpDocxParagraphText($p, $WORDML);
        if (trim($line) !== '') {
            $text .= $line . "\n";
        }
    }

    // Tables — emit each row as `cell | cell | cell`.
    $tables = $dom->getElementsByTagNameNS($WORDML, 'tbl');
    foreach ($tables as $tbl) {
        $tableLines = [];
        foreach ($tbl->getElementsByTagNameNS($WORDML, 'tr') as $tr) {
            $cells = [];
            foreach ($tr->getElementsByTagNameNS($WORDML, 'tc') as $tc) {
                $cellText = '';
                foreach ($tc->getElementsByTagNameNS($WORDML, 'p') as $cellP) {
                    $cellText .= rfpDocxParagraphText($cellP, $WORDML) . ' ';
                }
                $cells[] = trim($cellText);
            }
            $rowLine = trim(implode(' | ', $cells));
            if ($rowLine !== '') {
                $tableLines[] = $rowLine;
            }
        }
        if (!empty($tableLines)) {
            $text .= "\n" . implode("\n", $tableLines) . "\n";
        }
    }

    return trim($text);
}

/**
 * True if the given element has any <w:tbl> ancestor.
 * Used to skip paragraphs that are inside table cells during the
 * paragraph pass, so they don't get double-counted.
 */
function rfpDocxIsInsideTable(DOMElement $node): bool
{
    for ($p = $node->parentNode; $p !== null; $p = $p->parentNode) {
        if ($p->nodeType === XML_ELEMENT_NODE && $p->localName === 'tbl') {
            return true;
        }
    }
    return false;
}

/**
 * Concatenate all text runs in a single <w:p>, preserving tab characters
 * where <w:tab/> elements appear.
 */
function rfpDocxParagraphText(DOMElement $p, string $ns): string
{
    $hasTab = $p->getElementsByTagNameNS($ns, 'tab')->length > 0;

    if (!$hasTab) {
        $out = '';
        foreach ($p->getElementsByTagNameNS($ns, 't') as $t) {
            $out .= $t->textContent;
        }
        return $out;
    }

    // Walk children in document order so tabs land in the right places.
    $out = '';
    foreach ($p->childNodes as $child) {
        if ($child->localName === 'r') {
            foreach ($child->childNodes as $runChild) {
                if ($runChild->localName === 't') {
                    $out .= $runChild->textContent;
                } elseif ($runChild->localName === 'tab') {
                    $out .= "\t";
                }
            }
        }
    }
    return $out;
}
