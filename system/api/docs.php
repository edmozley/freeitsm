<?php
/**
 * System - API documentation + interactive tester.
 *
 * Documents every REST API v1 endpoint (data-driven from the SPEC array
 * below — keep it in step with api/v1/index.php's route table) and lets an
 * admin fire real requests against this install and inspect the response.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

require_once '../../includes/functions.php';

$current_page = 'api';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$apiBaseUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL . 'api/v1';

// Endpoint catalogue: SINGLE SOURCE OF TRUTH, shared with the OpenAPI generator
// (api/v1/lib/openapi.php). To add or change an endpoint, edit api/v1/spec.json only.
$__spec = json_decode(file_get_contents(__DIR__ . '/../../api/v1/spec.json'), true);
$__specJson   = $__spec ? json_encode($__spec['spec'])   : '[]';
$__extrasJson = $__spec ? json_encode($__spec['extras']) : '{}';
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - API documentation</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        /* ---- Three-pane shell: nav | endpoint doc | live code -------------- */
        .docs-shell { display: flex; height: calc(100vh - 48px); overflow: hidden; background: #f4f6f8; }
        .docs-nav  { flex: 0 0 280px; background: #fff; border-right: 1px solid #e5e8eb; display: flex; flex-direction: column; min-width: 0; }
        .docs-main { flex: 1 1 auto; overflow-y: auto; padding: 26px 32px 60px; min-width: 0; }
        .docs-code { flex: 0 0 480px; background: #263238; color: #eceff1; overflow-y: auto; padding: 18px 18px 40px; min-width: 0; }
        @media (max-width: 1280px) { .docs-code { flex-basis: 400px; } }
        @media (max-width: 1024px) {
            .docs-shell { flex-direction: column; height: auto; overflow: visible; }
            .docs-nav { flex: none; max-height: 300px; border-right: none; border-bottom: 1px solid #e5e8eb; }
            .docs-code { flex: none; }
        }

        /* ---- Left: search + navigation tree -------------------------------- */
        .nav-search { padding: 12px; border-bottom: 1px solid #eef1f3; }
        .nav-search input { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
        .nav-search input:focus { outline: none; border-color: #546e7a; }
        .nav-tree { flex: 1; overflow-y: auto; padding: 8px 0 30px; }
        .nav-home { display: block; padding: 8px 14px; font-size: 13px; font-weight: 600; color: #37474f; text-decoration: none; }
        .nav-home:hover, .nav-home.active { background: #eef3f6; color: #1565c0; }
        .nav-section { margin-top: 2px; }
        .nav-section-head { display: flex; align-items: center; gap: 6px; padding: 7px 14px; font-size: 12px; font-weight: 700; color: #546e7a; text-transform: uppercase; letter-spacing: 0.4px; cursor: pointer; user-select: none; }
        .nav-section-head:hover { background: #f7f9fa; }
        .nav-caret { transition: transform 0.12s; font-size: 10px; color: #90a4ae; }
        .nav-section.open .nav-caret { transform: rotate(90deg); }
        .nav-items { display: none; }
        .nav-section.open .nav-items { display: block; }
        .nav-ep { display: flex; align-items: center; gap: 8px; padding: 5px 14px 5px 24px; font-size: 12.5px; color: #444; text-decoration: none; cursor: pointer; }
        .nav-ep:hover { background: #f5f8fa; }
        .nav-ep.active { background: #e8f0fe; }
        .nav-ep.active .nav-ep-path { color: #1565c0; font-weight: 600; }
        .nav-ep-path { font-family: Consolas, Monaco, monospace; font-size: 11.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .m-badge { flex: none; width: 44px; text-align: center; padding: 2px 0; border-radius: 4px; font-size: 9.5px; font-weight: 700; color: #fff; font-family: Consolas, Monaco, monospace; }
        .m-badge.GET { background: #2e7d32; } .m-badge.POST { background: #1565c0; }
        .m-badge.PATCH { background: #e65100; } .m-badge.DELETE { background: #c62828; }
        .nav-empty { padding: 16px 14px; font-size: 12.5px; color: #999; }

        /* ---- Middle: the endpoint document ---------------------------------- */
        .ep-title { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin: 0 0 4px; }
        .ep-title .method { flex: none; padding: 5px 12px; border-radius: 5px; font-size: 12px; font-weight: 700; color: #fff; font-family: Consolas, Monaco, monospace; }
        .method.GET { background: #2e7d32; } .method.POST { background: #1565c0; }
        .method.PATCH { background: #e65100; } .method.DELETE { background: #c62828; }
        .ep-title code { font-size: 17px; color: #263238; font-family: Consolas, Monaco, monospace; font-weight: 600; }
        .ep-meta { display: flex; align-items: center; gap: 10px; margin: 8px 0 16px; flex-wrap: wrap; }
        .ep-perm { font-size: 11.5px; background: #e3f2fd; color: #1565c0; border-radius: 10px; padding: 3px 10px; }
        .wiki-link { font-size: 12px; color: #546e7a; text-decoration: none; }
        .wiki-link:hover { text-decoration: underline; }
        .ep-desc { font-size: 13.5px; color: #444; line-height: 1.65; margin: 0 0 20px; max-width: 760px; }
        .doc-h { font-size: 13px; font-weight: 700; color: #546e7a; text-transform: uppercase; letter-spacing: 0.5px; margin: 24px 0 10px; }

        .example-chips { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 8px; }
        .chip { padding: 6px 13px; border: 1px solid #cfd8dc; border-radius: 16px; background: #fff; font-size: 12.5px; color: #37474f; cursor: pointer; }
        .chip:hover { border-color: #546e7a; }
        .chip.active { background: #546e7a; color: #fff; border-color: #546e7a; }
        .example-note { font-size: 12.5px; color: #777; line-height: 1.5; margin: 4px 0 0; min-height: 18px; max-width: 760px; }

        table.params { width: 100%; max-width: 860px; border-collapse: collapse; font-size: 12.5px; background: #fff; border: 1px solid #e8ecef; border-radius: 8px; overflow: hidden; }
        table.params th { text-align: left; color: #78909c; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px; padding: 8px 12px; border-bottom: 1px solid #e8ecef; background: #fafbfc; }
        table.params td { padding: 8px 12px; border-bottom: 1px solid #f2f4f6; color: #444; vertical-align: middle; }
        table.params tr:last-child td { border-bottom: none; }
        table.params code { background: #f5f7fa; padding: 1px 6px; border-radius: 3px; font-size: 12px; }
        .param-req { color: #c62828; font-weight: 600; font-size: 10.5px; margin-left: 4px; }
        .param-in { font-size: 11px; color: #90a4ae; }
        .p-input { width: 100%; box-sizing: border-box; padding: 6px 8px; border: 1px solid #dde3e7; border-radius: 4px; font-size: 12px; font-family: Consolas, Monaco, monospace; }
        .p-input:focus { outline: none; border-color: #546e7a; }
        td.p-cell { width: 220px; }
        .body-editor { width: 100%; max-width: 860px; min-height: 140px; box-sizing: border-box; padding: 10px; border: 1px solid #dde3e7; border-radius: 6px; font-size: 12.5px; font-family: Consolas, Monaco, monospace; resize: vertical; background: #fff; }
        .body-editor:focus { outline: none; border-color: #546e7a; }
        .body-invalid { border-color: #c62828 !important; }

        table.errors { width: 100%; max-width: 860px; border-collapse: collapse; font-size: 12.5px; background: #fff; border: 1px solid #e8ecef; border-radius: 8px; overflow: hidden; }
        table.errors th { text-align: left; color: #78909c; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px; padding: 8px 12px; border-bottom: 1px solid #e8ecef; background: #fafbfc; }
        table.errors td { padding: 8px 12px; border-bottom: 1px solid #f2f4f6; color: #444; vertical-align: top; }
        table.errors tr:last-child td { border-bottom: none; }
        .err-code { font-family: Consolas, Monaco, monospace; font-weight: 700; white-space: nowrap; }
        .err-4 { color: #e65100; } .err-5 { color: #c62828; } .err-2 { color: #2e7d32; }
        .err-slug { font-family: Consolas, Monaco, monospace; font-size: 11.5px; color: #78909c; white-space: nowrap; }

        /* ---- Overview (landing) --------------------------------------------- */
        .ov-card { background: #fff; border: 1px solid #e8ecef; border-radius: 8px; padding: 20px 24px; margin-bottom: 18px; max-width: 860px; }
        .ov-card h3 { margin: 0 0 8px; font-size: 15px; color: #263238; }
        .ov-card p, .ov-card li { font-size: 13px; color: #555; line-height: 1.65; }
        .ov-card code { background: #f5f7fa; padding: 1px 6px; border-radius: 3px; font-size: 12px; }
        .ov-card a { color: #1565c0; }

        /* ---- Right: live code pane ------------------------------------------ */
        .code-key { display: flex; gap: 8px; align-items: center; margin-bottom: 14px; }
        .code-key input { flex: 1; min-width: 0; padding: 8px 10px; border: 1px solid #455a64; border-radius: 5px; background: #1e272c; color: #eceff1; font-size: 12px; font-family: Consolas, Monaco, monospace; }
        .code-key input::placeholder { color: #78909c; }
        .code-key input:focus { outline: none; border-color: #90a4ae; }
        .key-dot { flex: none; width: 9px; height: 9px; border-radius: 50%; background: #78909c; }
        .key-dot.ok { background: #66bb6a; } .key-dot.bad { background: #ef5350; }
        .key-note { font-size: 11px; color: #90a4ae; margin: -8px 0 14px; }
        .key-note a { color: #90caf9; }

        .lang-tabs { display: flex; gap: 2px; flex-wrap: wrap; margin-bottom: 0; }
        .lang-tab { padding: 6px 12px; font-size: 11.5px; color: #b0bec5; background: transparent; border: none; border-radius: 6px 6px 0 0; cursor: pointer; font-family: inherit; }
        .lang-tab:hover { color: #eceff1; }
        .lang-tab.active { background: #1e272c; color: #fff; font-weight: 600; }
        .code-block { position: relative; background: #1e272c; border-radius: 0 8px 8px 8px; margin-bottom: 16px; }
        .code-block pre { margin: 0; padding: 14px; font-size: 11.5px; line-height: 1.55; overflow-x: auto; color: #e3f2fd; font-family: Consolas, Monaco, monospace; white-space: pre; max-height: 440px; overflow-y: auto; }
        .code-head { display: flex; align-items: center; justify-content: space-between; padding: 8px 12px 0; }
        .code-label { font-size: 10.5px; font-weight: 700; color: #78909c; text-transform: uppercase; letter-spacing: 0.6px; }
        .copy-btn { background: #37474f; color: #cfd8dc; border: none; border-radius: 4px; padding: 4px 10px; font-size: 11px; cursor: pointer; }
        .copy-btn:hover { background: #455a64; color: #fff; }
        .resp-block pre { max-height: 520px; }
        .resp-bar { display: flex; align-items: center; gap: 10px; padding: 8px 12px 0; }
        .resp-status { font-size: 12px; font-weight: 700; }
        .resp-status.ok { color: #81c784; } .resp-status.err { color: #ef9a9a; }
        .resp-note { font-size: 11px; color: #78909c; }
        .send-btn { padding: 8px 24px; background: #1565c0; color: #fff; border: none; border-radius: 5px; font-size: 12.5px; font-weight: 600; cursor: pointer; }
        .send-btn:hover { background: #0d47a1; }
        .send-btn:disabled { opacity: 0.55; cursor: wait; }
        .send-row { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; }
        .send-hint { font-size: 11px; color: #90a4ae; }
        .resp-placeholder { padding: 14px; font-size: 12px; color: #78909c; line-height: 1.6; }
    </style>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="docs-shell">
        <nav class="docs-nav">
            <div class="nav-search">
                <input type="text" id="navSearch" placeholder="Search endpoints…  ( / )" autocomplete="off">
            </div>
            <div class="nav-tree" id="navTree"></div>
        </nav>
        <main class="docs-main" id="docsMain"></main>
        <aside class="docs-code" id="docsCode"></aside>
    </div>

    <span id="baseUrl" style="display:none"><?php echo htmlspecialchars($apiBaseUrl); ?></span>
    <script>
    const BASE = document.getElementById('baseUrl').textContent;


    const SPEC = <?php echo $__specJson; ?>;
    const EXTRAS = <?php echo $__extrasJson; ?>;

    // Endpoint catalogue (SPEC) + examples/errors (EXTRAS) come from api/v1/spec.json
    // — the single source shared with the OpenAPI generator. Do not hand-edit here.
    const esc = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };

    // Section -> GitHub wiki usage guide.
    const WIKI_BASE = 'https://github.com/edmozley/freeitsm/wiki/';
    const WIKI = {
        'Getting started': 'REST-API', 'Tickets': 'REST-API-Tickets',
        'Ticket notes, conversation & history': 'REST-API-Tickets', 'Time tracking': 'REST-API-Tickets',
        'Assets': 'REST-API-Assets', 'Asset assignments, history & inventory': 'REST-API-Assets',
        'Problems': 'REST-API-Problems', 'Problem notes, history & links': 'REST-API-Problems',
        'Changes': 'REST-API-Changes', 'Change comments, history & CAB': 'REST-API-Changes',
        'Knowledge base': 'REST-API-Knowledge', 'Tasks': 'REST-API-Tasks', 'CMDB': 'REST-API-CMDB',
        'Contracts': 'REST-API-Contracts', 'Suppliers & contacts': 'REST-API-Contracts',
        'Calendar': 'REST-API-Calendar', 'Software': 'REST-API-Software',
        'Service status': 'REST-API-Service-Status', 'Morning checks': 'REST-API-Morning-Checks',
        'Forms': 'REST-API-Forms', 'Workflows': 'REST-API-Workflow',
        'Network Mapper': 'REST-API-Network-Mapper', 'Requesters': 'REST-API-Tickets',
        'Reference data': 'REST-API',
    };

    const LANGS = [
        ['curl', 'cURL'], ['powershell', 'PowerShell'], ['php', 'PHP'], ['python', 'Python'],
        ['csharp', 'C#'], ['ruby', 'Ruby'], ['js', 'JavaScript'],
    ];

    // --- Flatten SPEC into a slug-addressable endpoint list ------------------
    const slugOf = ep => (ep.m + '-' + ep.p).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    const ALL = [];
    SPEC.forEach(sec => sec.items.forEach(ep => {
        ep.section = sec.section;
        ep.slug = slugOf(ep);
        ep.extras = EXTRAS[ep.m + ' ' + ep.p] || {};
        ALL.push(ep);
    }));
    const bySlug = Object.fromEntries(ALL.map(ep => [ep.slug, ep]));

    // Query params with " / " combined names are split into real inputs.
    function queryParamsOf(ep) {
        const out = [];
        (ep.params || []).filter(p => p.in === 'query').forEach(p => {
            p.name.split('/').map(s => s.trim()).filter(Boolean).forEach(name => out.push({name, desc: p.desc, req: p.req}));
        });
        return out;
    }
    const pathParamsOf = ep => (ep.params || []).filter(p => p.in === 'path');
    const hasBody = ep => ['POST', 'PATCH'].includes(ep.m);

    // --- Derived + endpoint-specific error documentation ----------------------
    const GLOSSARY = [
        [400, 'invalid_json', 'The request body is not parseable JSON.'],
        [400, 'invalid_parameter', 'A query parameter has an invalid value (e.g. a malformed date).'],
        [401, 'unauthenticated', 'No API key sent, or the key is unknown. Send Authorization: Bearer <key>.'],
        [403, 'forbidden', 'The key is valid but lacks the permission this endpoint requires.'],
        [403, 'key_disabled', 'The key (or the analyst it acts as) has been disabled.'],
        [403, 'key_expired', 'The key has passed its expiry date.'],
        [404, 'not_found', 'No record with that id (or it is outside the key\'s company scope).'],
        [405, 'method_not_allowed', 'The path exists but not with this HTTP method (an Allow header lists valid ones).'],
        [409, 'conflict', 'The request is valid but collides with current state (duplicates, frozen versions, …).'],
        [422, 'missing_field', 'A required field was not supplied.'],
        [422, 'invalid_field', 'A supplied field has a value the endpoint cannot accept.'],
        [429, 'rate_limited', 'Too many requests this minute for this key. Check the X-RateLimit-* headers.'],
        [500, 'server_error', 'Something unexpected failed server-side; the detail is in the server log.'],
    ];

    function errorsOf(ep) {
        const specific = (ep.extras.errors || []).slice();
        const have = code => specific.some(e => e.code === code);
        const derived = [];
        derived.push({code: 401, slug: 'unauthenticated', when: 'The API key is missing or invalid.'});
        if (ep.perm !== 'none') {
            derived.push({code: 403, slug: 'forbidden', when: 'The key does not have the ' + ep.perm + ' permission.'});
        }
        if (ep.p.includes('{') && !have(404)) {
            derived.push({code: 404, slug: 'not_found', when: 'No record with that id.'});
        }
        if (hasBody(ep)) {
            derived.push({code: 400, slug: 'invalid_json', when: 'The request body is not valid JSON.'});
            if (!have(422)) {
                derived.push({code: 422, slug: 'missing_field / invalid_field', when: 'A required field is missing, or a field value is not acceptable.'});
            }
        }
        derived.push({code: 429, slug: 'rate_limited', when: 'The key exceeded its per-minute rate limit.'});
        return specific.concat(derived).sort((a, b) => a.code - b.code);
    }

    // --- State ----------------------------------------------------------------
    let current = null;          // the selected endpoint (null = overview)
    let lang = localStorage.getItem('freeitsm_api_docs_lang') || 'curl';
    let apiKey = localStorage.getItem('freeitsm_api_test_key') || '';
    const respCache = {};        // url -> {status, ok, text, remaining}
    let fireTimer = null;
    let fireSeq = 0;             // guards against out-of-order async responses

    // --- Navigation tree -------------------------------------------------------
    const navTree = document.getElementById('navTree');
    function renderNav(filter) {
        const f = (filter || '').toLowerCase().trim();
        let html = '<a class="nav-home' + (current === null ? ' active' : '') + '" href="#overview">📖 Overview & errors</a>';
        let any = false;
        SPEC.forEach((sec, si) => {
            const items = sec.items.filter(ep => !f
                || ep.p.toLowerCase().includes(f) || ep.s.toLowerCase().includes(f)
                || (ep.d || '').toLowerCase().includes(f) || ep.m.toLowerCase() === f);
            if (!items.length) return;
            any = true;
            const open = f || (current && current.section === sec.section);
            html += '<div class="nav-section' + (open ? ' open' : '') + '" data-si="' + si + '">'
                + '<div class="nav-section-head" onclick="this.parentElement.classList.toggle(\'open\')">'
                + '<span class="nav-caret">▶</span>' + esc(sec.section) + '</div><div class="nav-items">'
                + items.map(ep => '<a class="nav-ep' + (current && current.slug === ep.slug ? ' active' : '') + '" href="#' + ep.slug + '">'
                    + '<span class="m-badge ' + ep.m + '">' + ep.m + '</span>'
                    + '<span class="nav-ep-path" title="' + esc(ep.s) + '">' + esc(ep.p) + '</span></a>').join('')
                + '</div></div>';
        });
        if (!any) html += '<div class="nav-empty">No endpoints match "' + esc(filter) + '".</div>';
        navTree.innerHTML = html;
    }
    const searchBox = document.getElementById('navSearch');
    searchBox.addEventListener('input', () => renderNav(searchBox.value));
    document.addEventListener('keydown', e => {
        if (e.key === '/' && document.activeElement.tagName !== 'INPUT' && document.activeElement.tagName !== 'TEXTAREA') {
            e.preventDefault();
            searchBox.focus();
        }
    });

    // --- Router -----------------------------------------------------------------
    function route() {
        const slug = location.hash.replace(/^#/, '');
        if (slug && bySlug[slug]) {
            selectEndpoint(bySlug[slug]);
        } else {
            current = null;
            renderNav(searchBox.value);
            renderOverview();
            renderCodePane();
        }
    }
    window.addEventListener('hashchange', route);

    // --- Overview (landing page) --------------------------------------------------
    function renderOverview() {
        const wikiLinks = [...new Set(Object.values(WIKI))].map(w =>
            '<a href="' + WIKI_BASE + w + '" target="_blank" rel="noopener">' + esc(w.replace(/-/g, ' ')) + '</a>').join(' · ');
        document.getElementById('docsMain').innerHTML = `
            <div class="ep-title"><code style="font-size:20px;">FreeITSM REST API v1</code></div>
            <p class="ep-desc">Pick an endpoint on the left (or press <code>/</code> to search). The middle pane documents it —
               fill in the parameter fields or click an example, and the right pane rewrites the code sample in your
               language and shows the live response from this install.</p>
            <div class="ov-card"><h3>Authentication</h3>
                <p>Every request needs a key from the <a href="index.php">API keys page</a>, sent as
                <code>Authorization: Bearer fitsm_…</code>. Keys are granular — they start with no permissions, act as a
                named analyst (so audit trails have a real author), and can be scoped to specific companies.
                <code>GET /ping</code> tells you what a key can do.</p></div>
            <div class="ov-card"><h3>Responses & pagination</h3>
                <p>Success is <code>{"data": …}</code>; paginated lists add <code>{"meta": {"page", "per_page", "total", "total_pages"}}</code>
                and take <code>?page=&amp;per_page=</code> (default 25, max 100). Errors are
                <code>{"error": {"code", "message"}}</code> with a real HTTP status. Timestamps are UTC ISO 8601 —
                except the calendar module, which is deliberately naive server-local (documented there).</p></div>
            <div class="ov-card"><h3>Rate limits</h3>
                <p>60 requests/minute per key by default (overridable per key). Every response carries
                <code>X-RateLimit-Limit</code>, <code>X-RateLimit-Remaining</code> and <code>X-RateLimit-Reset</code>;
                going over returns <code>429</code>.</p></div>
            <div class="ov-card"><h3>Error codes</h3>
                <p>Every endpoint's page lists the errors it can return and what triggers them. The full vocabulary:</p>
                <table class="errors"><thead><tr><th>HTTP</th><th>Code</th><th>Meaning</th></tr></thead><tbody>
                ${GLOSSARY.map(([c, s, m]) => '<tr><td class="err-code err-' + String(c)[0] + '">' + c + '</td><td class="err-slug">' + s + '</td><td>' + esc(m) + '</td></tr>').join('')}
                </tbody></table></div>
            <div class="ov-card"><h3>Guides on the wiki</h3>
                <p>Each module has a full usage guide with worked scenarios: ${wikiLinks}.</p></div>`;
    }

    // --- Endpoint page -------------------------------------------------------------
    function selectEndpoint(ep) {
        current = ep;
        renderNav(searchBox.value);
        const wiki = WIKI[ep.section];
        const examples = ep.extras.examples || [];
        const qp = queryParamsOf(ep);
        const pp = pathParamsOf(ep);
        const bodyParams = (ep.params || []).filter(p => p.in === 'body');

        const paramRow = (p, role) => `
            <tr><td><code>${esc(p.name)}</code>${p.req ? '<span class="param-req">required</span>' : ''}
                <div class="param-in">${role}</div></td>
            <td>${esc(p.desc)}</td>
            <td class="p-cell"><input class="p-input" data-role="${role}" data-name="${esc(p.name)}" placeholder="${role === 'path' ? 'e.g. 1' : '—'}"></td></tr>`;

        document.getElementById('docsMain').innerHTML = `
            <div class="ep-title"><span class="method ${ep.m}">${ep.m}</span><code>${esc(ep.p)}</code></div>
            <div class="ep-meta">
                <span class="ep-perm">🔑 ${esc(ep.perm)}</span>
                ${wiki ? '<a class="wiki-link" href="' + WIKI_BASE + wiki + '" target="_blank" rel="noopener">📖 ' + esc(ep.section) + ' guide on the wiki ↗</a>' : ''}
            </div>
            <p class="ep-desc"><strong>${esc(ep.s)}.</strong> ${esc(ep.d || '')}</p>
            ${examples.length ? `<div class="doc-h">Examples — click to load</div>
                <div class="example-chips">${examples.map((ex, i) => '<button class="chip" data-ex="' + i + '">' + esc(ex.title) + '</button>').join('')}</div>
                <p class="example-note" id="exampleNote">Each example fills the fields below — watch the code and response update.</p>` : ''}
            ${(pp.length || qp.length) ? `<div class="doc-h">Parameters</div>
                <table class="params"><thead><tr><th>Parameter</th><th>Description</th><th>Value</th></tr></thead><tbody>
                ${pp.map(p => paramRow(p, 'path')).join('')}${qp.map(p => paramRow(p, 'query')).join('')}
                </tbody></table>` : ''}
            ${hasBody(ep) ? `<div class="doc-h">Request body</div>
                ${bodyParams.length ? `<table class="params" style="margin-bottom:10px;"><thead><tr><th>Field</th><th>Description</th></tr></thead><tbody>
                    ${bodyParams.map(p => '<tr><td><code>' + esc(p.name) + '</code>' + (p.req ? '<span class="param-req">required</span>' : '') + '</td><td>' + esc(p.desc) + '</td></tr>').join('')}
                </tbody></table>` : ''}
                <textarea class="body-editor" id="bodyEditor" spellcheck="false">${ep.body ? esc(JSON.stringify(ep.body, null, 2)) : '{\n}'}</textarea>` : ''}
            <div class="doc-h">Errors</div>
            <table class="errors"><thead><tr><th>HTTP</th><th>Code</th><th>When</th></tr></thead><tbody>
                ${errorsOf(ep).map(e => '<tr><td class="err-code err-' + String(e.code)[0] + '">' + e.code + '</td><td class="err-slug">' + esc(e.slug) + '</td><td>' + esc(e.when) + '</td></tr>').join('')}
            </tbody></table>`;

        document.querySelectorAll('#docsMain .p-input').forEach(inp => inp.addEventListener('input', () => onChange()));
        const be = document.getElementById('bodyEditor');
        if (be) be.addEventListener('input', () => onChange());
        document.querySelectorAll('.chip').forEach(chip => chip.addEventListener('click', () => applyExample(ep, +chip.dataset.ex, chip)));

        renderCodePane();
        onChange(true);
    }

    function applyExample(ep, i, chip) {
        const ex = (ep.extras.examples || [])[i];
        if (!ex) return;
        document.querySelectorAll('.chip').forEach(c => c.classList.remove('active'));
        chip.classList.add('active');
        const note = document.getElementById('exampleNote');
        if (note) note.textContent = ex.note || '';
        document.querySelectorAll('#docsMain .p-input').forEach(inp => {
            const src = inp.dataset.role === 'path' ? (ex.path || {}) : (ex.query || {});
            inp.value = src[inp.dataset.name] !== undefined ? String(src[inp.dataset.name]) : '';
        });
        const be = document.getElementById('bodyEditor');
        if (be && ex.body !== undefined) be.value = JSON.stringify(ex.body, null, 2);
        onChange(true);
    }

    // --- Request assembly ---------------------------------------------------------
    function collect() {
        const path = {}, query = {};
        document.querySelectorAll('#docsMain .p-input').forEach(inp => {
            const v = inp.value.trim();
            if (v === '') return;
            (inp.dataset.role === 'path' ? path : query)[inp.dataset.name] = v;
        });
        const be = document.getElementById('bodyEditor');
        return {path, query, body: be ? be.value.trim() : null};
    }

    function buildUrl(ep, vals) {
        let p = ep.p.replace(/\{([^}]+)\}/g, (m0, name) =>
            vals.path[name] !== undefined ? encodeURIComponent(vals.path[name]) : '{' + name + '}');
        const qs = Object.entries(vals.query).map(([k, v]) => encodeURIComponent(k) + '=' + encodeURIComponent(v)).join('&');
        return BASE + p + (qs ? '?' + qs : '');
    }

    function pathReady(ep, vals) {
        return pathParamsOf(ep).every(p => vals.path[p.name] !== undefined);
    }

    // --- Code generators (one template per language, data-driven) -------------------
    const indent = (text, pad) => text.split('\n').map(l => pad + l).join('\n');

    function genCode(ep, url, body) {
        const m = ep.m;
        switch (lang) {
        case 'curl': {
            let c = 'curl -X ' + m + ' "' + url + '" \\\n  -H "Authorization: Bearer YOUR_API_KEY"';
            if (body) c += ' \\\n  -H "Content-Type: application/json" \\\n  -d \'' + body.replace(/'/g, "'\\''") + '\'';
            return c;
        }
        case 'powershell': {
            let c = '$headers = @{ Authorization = "Bearer YOUR_API_KEY" }\n';
            if (body) c += '$body = @\'\n' + body + '\n\'@\n';
            c += '\n$response = Invoke-RestMethod -Uri "' + url + '" -Method ' + (m.charAt(0) + m.slice(1).toLowerCase())
               + ' -Headers $headers' + (body ? ' -ContentType "application/json" -Body $body' : '') + '\n$response.data';
            return c;
        }
        case 'php': {
            // '<' + '?php' split so the PHP parser serving this page never sees an open tag.
            let c = '<' + '?php\n$ch = curl_init(\'' + url + '\');\ncurl_setopt_array($ch, [\n'
                + '    CURLOPT_RETURNTRANSFER => true,\n'
                + '    CURLOPT_CUSTOMREQUEST  => \'' + m + '\',\n'
                + '    CURLOPT_HTTPHEADER     => [\n        \'Authorization: Bearer YOUR_API_KEY\',\n'
                + (body ? '        \'Content-Type: application/json\',\n' : '') + '    ],\n';
            if (body) c += '    CURLOPT_POSTFIELDS     => <<<\'JSON\'\n' + body + '\nJSON,\n';
            c += ']);\n$response = json_decode(curl_exec($ch), true);\ncurl_close($ch);\n\nprint_r($response[\'data\']);';
            return c;
        }
        case 'python': {
            let c = 'import requests\n\nresponse = requests.' + m.toLowerCase() + '(\n    "' + url + '",\n'
                + '    headers={"Authorization": "Bearer YOUR_API_KEY"'
                + (body ? ', "Content-Type": "application/json"' : '') + '},\n';
            if (body) c += '    data="""' + body + '""",\n';
            c += ')\nprint(response.json()["data"])';
            return c;
        }
        case 'csharp': {
            let c = 'using System.Net.Http;\nusing System.Text;\n\n'
                + 'using var client = new HttpClient();\n'
                + 'client.DefaultRequestHeaders.Add("Authorization", "Bearer YOUR_API_KEY");\n\n';
            if (body) {
                c += 'var body = new StringContent(@"\n' + body.replace(/"/g, '""') + '",\n    Encoding.UTF8, "application/json");\n\n';
            }
            const call = {GET: 'GetAsync("' + url + '")', DELETE: 'DeleteAsync("' + url + '")',
                          POST: 'PostAsync("' + url + '", body)', PATCH: 'PatchAsync("' + url + '", body)'}[m];
            c += 'var response = await client.' + call + ';\n'
               + 'Console.WriteLine(await response.Content.ReadAsStringAsync());';
            return c;
        }
        case 'ruby': {
            const klass = m.charAt(0) + m.slice(1).toLowerCase();
            let c = 'require "net/http"\nrequire "json"\n\nuri = URI("' + url + '")\n'
                + 'req = Net::HTTP::' + klass + '.new(uri)\nreq["Authorization"] = "Bearer YOUR_API_KEY"\n';
            if (body) c += 'req["Content-Type"] = "application/json"\nreq.body = <<~JSON\n' + indent(body, '  ') + '\nJSON\n';
            c += '\nres = Net::HTTP.start(uri.hostname, uri.port, use_ssl: uri.scheme == "https") { |http| http.request(req) }\n'
               + 'puts JSON.parse(res.body)["data"]';
            return c;
        }
        case 'js': {
            let c = 'const response = await fetch("' + url + '", {\n  method: "' + m + '",\n'
                + '  headers: {\n    "Authorization": "Bearer YOUR_API_KEY",\n'
                + (body ? '    "Content-Type": "application/json",\n' : '') + '  },\n';
            if (body) c += '  body: JSON.stringify(' + indent(body, '  ').trim() + '),\n';
            c += '});\nconst { data } = await response.json();\nconsole.log(data);';
            return c;
        }
        }
        return '';
    }

    // --- Right pane -----------------------------------------------------------------
    function renderCodePane() {
        const pane = document.getElementById('docsCode');
        const keyBlock = `
            <div class="code-key">
                <span class="key-dot" id="keyDot"></span>
                <input type="password" id="apiKeyInput" placeholder="Paste an API key (fitsm_…) to light up live responses" autocomplete="off" value="${esc(apiKey)}">
            </div>
            <div class="key-note">Kept in this browser only. Create keys on the <a href="index.php">API keys page</a>.
                GETs run automatically as you browse; writes only run when you press Send.</div>`;
        if (!current) {
            pane.innerHTML = keyBlock + '<div class="code-block"><div class="resp-placeholder">Pick an endpoint to see request code in '
                + LANGS.map(l => l[1]).join(', ') + ' — and the live response from this install.</div></div>';
        } else {
            pane.innerHTML = keyBlock + `
                <div class="lang-tabs">${LANGS.map(([id, label]) =>
                    '<button class="lang-tab' + (lang === id ? ' active' : '') + '" data-lang="' + id + '">' + label + '</button>').join('')}</div>
                <div class="code-block">
                    <div class="code-head"><span class="code-label">Request</span><button class="copy-btn" id="copyReq">Copy</button></div>
                    <pre id="reqCode"></pre>
                </div>
                <div class="send-row">
                    <button class="send-btn" id="sendBtn">Send</button>
                    <span class="send-hint" id="sendHint"></span>
                </div>
                <div class="code-block resp-block">
                    <div class="resp-bar"><span class="code-label">Response</span>
                        <span class="resp-status" id="respStatus"></span><span class="resp-note" id="respNote"></span>
                        <button class="copy-btn" id="copyResp" style="margin-left:auto;">Copy</button></div>
                    <pre id="respCode"></pre>
                </div>`;
            document.querySelectorAll('.lang-tab').forEach(t => t.addEventListener('click', () => {
                lang = t.dataset.lang;
                localStorage.setItem('freeitsm_api_docs_lang', lang);
                document.querySelectorAll('.lang-tab').forEach(x => x.classList.toggle('active', x.dataset.lang === lang));
                refreshCode();
            }));
            document.getElementById('sendBtn').addEventListener('click', () => fire(true));
            document.getElementById('copyReq').addEventListener('click', () => copyText(document.getElementById('reqCode').textContent, 'copyReq'));
            document.getElementById('copyResp').addEventListener('click', () => copyText(document.getElementById('respCode').textContent, 'copyResp'));
        }
        const ki = document.getElementById('apiKeyInput');
        ki.addEventListener('change', () => {
            apiKey = ki.value.trim();
            localStorage.setItem('freeitsm_api_test_key', apiKey);
            checkKey();
            if (current) onChange(true);
        });
        checkKey();
    }

    function copyText(text, btnId) {
        navigator.clipboard.writeText(text).then(() => {
            const b = document.getElementById(btnId);
            const old = b.textContent;
            b.textContent = 'Copied';
            setTimeout(() => { b.textContent = old; }, 1200);
        });
    }

    async function checkKey() {
        const dot = document.getElementById('keyDot');
        if (!dot) return;
        if (!apiKey) { dot.className = 'key-dot'; return; }
        try {
            const res = await fetch(BASE + '/ping', {headers: {Authorization: 'Bearer ' + apiKey}});
            dot.className = 'key-dot ' + (res.ok ? 'ok' : 'bad');
            dot.title = res.ok ? 'Key works' : 'Key rejected (HTTP ' + res.status + ')';
        } catch (e) { dot.className = 'key-dot bad'; }
    }

    // --- Live rebuild + auto-fire ------------------------------------------------------
    function refreshCode() {
        if (!current) return;
        const vals = collect();
        let body = null;
        const be = document.getElementById('bodyEditor');
        if (be) {
            body = vals.body || null;
            let valid = true;
            if (body) { try { JSON.parse(body); } catch (e) { valid = false; } }
            be.classList.toggle('body-invalid', !valid);
        }
        document.getElementById('reqCode').textContent = genCode(current, buildUrl(current, vals), body);
        return {vals, body};
    }

    function onChange(immediate) {
        const state = refreshCode();
        if (!state || !current) return;
        const hint = document.getElementById('sendHint');
        if (current.m !== 'GET') {
            hint.textContent = 'This ' + current.m + ' writes data — it only runs when you press Send.';
            setResponsePlaceholder('Press Send to run this ' + current.m + ' request for real.');
            return;
        }
        hint.textContent = '';
        if (!apiKey) { setResponsePlaceholder('Paste an API key above and the live response appears here automatically.'); return; }
        if (!pathReady(current, state.vals)) { setResponsePlaceholder('Fill in the path parameter' + (pathParamsOf(current).length > 1 ? 's' : '') + ' above to fetch the live response.'); return; }
        clearTimeout(fireTimer);
        fireTimer = setTimeout(() => fire(false), immediate ? 60 : 550);
    }

    function setResponsePlaceholder(msg) {
        const el = document.getElementById('respCode');
        if (!el) return;
        el.textContent = '';
        document.getElementById('respStatus').textContent = '';
        document.getElementById('respNote').textContent = '';
        el.innerHTML = '<span style="color:#78909c">' + esc(msg) + '</span>';
    }

    async function fire(manual) {
        if (!current) return;
        const ep = current;
        const state = refreshCode();
        if (!apiKey) { setResponsePlaceholder('Paste an API key above first.'); return; }
        if (!pathReady(ep, state.vals)) { setResponsePlaceholder('Fill in the path parameters first.'); return; }
        if (state.body) {
            try { JSON.parse(state.body); } catch (e) { setResponsePlaceholder('The request body is not valid JSON: ' + e.message); return; }
        }
        const url = buildUrl(ep, state.vals);
        const seq = ++fireSeq;

        if (!manual && respCache[url]) { showResponse(respCache[url], true); return; }

        const btn = document.getElementById('sendBtn');
        btn.disabled = true;
        try {
            const res = await fetch(url, {
                method: ep.m,
                headers: Object.assign({Authorization: 'Bearer ' + apiKey},
                    state.body ? {'Content-Type': 'application/json'} : {}),
                body: (ep.m !== 'GET' && state.body) ? state.body : undefined,
            });
            const text = await res.text();
            let pretty = text;
            try { pretty = JSON.stringify(JSON.parse(text), null, 2); } catch (e) { /* leave as-is */ }
            const result = {status: res.status, ok: res.ok, text: pretty, remaining: res.headers.get('X-RateLimit-Remaining')};
            if (ep.m === 'GET') respCache[url] = result;
            if (seq === fireSeq && current === ep) showResponse(result, false);
        } catch (e) {
            if (seq === fireSeq && current === ep) showResponse({status: 0, ok: false, text: 'Request failed: ' + e, remaining: null}, false);
        } finally {
            const b = document.getElementById('sendBtn');
            if (b) b.disabled = false;
        }
    }

    function showResponse(r, cached) {
        const st = document.getElementById('respStatus');
        st.textContent = r.status ? 'HTTP ' + r.status : 'Failed';
        st.className = 'resp-status ' + (r.ok ? 'ok' : 'err');
        document.getElementById('respNote').textContent =
            (cached ? 'cached — press Send to refresh' : '') +
            (r.remaining !== null && !cached ? 'rate limit remaining: ' + r.remaining : '');
        document.getElementById('respCode').textContent = r.text;
    }

    // --- Boot -------------------------------------------------------------------------
    route();
    </script>
</body>
</html>
