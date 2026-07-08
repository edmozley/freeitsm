<?php
/**
 * System - Topology / overview.
 *
 * A collapsible, company-rooted tree of the whole configuration — companies and
 * their mailboxes, domains, sign-in providers, analysts (with access),
 * requesters and tickets — plus a "Global / shared" node for things that serve
 * every company. Read-only; deep-links out to the relevant editors.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

$current_page = 'topology';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . $path_prefix . 'login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Topology</title>
    <link rel="stylesheet" href="<?php echo $path_prefix; ?>assets/css/inbox.css">
    <style>
        .topo-wrap { height: calc(100vh - 48px); overflow-y: auto; background: #f5f7fa; padding: 24px 28px 60px; }
        .topo-head { display: flex; align-items: baseline; gap: 14px; flex-wrap: wrap; margin-bottom: 6px; }
        .topo-head h2 { font-size: 22px; color: #333; margin: 0; }
        .topo-sub { font-size: 13px; color: #888; margin: 0 0 16px; }

        .topo-totals { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; }
        .topo-stat { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px 14px; font-size: 13px; color: #555; }
        .topo-stat strong { color: #1f2330; font-size: 16px; margin-right: 5px; }

        .topo-toolbar { display: flex; gap: 10px; align-items: center; margin-bottom: 14px; flex-wrap: wrap; }
        .topo-search { position: relative; flex: 1; max-width: 320px; }
        .topo-search input { width: 100%; padding: 8px 12px; border: 1px solid #d6dde3; border-radius: 7px; font-size: 13px; box-sizing: border-box; }
        .topo-search input:focus { outline: none; border-color: #546e7a; }
        .topo-btn { background: #fff; border: 1px solid #d6dde3; border-radius: 7px; padding: 8px 12px; font-size: 12.5px; color: #555; cursor: pointer; }
        .topo-btn:hover { background: #f3f4f6; }

        .tree { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 8px 4px; }
        .tree-row { display: flex; align-items: center; gap: 8px; padding: 6px 10px; border-radius: 6px; cursor: default; font-size: 13.5px; color: #333; }
        .tree-row.expandable { cursor: pointer; }
        .tree-row.expandable:hover { background: #f5f7fa; }
        .tree-caret { width: 14px; flex-shrink: 0; color: #98a2b3; font-size: 11px; transition: transform 0.12s; display: inline-block; text-align: center; }
        .tree-row.open > .tree-caret { transform: rotate(90deg); }
        .tree-caret.empty { visibility: hidden; }
        .tree-ico { width: 16px; height: 16px; flex-shrink: 0; color: #6b7280; }
        .tree-label { color: #1f2330; }
        .tree-label.muted { color: #98a2b3; font-style: italic; }
        .tree-count { background: #eef2ff; color: #4338ca; font-size: 11px; font-weight: 600; border-radius: 10px; padding: 1px 8px; }
        .tree-link { margin-left: 6px; font-size: 11.5px; color: #6366f1; text-decoration: none; }
        .tree-link:hover { text-decoration: underline; }
        .tree-children { display: none; margin-left: 20px; border-left: 1px solid #eef0f4; padding-left: 4px; }
        .tree-children.open { display: block; }

        .tag { font-size: 10.5px; font-weight: 600; border-radius: 10px; padding: 1px 8px; white-space: nowrap; }
        .tag.green { background: #e8f5e9; color: #2e7d32; }
        .tag.amber { background: #fff8e1; color: #8a5a00; }
        .tag.grey  { background: #f1f3f5; color: #868e96; }
        .tag.blue  { background: #e3f2fd; color: #1565c0; }
        .tag.default { background: #ede7f6; color: #5e35b1; }

        .topo-loading, .topo-error { color: #888; font-size: 13px; padding: 20px; }
        .topo-error { color: #c0392b; }
    </style>
    <?php echo Tz::scriptTag(); ?>
    <script src="<?php echo $path_prefix; ?>assets/js/tz.js?v=1"></script>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="topo-wrap">
        <div class="topo-head">
            <h2>Topology</h2>
        </div>
        <p class="topo-sub">How your companies, mailboxes, domains, sign-in providers, analysts and requesters fit together. Read-only — click a section's link to manage it.</p>

        <div class="topo-totals" id="topoTotals"></div>

        <div class="topo-toolbar">
            <div class="topo-search"><input type="text" id="topoSearch" placeholder="Filter companies…" autocomplete="off"></div>
            <button class="topo-btn" id="topoExpand">Expand all</button>
            <button class="topo-btn" id="topoCollapse">Collapse all</button>
        </div>

        <div id="topoTree" class="tree"><div class="topo-loading">Loading…</div></div>
    </div>

    <script>
    const API = '<?php echo $path_prefix; ?>api/';
    const BASE = '<?php echo $path_prefix; ?>';

    function esc(s) { return (s ?? '').toString().replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

    // Inline icons by key.
    const ICONS = {
        company: '<path d="M3 21h18"></path><path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"></path><path d="M9 8h1M9 12h1M9 16h1M14 8h1M14 12h1M14 16h1"></path>',
        globe: '<circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>',
        mail: '<rect x="2" y="4" width="20" height="16" rx="2"></rect><path d="m22 7-10 6L2 7"></path>',
        at: '<circle cx="12" cy="12" r="4"></circle><path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-3.92 7.94"></path>',
        key: '<path d="M15 7h3a5 5 0 0 1 5 5 5 5 0 0 1-5 5h-3m-6 0H6a5 5 0 0 1-5-5 5 5 0 0 1 5-5h3"></path><line x1="8" y1="12" x2="16" y2="12"></line>',
        users: '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"></path>',
        user: '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle>',
        ticket: '<path d="M3 9a2 2 0 0 0 2-2 2 2 0 0 1 4 0 2 2 0 0 0 2 2h7a2 2 0 0 1 2 2v2a2 2 0 0 1-2 2H11a2 2 0 0 0-2 2 2 2 0 0 1-4 0 2 2 0 0 0-2-2 2 2 0 0 1-2-2v-2a2 2 0 0 1 2-2z"></path>',
        dot: '<circle cx="12" cy="12" r="2.5"></circle>',
    };
    function ico(key) {
        return `<svg class="tree-ico" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">${ICONS[key] || ''}</svg>`;
    }

    // A node with optional children. children = array of HTML strings (leaf rows or nested nodes).
    function node(iconKey, labelHtml, opts = {}) {
        const { count, link, tags = [], children = null, open = false, muted = false } = opts;
        const hasKids = Array.isArray(children) && children.length > 0;
        const caret = hasKids ? `<span class="tree-caret">▶</span>` : `<span class="tree-caret empty">▶</span>`;
        const countHtml = (count !== undefined && count !== null) ? `<span class="tree-count">${count}</span>` : '';
        const tagsHtml = tags.map(t => `<span class="tag ${t.cls}">${esc(t.text)}</span>`).join(' ');
        const linkHtml = link ? `<a class="tree-link" href="${link.href}" onclick="event.stopPropagation()">${esc(link.text)} ↗</a>` : '';
        const rowCls = 'tree-row' + (hasKids ? ' expandable' : '') + (open ? ' open' : '');
        const labelCls = 'tree-label' + (muted ? ' muted' : '');
        let html = `<div class="tree-node"><div class="${rowCls}">${caret}${ico(iconKey)}<span class="${labelCls}">${labelHtml}</span> ${countHtml} ${tagsHtml} ${linkHtml}</div>`;
        if (hasKids) html += `<div class="tree-children${open ? ' open' : ''}">${children.join('')}</div>`;
        html += `</div>`;
        return html;
    }
    // A simple leaf row (no children).
    function leaf(iconKey, labelHtml, tags = []) {
        const tagsHtml = tags.map(t => `<span class="tag ${t.cls}">${esc(t.text)}</span>`).join(' ');
        return `<div class="tree-node"><div class="tree-row"><span class="tree-caret empty">▶</span>${ico(iconKey)}<span class="tree-label">${labelHtml}</span> ${tagsHtml}</div></div>`;
    }

    function mailboxTags(m) {
        if (!m.is_active) return [{ cls: 'grey', text: 'inactive' }];
        return m.is_authenticated ? [{ cls: 'green', text: 'signed in' }] : [{ cls: 'amber', text: 'not signed in' }];
    }
    function prettyProvider(p) {
        return ({ microsoft: 'Microsoft 365', google: 'Google Workspace', imap: 'IMAP' })[p] || (p ? p : 'Unknown');
    }
    // Expandable mailbox node: row shows name + badges; expand for the config.
    function buildMailbox(m, isShared) {
        const tags = mailboxTags(m);
        if (isShared) tags.unshift({ cls: 'grey', text: 'shared' });
        const cfg = [
            leaf('dot', `Address: <strong>${esc(m.address || '—')}</strong>`),
            leaf('dot', `Kind: ${esc(prettyProvider(m.provider))}`),
        ];
        if (m.folder) cfg.push(leaf('dot', `Folder: ${esc(m.folder)}`));
        cfg.push(leaf('dot', `Status: ${m.is_active ? 'active' : 'inactive'}, ${m.is_authenticated ? 'signed in' : 'not signed in'}`));
        if (m.last_checked) cfg.push(leaf('dot', `Last checked: ${esc(m.last_checked)} UTC`));
        return node('mail', esc(m.name), { tags, children: cfg });
    }

    function buildCompany(c, sharedMailboxes) {
        const cats = [];

        // Mailboxes — pinned to this company PLUS the shared ones that serve it.
        const shared = sharedMailboxes || [];
        const mbItems = c.mailboxes.map(m => buildMailbox(m, false))
            .concat(shared.map(m => buildMailbox(m, true)));
        const mbCount = c.mailboxes.length + shared.length;
        cats.push(node('mail', 'Mailboxes', { count: mbCount, children: mbItems.length ? mbItems : [leaf('mail', '<span class="tree-label muted">none</span>')] }));

        // Domains
        cats.push(node('at', 'Domains', {
            count: c.domains.length,
            link: { href: BASE + 'system/companies/', text: 'manage' },
            children: c.domains.length ? c.domains.map(d => leaf('at', esc(d))) : [leaf('at', '<span class="tree-label muted">none</span>')]
        }));

        // Specific senders (only if any)
        if (c.senders && c.senders.length) {
            cats.push(node('at', 'Specific senders', { count: c.senders.length, children: c.senders.map(s => leaf('at', esc(s))) }));
        }

        // Sign-in providers
        cats.push(node('key', 'Sign-in providers', {
            count: c.providers.length,
            link: { href: BASE + 'system/sso/', text: 'manage' },
            children: c.providers.length
                ? c.providers.map(p => leaf('key', esc(p.name), p.enabled ? [] : [{ cls: 'grey', text: 'disabled' }]))
                : [leaf('key', '<span class="tree-label muted">none (uses Global / local password)</span>')]
        }));

        // Analysts with access (restricted analysts granted to this company)
        cats.push(node('users', 'Analysts (restricted, with access)', {
            count: c.analysts.length,
            link: { href: BASE + 'system/analysts/', text: 'manage' },
            children: c.analysts.length
                ? c.analysts.map(a => leaf('user', esc(a.name)))
                : [leaf('user', '<span class="tree-label muted">none restricted here — all-access analysts can work in every company (see Global)</span>')]
        }));

        // Requesters (count only in v1)
        cats.push(leaf('users', `Requesters <span class="tree-label muted">(by email domain)</span>`, [{ cls: 'blue', text: String(c.requester_count) }]));

        // Tickets — counted across the whole company, every department (unlike the
        // Tickets module, which scopes folder counts to the viewer's own teams).
        cats.push(leaf('ticket', 'Tickets <span class="tree-label muted">(whole company, all departments)</span>',
            [{ cls: 'blue', text: `${c.ticket_total} total` }, { cls: 'amber', text: `${c.ticket_open} open` }]));

        const tags = [];
        if (c.is_default) tags.push({ cls: 'default', text: 'default' });
        if (!c.has_sendable_mailbox) tags.push({ cls: 'amber', text: 'no sendable mailbox' });

        return node('company', esc(c.name), {
            tags,
            link: { href: BASE + 'system/companies/', text: 'open' },
            open: true,            // companies expanded by default; categories collapsed
            children: cats
        });
    }

    function buildGlobal(g) {
        const kids = [];
        kids.push(node('mail', 'Shared mailboxes', {
            count: g.mailboxes.length,
            children: g.mailboxes.length ? g.mailboxes.map(m => buildMailbox(m, false)) : [leaf('mail', '<span class="tree-label muted">none</span>')]
        }));
        kids.push(node('key', 'Global sign-in providers', {
            count: g.providers.length,
            link: { href: BASE + 'system/sso/', text: 'manage' },
            children: g.providers.length ? g.providers.map(p => leaf('key', esc(p.name), p.enabled ? [] : [{ cls: 'grey', text: 'disabled' }])) : [leaf('key', '<span class="tree-label muted">none</span>')]
        }));
        kids.push(node('users', 'All-access analysts', {
            count: g.all_access_analysts.length,
            link: { href: BASE + 'system/analysts/', text: 'manage' },
            children: g.all_access_analysts.length ? g.all_access_analysts.map(a => leaf('user', esc(a.name))) : [leaf('user', '<span class="tree-label muted">none</span>')]
        }));
        return node('globe', '<strong>Global / shared</strong> <span class="tree-label muted">— serves every company</span>', { open: true, children: kids });
    }

    async function load() {
        const tree = document.getElementById('topoTree');
        try {
            const r = await fetch(API + 'system/get_topology.php', { credentials: 'same-origin' });
            const d = await r.json();
            if (!d.success) throw new Error(d.error || 'failed');

            // Totals
            document.getElementById('topoTotals').innerHTML = [
                ['Companies', d.totals.companies], ['Mailboxes', d.totals.mailboxes],
                ['Sign-in providers', d.totals.providers], ['Analysts', d.totals.analysts], ['Requesters', d.totals.users]
            ].map(([k, v]) => `<div class="topo-stat"><strong>${v}</strong>${esc(k)}</div>`).join('');

            let html = d.companies.map(c => buildCompany(c, d.global.mailboxes)).join('');
            html += buildGlobal(d.global);
            tree.innerHTML = html;
        } catch (e) {
            tree.innerHTML = '<div class="topo-error">Could not load topology: ' + esc(e.message) + '</div>';
        }
    }

    // Expand/collapse via event delegation.
    document.getElementById('topoTree').addEventListener('click', function (e) {
        const row = e.target.closest('.tree-row.expandable');
        if (!row) return;
        const kids = row.parentElement.querySelector(':scope > .tree-children');
        if (!kids) return;
        row.classList.toggle('open');
        kids.classList.toggle('open');
    });
    document.getElementById('topoExpand').addEventListener('click', () => {
        document.querySelectorAll('#topoTree .tree-children').forEach(el => el.classList.add('open'));
        document.querySelectorAll('#topoTree .tree-row.expandable').forEach(el => el.classList.add('open'));
    });
    document.getElementById('topoCollapse').addEventListener('click', () => {
        document.querySelectorAll('#topoTree .tree-children').forEach(el => el.classList.remove('open'));
        document.querySelectorAll('#topoTree .tree-row.expandable').forEach(el => el.classList.remove('open'));
    });
    document.getElementById('topoSearch').addEventListener('input', function () {
        const q = this.value.trim().toLowerCase();
        // Filter only the top-level company/global nodes by their label text.
        document.querySelectorAll('#topoTree > .tree-node').forEach(n => {
            const label = (n.querySelector('.tree-row .tree-label')?.textContent || '').toLowerCase();
            n.style.display = (q === '' || label.indexOf(q) !== -1) ? '' : 'none';
        });
    });

    load();
    </script>
</body>
</html>
