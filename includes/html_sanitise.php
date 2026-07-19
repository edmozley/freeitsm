<?php
/**
 * Server-side allow-list sanitiser for HTML written by a CUSTOMER.
 *
 * WHY THIS EXISTS
 * ---------------
 * The self-service new-ticket form uses a rich-text editor, so the description
 * arrives as HTML rather than plain text. That HTML is stored and then rendered
 * in the ANALYST's reading pane — so an unprivileged person is now authoring
 * markup that a privileged person will open.
 *
 * The reading pane already cleans message bodies at render time
 * (assets/js/safe-html.js), and that remains the last line of defence. This is
 * the first: cleaning on the way IN means the database never holds the payload
 * at all, so anything that reads `emails.body_content` in future — an export, a
 * digest email, a report, a channel that hasn't been written yet — inherits the
 * protection instead of having to remember it.
 *
 * ALLOW-LIST, NOT DENY-LIST
 * -------------------------
 * Everything is removed unless named here. A deny-list has to anticipate every
 * dangerous construct; an allow-list only has to know what a customer legitimately
 * needs to describe a problem: paragraphs, emphasis, lists, links, tables.
 *
 * Deliberately NOT allowed: <script>, <style>, <iframe>, <object>, <embed>,
 * <form>, <input>, and every event handler. Also <img> — a customer attaches
 * screenshots as files, and permitting remote images would let a ticket body
 * beacon the analyst's IP and read-time to a third-party server.
 */

/** Tags a customer may use. Anything else is unwrapped (its text is kept). */
const USER_HTML_ALLOWED_TAGS = [
    'p', 'br', 'div', 'span',
    'b', 'strong', 'i', 'em', 'u', 's', 'strike',
    'ul', 'ol', 'li',
    'blockquote', 'pre', 'code',
    'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    'a',
    'table', 'thead', 'tbody', 'tr', 'th', 'td',
];

/** Attributes kept, per tag. Everything else is dropped. */
const USER_HTML_ALLOWED_ATTRS = [
    'a' => ['href', 'title'],
];

/**
 * Clean customer-authored HTML down to the allow-list.
 *
 * @param string $html raw editor output
 * @return string safe HTML, or '' when there is nothing left worth keeping
 */
function sanitiseUserHtml(string $html): string {
    $html = trim($html);
    if ($html === '') return '';

    if (!class_exists('DOMDocument')) {
        // No DOM extension: fall back to escaping entirely. Losing formatting is
        // an acceptable price; guessing with regex is not.
        return nl2br(htmlspecialchars($html, ENT_QUOTES, 'UTF-8'));
    }

    $doc = new DOMDocument();
    // Parse as UTF-8 without letting the parser add <html>/<body> scaffolding to
    // the output. libxml warns loudly about HTML5 tags it doesn't know; those
    // warnings are noise, not failures.
    $prev = libxml_use_internal_errors(true);
    $ok = $doc->loadHTML(
        '<?xml encoding="UTF-8"?><div id="__root">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    if (!$ok) {
        return nl2br(htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8'));
    }

    $root = $doc->getElementById('__root');
    if (!$root) return '';

    sanitiseUserHtmlNode($doc, $root);

    // Serialise the root's CHILDREN, so the wrapper itself doesn't survive.
    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }

    // An editor left empty still posts scaffolding like "<p><br></p>".
    if (trim(strip_tags($out)) === '' && stripos($out, '<img') === false) {
        return '';
    }
    return trim($out);
}

/**
 * Walk a node's children, removing or unwrapping anything not allow-listed.
 * Iterates over a SNAPSHOT of the child list because the loop mutates it.
 */
function sanitiseUserHtmlNode(DOMDocument $doc, DOMNode $node): void {
    $children = [];
    foreach ($node->childNodes as $child) $children[] = $child;

    foreach ($children as $child) {
        if ($child->nodeType === XML_TEXT_NODE) continue;

        if ($child->nodeType === XML_COMMENT_NODE) {
            $child->parentNode->removeChild($child);      // comments can hide payloads
            continue;
        }

        if ($child->nodeType !== XML_ELEMENT_NODE) {
            $child->parentNode->removeChild($child);      // CDATA, PIs, doctypes
            continue;
        }

        /** @var DOMElement $child */
        $tag = strtolower($child->nodeName);

        // Tags whose CONTENT is also unsafe are removed whole; anything else
        // unknown is unwrapped so the customer's words survive the cleaning.
        if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'textarea', 'select', 'button'], true)) {
            $child->parentNode->removeChild($child);
            continue;
        }

        if (!in_array($tag, USER_HTML_ALLOWED_TAGS, true)) {
            sanitiseUserHtmlNode($doc, $child);           // clean before promoting
            while ($child->firstChild) {
                $child->parentNode->insertBefore($child->firstChild, $child);
            }
            $child->parentNode->removeChild($child);
            continue;
        }

        $allowed = USER_HTML_ALLOWED_ATTRS[$tag] ?? [];
        $attrs = [];
        foreach ($child->attributes as $attr) $attrs[] = $attr->nodeName;
        foreach ($attrs as $name) {
            if (!in_array(strtolower($name), $allowed, true)) {
                $child->removeAttribute($name);
                continue;
            }
            if (strtolower($name) === 'href') {
                // Whitespace is stripped first: "java\tscript:" is a javascript:
                // URL to a browser but not to a naive comparison.
                $value = strtolower(preg_replace('/\s+/', '', $child->getAttribute('href')));
                if (strpos($value, 'javascript:') === 0 || strpos($value, 'data:') === 0 || strpos($value, 'vbscript:') === 0) {
                    $child->removeAttribute('href');
                }
            }
        }

        // Links open elsewhere; rel stops the new page reaching back via opener.
        if ($tag === 'a' && $child->hasAttribute('href')) {
            $child->setAttribute('target', '_blank');
            $child->setAttribute('rel', 'noopener noreferrer');
        }

        sanitiseUserHtmlNode($doc, $child);
    }
}
