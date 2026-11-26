<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The lock marks OWN permissions, not a dormant Open/Restricted setting.
 * Enabling inheritance preserves that setting, so the old folder renderers
 * kept showing a lock for inherited restrictions and missed open exceptions.
 * Runs the shipped folder and article renderers in Chrome, including every
 * browse layout and the sidebar; no database, login or settings are touched.
 * The inherited-restricted and own-open folder cases fail on the old code.
 * Run: php tests/knowledge-permission-badges.php (PHP and Chrome/Edge only).
 */
require __DIR__ . '/support/ticket-workspace-browser.php';

$root = dirname(__DIR__);
$source = file_get_contents($root . '/assets/js/knowledge.js');
if ($source === false) throw new RuntimeException('Cannot read the Knowledge script.');
$code = '';
foreach (['renderDetailsLayout', 'renderTreeLayout', 'folderChildren', 'renderFolderTree',
          'renderFolderRows', 'renderArticleList', 'escapeHtml', 'jsAttr'] as $name) {
    // Top-level closing braces delimit the existing renderers, not copies of their logic.
    if (!preg_match('/^function ' . preg_quote($name, '/') . '\([^\n]*\).*?^\}/ms', $source, $match)) {
        throw new RuntimeException('Cannot find Knowledge renderer: ' . $name);
    }
    $code .= $match[0] . "\n";
}

$html = '<!doctype html><html><head><meta charset="utf-8"><style>'
    . file_get_contents($root . '/assets/css/knowledge.css')
    . '</style></head><body><div id="kbFolderTree"></div><div id="articleCount"></div>'
    . '<div id="articleList"></div><pre id="result">RUNNING</pre><script>'
    . <<<'JS'
window.t = key => key;
let activeFolder = '', kbLayout = 'cards', kbArticlesLoaded = true, kbCanManagePerms = false;
let kbDetailsSort = {col: 'title', dir: 1};
const kbSelectBox = () => '', kbRowIsShortcut = () => false, formatDate = () => '';
const renderCardTags = () => '', kbFolderLabel = () => '';
const renderBreadcrumb = () => {}, kbRenderSelection = () => {};
const cases = [
    {label: 'inherited restricted', inherits: 1, restricted: 1, own: false},
    {label: 'inherited open', inherits: '1', restricted: 0, own: false},
    {label: 'own restricted', inherits: 0, restricted: 1, own: true},
    {label: 'own open', inherits: '0', restricted: 0, own: true}
];
const kbFolders = cases.map((c, n) => ({
    id: n + 1, parent_id: null, name: 'ZZ ' + c.label, article_count: 0,
    inherits: c.inherits, is_restricted: c.restricted
}));
const articles = cases.map((c, n) => ({
    id: n + 11, folder_id: null, title: 'ZZ ' + c.label, is_published: 1,
    inherit_permissions: c.inherits, is_restricted: c.restricted, tags: []
}));
JS
    . $code . <<<'JS'
const checks = [];
const check = (label, pass, detail) => checks.push({label, pass: !!pass, detail});
const hasLock = row => row && row.textContent.includes('🔒');
try {
    renderFolderTree(articles.length);
    cases.forEach((c, n) => {
        const row = document.querySelector('#kbFolderTree [data-folder="' + (n + 1) + '"]');
        check('sidebar folder: ' + c.label, !!row && hasLock(row) === c.own, row && row.textContent.trim());
        check('sidebar tooltip: ' + c.label,
            !!row && row.title === (c.own ? 'knowledge.exceptions.own_rules' : ''), row && row.title);
    });
    for (const layout of ['cards', 'list', 'tree', 'details']) {
        kbLayout = layout;
        renderArticleList();
        cases.forEach((c, n) => {
            for (const [kind, id] of [['folder', n + 1], ['article', n + 11]]) {
                const row = document.querySelector('#articleList [data-' + kind + '="' + id + '"]');
                check(layout + ' ' + kind + ': ' + c.label,
                    !!row && hasLock(row) === c.own, row && row.textContent.trim());
            }
        });
    }
} catch (error) {
    check('Knowledge renderers execute: ' + error.message, false);
}
document.getElementById('result').textContent = JSON.stringify(checks);
</script></body></html>
JS;

exit(ticketWorkspaceBrowser(['index.html' => $html]));
