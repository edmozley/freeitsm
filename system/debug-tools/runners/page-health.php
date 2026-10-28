<?php
/**
 * D019 Page health - the browser runner. Included twice by tool-page.php:
 * $RUNNER_PART = 'html' inside the tool's card, 'script' after i18n.js.
 *
 * Why the browser: GH #222. In 4.3.0 the Assets page never loaded - one comment
 * swallowed the code after it, the page's script could not be parsed, and so the
 * browser ran NONE of it. The server sent the page perfectly (HTTP 200, `php -l`
 * clean); only a browser can tell that its script cannot be read.
 *
 * What it does, page by page (the list is includes/page_health.php, via
 * api/system/debug-tools/D019_page_health.php):
 *   1. fetch() the page as the signed-in admin - redirect: 'manual', so a page
 *      that sends you elsewhere is reported as skipped rather than followed.
 *   2. Pull out every <script>: inline ones from the HTML, and each same-origin
 *      src file once (cached across pages).
 *   3. COMPILE each with new Function(code) - which parses and never runs.
 *
 * TRAP: never find the error line by injecting the script into a frame and
 * reading window.onerror's line number. A broken script wrapped as
 * "(function(){ ... })" can close the wrapper itself and RUN - with the
 * administrator's session. new Function() parses its body as a function body on
 * its own, so nothing can escape. The line is found by compiling shorter and
 * shorter prefixes (a binary search) until the browser's message appears.
 *
 * @wiki Page-Health-Developer-Guide
 */
if (($RUNNER_PART ?? '') === 'html'): ?>
<style>
    .ph-last { font-size: 13px; color: var(--text-muted, #555); margin: 16px 0 0; padding: 10px 14px; border-radius: 6px; background: var(--surface-2, #f7f8fa); border: 1px solid var(--border-soft, #eee); }
    .ph-last.is-ok { border-left: 4px solid #2e7d32; }
    .ph-last.is-bad { border-left: 4px solid #c62828; }
    .ph-actions { display: flex; align-items: center; gap: 10px; margin-top: 16px; flex-wrap: wrap; }
    .ph-actions .ph-grow { flex: 1; }
    .ph-only { font-size: 13px; color: var(--text-muted, #555); display: none; align-items: center; gap: 6px; cursor: pointer; }
    .ph-progress { margin-top: 16px; }
    .ph-bar { height: 8px; border-radius: 4px; background: var(--surface-3, #eceff1); overflow: hidden; }
    .ph-bar > span { display: block; height: 100%; width: 0; background: var(--sys-accent, #546e7a); transition: width .15s ease; }
    .ph-bar.has-bad > span { background: #c62828; }
    .ph-now { font-size: 12px; color: var(--text-dim, #888); margin-top: 6px; font-family: Consolas, monospace; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .ph-summary { margin-top: 16px; padding: 14px 16px; border-radius: 8px; display: flex; align-items: center; gap: 14px; }
    .ph-summary.is-ok { background: #e8f5e9; color: #1b5e20; }
    .ph-summary.is-bad { background: #fdecea; color: #8e1b1b; }
    .ph-summary-icon { font-size: 26px; line-height: 1; }
    .ph-summary-title { font-size: 16px; font-weight: 600; }
    .ph-summary-sub { font-size: 12.5px; opacity: .85; margin-top: 2px; }
    .ph-groups { margin-top: 16px; display: grid; gap: 8px; }
    .ph-group { border: 1px solid var(--border-soft, #eee); border-radius: 8px; background: var(--surface, #fff); overflow: hidden; }
    .ph-ghead { display: flex; align-items: center; gap: 10px; padding: 9px 14px; cursor: pointer; user-select: none; font-size: 13.5px; }
    .ph-ghead:hover { background: var(--surface-hover, #f5f5f5); }
    .ph-gname { flex: 1; font-weight: 600; color: var(--text, #333); }
    .ph-gcount { font-size: 12px; color: var(--text-dim, #888); }
    .ph-chev { color: var(--text-dim, #888); transition: transform .15s; font-size: 11px; }
    .ph-group.is-open .ph-chev { transform: rotate(90deg); }
    .ph-gbody { display: none; border-top: 1px solid var(--border-soft, #eee); }
    .ph-group.is-open .ph-gbody { display: block; }
    .ph-dot { width: 10px; height: 10px; border-radius: 50%; background: var(--border, #ccc); flex: 0 0 auto; }
    .ph-dot.ok { background: #2e7d32; }
    .ph-dot.bad { background: #c62828; }
    .ph-dot.skip { background: #b0bec5; }
    .ph-dot.busy { background: var(--sys-accent, #546e7a); animation: ph-pulse .8s ease-in-out infinite alternate; }
    @keyframes ph-pulse { from { opacity: .35; } to { opacity: 1; } }
    .ph-page { display: flex; align-items: flex-start; gap: 10px; padding: 6px 14px 6px 28px; font-size: 12.5px; border-top: 1px solid var(--border-soft, #f3f3f3); }
    .ph-page:first-child { border-top: 0; }
    .ph-page .ph-dot { margin-top: 4px; width: 8px; height: 8px; }
    .ph-pmain { flex: 1; min-width: 0; }
    .ph-path { font-family: Consolas, monospace; color: var(--text, #333); word-break: break-all; }
    .ph-note { color: var(--text-dim, #888); font-size: 12px; margin-top: 2px; }
    .ph-open { font-size: 12px; color: var(--sys-accent, #546e7a); text-decoration: none; white-space: nowrap; }
    .ph-open:hover { text-decoration: underline; }
    .ph-err { margin-top: 6px; }
    .ph-err-msg { color: #c62828; font-weight: 500; }
    .ph-code { margin-top: 4px; background: #1e1e1e; color: #d4d4d4; border-radius: 5px; padding: 8px 10px; font: 11.5px/1.5 Consolas, monospace; white-space: pre; overflow-x: auto; }
    .ph-code .ph-hl { color: #ff8a80; }
    .ph-hide-ok .ph-page.is-ok, .ph-hide-ok .ph-page.is-skip, .ph-hide-ok .ph-group.is-clean { display: none; }
    [data-theme-mode="dark"] .ph-summary.is-ok { background: #16301b; color: #a5d6a7; }
    [data-theme-mode="dark"] .ph-summary.is-bad { background: #3a1a1d; color: #fca5a5; }
    [data-theme-mode="dark"] .ph-err-msg { color: #ff8a80; }
</style>

<div class="ph" id="phRoot">
    <div class="ph-last" id="phLast"><?php echo htmlspecialchars(t('system.debug.ph.loading')); ?></div>

    <div class="ph-actions">
        <label class="ph-only" id="phOnlyWrap"><input type="checkbox" id="phOnly"> <?php echo htmlspecialchars(t('system.debug.ph.problems_only')); ?></label>
        <span class="ph-grow"></span>
        <button class="copy-btn" id="phCopy" type="button"><?php echo htmlspecialchars(t('system.debug.copy')); ?></button>
        <button class="copy-btn" id="phDownload" type="button"><?php echo htmlspecialchars(t('system.debug.ph.download')); ?></button>
        <button class="run-btn" id="phRun" type="button" disabled><?php echo htmlspecialchars(t('system.debug.run')); ?></button>
    </div>

    <div class="ph-progress" id="phProgress" hidden>
        <div class="ph-bar" id="phBar"><span></span></div>
        <div class="ph-now" id="phNow"></div>
    </div>

    <div class="ph-summary" id="phSummary" hidden>
        <span class="ph-summary-icon" id="phSumIcon"></span>
        <div><div class="ph-summary-title" id="phSumTitle"></div><div class="ph-summary-sub" id="phSumSub"></div></div>
    </div>

    <div class="ph-groups" id="phGroups"></div>
</div>
<?php elseif (($RUNNER_PART ?? '') === 'script'): ?>
<script>
(function () {
    'use strict';
    var API  = <?php echo json_encode($path_prefix . 'api/system/debug-tools/'); ?>;
    var BASE = new URL(<?php echo json_encode($path_prefix); ?>, location.href).href;   // the app root, absolute
    var CONCURRENCY = 4;
    var T = function (k, p) { return window.t('system.debug.ph.' + k, p); };
    var $ = function (id) { return document.getElementById(id); };
    /** Escapes text for HTML, quotes included. */
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    var pages = [], groups = {}, groupOrder = [], fileCache = {}, version = '', running = false, report = null;

    // ── Compiling without running ──────────────────────────────────────────
    /**
     * Parses a script without running it. Returns null, or {message, line} where
     * line is 1-based within the code. See the TRAP in this file's header.
     */
    function compile(code) {
        try { new Function(code); return null; } catch (e) {
            if (!(e instanceof SyntaxError)) return null;   // anything else is not a parse failure
            var message = e.message;
            var lines = code.split('\n');
            // An unclosed bracket or string: every prefix fails the same way, so the
            // honest answer is "the end", not line 1.
            // TRAP: new Function() wraps the code in its own "{ ... })", so a missing "}"
            // surfaces as "Unexpected token ')'" at every prefix and the search below
            // would blame line 1. Closing it ourselves tells the two apart.
            if (/end of input|unterminated|missing \} after/i.test(message) || closesWith(code)) {
                return { message: message, line: lines.length, unclosed: true };
            }
            var lo = 1, hi = lines.length;
            while (lo < hi) {
                var mid = (lo + hi) >> 1;
                if (failsWith(lines.slice(0, mid).join('\n'), message)) hi = mid; else lo = mid + 1;
            }
            return { message: message, line: lo };
        }
    }
    /** Whether a failing script compiles once up to three closing brackets are added - i.e. something is left open. */
    function closesWith(code) {
        var tails = ['\n}', '\n}}', '\n}}}', '\n)', '\n})', '\n}})'];
        for (var i = 0; i < tails.length; i++) {
            try { new Function(code + tails[i]); return true; } catch (e) { /* try the next */ }
        }
        return false;
    }
    /** Whether this prefix of a script fails with exactly the given message. */
    function failsWith(code, message) {
        try { new Function(code); return false; } catch (e) { return e.message === message; }
    }
    /** The lines around a 1-based line, numbered from `first`, the failing one marked. */
    function excerpt(code, line, first) {
        var lines = code.split('\n'), out = [];
        for (var i = Math.max(1, line - 2); i <= Math.min(lines.length, line + 1); i++) {
            var n = String(first + i - 1);
            var text = lines[i - 1].length > 220 ? lines[i - 1].slice(0, 220) + ' ...' : lines[i - 1];
            out.push((i === line ? '>' : ' ') + ' ' + n + ' | ' + text);
        }
        return out.join('\n');
    }

    // ── One page ───────────────────────────────────────────────────────────
    // TRAP: HTML comments are matched too, and skipped. The menu carries a comment that
    // SHOWS a <script src="toast.js"> tag, and taken as real it put a missing toast.js
    // on every page. A comment cannot hide inside a script: whichever starts first is
    // matched whole, so a "<!--" inside script code stays part of the script.
    var SCRIPT_RE = /<!--[\s\S]*?-->|<script\b([^>]*)>([\s\S]*?)<\/script\s*>/gi;
    /** The value of one attribute in a tag's attribute text, or null. */
    function attr(attrs, name) {
        var m = new RegExp('\\b' + name + '\\s*=\\s*(?:"([^"]*)"|\'([^\']*)\'|([^\\s>]+))', 'i').exec(attrs);
        return m ? (m[1] != null ? m[1] : m[2] != null ? m[2] : m[3]) : null;
    }
    /** A <script type> the browser runs as a classic script. */
    function isClassic(type) {
        return !type || /^(text|application)\/(javascript|ecmascript)$/i.test(type.trim());
    }

    /** Checks one script file, once per address however many pages load it. */
    function checkFile(url) {
        if (!fileCache[url]) {
            fileCache[url] = fetch(url, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
                if (!r.ok) return { status: r.status };
                return r.text().then(function (code) { var e = compile(code); return e ? { err: e, code: code } : { ok: true }; });
            }).catch(function (e) { return { status: 0, message: e.message }; });
        }
        return fileCache[url];
    }

    /** Fetches a page and checks every script on it. Resolves to {state, note, problems[], scripts}. */
    async function checkPage(p) {
        var url = new URL(p.path, BASE).href, res;
        try {
            res = await fetch(url, { credentials: 'same-origin', redirect: 'manual', cache: 'no-store' });
        } catch (e) {
            return { state: 'bad', problems: [{ text: T('err_fetch', { message: e.message }) }], scripts: 0 };
        }
        if (res.type === 'opaqueredirect' || (res.status >= 300 && res.status < 400)) return { state: 'skip', note: T('skip_redirect'), scripts: 0 };
        // Every page on the list is a file that exists, so a 400, 403 or 404 is the PAGE
        // saying "no record to show" / "not for you" - by design, not a fault.
        if (res.status === 404 || res.status === 403 || res.status === 400) return { state: 'skip', note: T('skip_status', { status: res.status }), scripts: 0 };
        if (res.status >= 400) return { state: 'bad', problems: [{ text: T('err_http', { status: res.status }) }], scripts: 0 };
        var type = (res.headers.get('Content-Type') || '').split(';')[0];
        if (!/html/i.test(type)) return { state: 'skip', note: T('skip_not_html', { type: type || '?' }), scripts: 0 };

        var html = await res.text(), problems = [], scripts = 0, n = 0, m;
        // A PHP fatal is still served as HTTP 200 - read the body (see the RBAC lesson).
        var fatal = /<b>(Fatal error|Parse error)<\/b>:\s*([^<\n]{0,200})/i.exec(html) || /\bPHP (Fatal error|Parse error):\s*([^<\n]{0,200})/i.exec(html);
        if (fatal) problems.push({ text: T('err_php', { message: fatal[1] + ': ' + fatal[2].trim() }) });

        SCRIPT_RE.lastIndex = 0;
        var files = [];
        while ((m = SCRIPT_RE.exec(html))) {
            if (m[1] === undefined) continue;                  // an HTML comment
            var attrs = m[1], code = m[2], src = attr(attrs, 'src'), type = attr(attrs, 'type');
            if (!isClassic(type)) continue;                    // JSON blocks, templates - and modules (none today)
            if (src) { files.push(new URL(src, url).href); continue; }
            n++;
            if (!code.trim()) continue;
            scripts++;
            var err = compile(code);
            if (err) {
                // The line in the PAGE SOURCE (View source), not in the PHP file.
                var startLine = html.slice(0, m.index + m[0].indexOf('>') + 1).split('\n').length;
                var pageLine = startLine + err.line - 1;
                problems.push({
                    text: T('err_script', { n: n, line: pageLine, message: err.message + (err.unclosed ? ' (' + T('err_unclosed') + ')' : '') }),
                    code: excerpt(code, err.line, startLine)
                });
            }
        }
        for (var i = 0; i < files.length; i++) {
            var f = files[i], short = f.indexOf(BASE) === 0 ? f.slice(BASE.length) : f;
            if (new URL(f).origin !== location.origin) continue;   // other sites: not ours to check
            scripts++;
            var r = await checkFile(f);
            if (r.status != null) problems.push({ text: T('err_file_http', { file: short.split('?')[0], status: r.status || '?' }) });
            else if (r.err) problems.push({
                text: T('err_file', { file: short.split('?')[0], line: r.err.line, message: r.err.message + (r.err.unclosed ? ' (' + T('err_unclosed') + ')' : '') }),
                code: excerpt(r.code, r.err.line, 1)
            });
        }
        return { state: problems.length ? 'bad' : 'ok', problems: problems, scripts: scripts };
    }

    // ── Drawing ────────────────────────────────────────────────────────────
    function moduleLabel(p) {
        if (p.group === '/') return T('group_home');
        if (p.group === 'auth') return T('group_auth');
        if (p.group === 'self-service') return T('group_portal');
        var name = window.t('common.modules.' + p.module + '.name');
        return name.indexOf('common.modules.') === 0 ? p.group : name;
    }
    function build() {
        groups = {}; groupOrder = [];
        pages.forEach(function (p) {
            var key = p.group;
            if (!groups[key]) { groups[key] = { label: moduleLabel(p), pages: [] }; groupOrder.push(key); }
            groups[key].pages.push(p);
        });
        groupOrder.sort(function (a, b) { return groups[a].label.localeCompare(groups[b].label); });
        var html = '';
        groupOrder.forEach(function (key, gi) {
            var g = groups[key];
            html += '<div class="ph-group" data-g="' + gi + '">' +
                '<div class="ph-ghead"><span class="ph-chev">▶</span><span class="ph-dot" data-gdot></span>' +
                '<span class="ph-gname">' + esc(g.label) + '</span><span class="ph-gcount" data-gcount></span></div><div class="ph-gbody">';
            g.pages.forEach(function (p) {
                p.el = 'ph-p-' + pages.indexOf(p);
                html += '<div class="ph-page" id="' + p.el + '"><span class="ph-dot"></span><div class="ph-pmain">' +
                    '<div class="ph-path">' + esc(p.path) + '</div><div class="ph-detail"></div></div>' +
                    '<a class="ph-open" href="' + esc(new URL(p.path, BASE).href) + '" target="_blank" rel="noopener">' + esc(T('open_page')) + '</a></div>';
            });
            html += '</div></div>';
            g.index = gi;
        });
        $('phGroups').innerHTML = html;
        pages.forEach(function (p) {
            if (p.skip) setResult(p, { state: 'skip', note: p.skip === 'module switched off' ? T('skip_module') : T('skip_never', { why: p.skip }), scripts: 0 });
        });
        groupOrder.forEach(function (key) { paintGroup(groups[key]); });
    }
    function setResult(p, r) {
        p.result = r;
        var row = $(p.el);
        row.className = 'ph-page ' + (r.state === 'ok' ? 'is-ok' : r.state === 'bad' ? 'is-bad' : r.state === 'skip' ? 'is-skip' : '');
        row.querySelector('.ph-dot').className = 'ph-dot ' + (r.state === 'ok' ? 'ok' : r.state === 'bad' ? 'bad' : r.state === 'skip' ? 'skip' : r.state === 'busy' ? 'busy' : '');
        var d = '';
        if (r.note) d += '<div class="ph-note">' + esc(r.note) + '</div>';
        (r.problems || []).forEach(function (pr) {
            d += '<div class="ph-err"><div class="ph-err-msg">' + esc(pr.text) + '</div>' +
                (pr.code ? '<div class="ph-code">' + pr.code.split('\n').map(function (l) {
                    return l.charAt(0) === '>' ? '<span class="ph-hl">' + esc(l) + '</span>' : esc(l);
                }).join('\n') + '</div>' : '') + '</div>';
        });
        row.querySelector('.ph-detail').innerHTML = d;
    }
    function paintGroup(g) {
        var ok = 0, bad = 0, skip = 0, done = 0;
        g.pages.forEach(function (p) {
            var s = p.result && p.result.state;
            if (s === 'ok') ok++; else if (s === 'bad') bad++; else if (s === 'skip') skip++;
            if (s && s !== 'busy') done++;
        });
        var el = document.querySelector('.ph-group[data-g="' + g.index + '"]');
        el.querySelector('[data-gdot]').className = 'ph-dot ' + (bad ? 'bad' : done === g.pages.length && ok ? 'ok' : done === g.pages.length ? 'skip' : '');
        var parts = [T('count_pages', { n: g.pages.length })];
        if (bad) parts.push(bad + ' ' + T('st_problem').toLowerCase());
        if (skip) parts.push(skip + ' ' + T('st_skipped').toLowerCase());
        el.querySelector('[data-gcount]').textContent = parts.join(' · ');
        el.classList.toggle('is-clean', done === g.pages.length && !bad);
        if (bad) el.classList.add('is-open');
    }

    function showLast(last) {
        var el = $('phLast');
        el.classList.remove('is-ok', 'is-bad');
        if (!last) { el.textContent = T('last_never'); return; }
        var when = new Date(last.ran_at.replace(' ', 'T') + 'Z').toLocaleString([], window.tzOpts ? window.tzOpts() : {});
        var p = { when: when, by: last.by || '?', version: last.version || '?', problems: last.problems };
        el.textContent = last.problems ? T('last_problems', p) : T('last_clear', p);
        el.classList.add(last.problems ? 'is-bad' : 'is-ok');
    }

    // ── Running ────────────────────────────────────────────────────────────
    async function run() {
        if (running) return;
        running = true;
        $('phRun').disabled = true;
        $('phCopy').style.display = $('phDownload').style.display = 'none';
        $('phSummary').hidden = true;
        $('phProgress').hidden = false;
        $('phBar').classList.remove('has-bad');
        fileCache = {};
        var todo = pages.filter(function (p) { return !p.skip; });
        todo.forEach(function (p) { setResult(p, { state: 'waiting' }); p.result = null; });   // a second run starts clean
        groupOrder.forEach(function (key) { paintGroup(groups[key]); });
        var next = 0, done = 0, anyBad = false;
        async function worker() {
            while (next < todo.length) {
                var p = todo[next++];
                $(p.el).querySelector('.ph-dot').className = 'ph-dot busy';
                $('phNow').textContent = T('checking', { n: done + 1, total: todo.length }) + ' · ' + p.path;
                var r;
                try { r = await checkPage(p); } catch (e) { r = { state: 'bad', problems: [{ text: T('err_fetch', { message: e.message }) }], scripts: 0 }; }
                setResult(p, r);
                if (r.state === 'bad') { anyBad = true; $('phBar').classList.add('has-bad'); }
                paintGroup(groups[p.group]);
                done++;
                $('phBar').firstElementChild.style.width = Math.round(done / todo.length * 100) + '%';
            }
        }
        var workers = [];
        for (var i = 0; i < CONCURRENCY; i++) workers.push(worker());
        await Promise.all(workers);
        finish();
    }

    function finish() {
        var checked = 0, scripts = 0, skipped = 0, problems = 0, badPages = 0;
        pages.forEach(function (p) {
            var r = p.result || {};
            if (r.state === 'skip') skipped++;
            else { checked++; scripts += r.scripts || 0; }
            if (r.state === 'bad') { badPages++; problems += r.problems.length; }
        });
        $('phProgress').hidden = true;
        var s = $('phSummary');
        s.hidden = false;
        s.className = 'ph-summary ' + (problems ? 'is-bad' : 'is-ok');
        $('phSumIcon').textContent = problems ? '✗' : '✓';
        $('phSumTitle').textContent = problems ? T('problems', { problems: problems, pages: badPages }) : T('all_clear', { pages: checked });
        $('phSumSub').textContent = T('stats', { pages: checked, scripts: scripts, skipped: skipped });
        // Modules with a problem move to the top, open - the reason anyone ran this.
        var list = $('phGroups');
        groupOrder.slice().reverse().forEach(function (key) {
            if (groups[key].pages.some(function (p) { return p.result && p.result.state === 'bad'; })) {
                list.insertBefore(list.querySelector('.ph-group[data-g="' + groups[key].index + '"]'), list.firstChild);
            }
        });
        $('phOnlyWrap').style.display = 'inline-flex';
        $('phCopy').style.display = $('phDownload').style.display = 'inline-block';
        report = buildReport({ checked: checked, scripts: scripts, skipped: skipped, problems: problems, badPages: badPages });
        running = false;
        $('phRun').disabled = false;
        fetch(API + 'D019_page_health_save.php', {
            method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ pages: checked, scripts: scripts, problems: problems, skipped: skipped })
        }).then(function (r) { return r.json(); }).then(function (j) { if (j.success) showLast(j.last); }).catch(function () {});
    }

    /** The plain-text report - what gets pasted into a bug report. */
    function buildReport(c) {
        var L = [];
        L.push('FreeITSM ' + version + ' - Page health (D019)');
        L.push(new Date().toISOString().replace('T', ' ').slice(0, 19) + ' UTC · ' + navigator.userAgent);
        L.push('');
        L.push(c.problems ? T('problems', { problems: c.problems, pages: c.badPages }) : T('all_clear', { pages: c.checked }));
        L.push(T('stats', { pages: c.checked, scripts: c.scripts, skipped: c.skipped }));
        var bad = pages.filter(function (p) { return p.result && p.result.state === 'bad'; });
        if (bad.length) {
            L.push('', '=== PROBLEMS ===');
            bad.forEach(function (p) {
                L.push('', p.path);
                p.result.problems.forEach(function (pr) { L.push('  ' + pr.text); if (pr.code) pr.code.split('\n').forEach(function (l) { L.push('    ' + l); }); });
            });
        }
        var skipped = pages.filter(function (p) { return p.result && p.result.state === 'skip'; });
        if (skipped.length) {
            L.push('', '=== SKIPPED ===');
            skipped.forEach(function (p) { L.push(p.path + ' - ' + p.result.note); });
        }
        L.push('', '=== CHECKED, NO PROBLEM ===');
        groupOrder.forEach(function (key) {
            var ok = groups[key].pages.filter(function (p) { return p.result && p.result.state === 'ok'; }).length;
            if (ok) L.push(groups[key].label + ': ' + ok);
        });
        return L.join('\n') + '\n';
    }

    // ── Wiring ─────────────────────────────────────────────────────────────
    $('phGroups').addEventListener('click', function (e) {
        var h = e.target.closest('.ph-ghead');
        if (h) h.parentNode.classList.toggle('is-open');
    });
    $('phOnly').addEventListener('change', function () { $('phRoot').classList.toggle('ph-hide-ok', this.checked); });
    $('phRun').addEventListener('click', run);
    $('phCopy').addEventListener('click', async function () {
        var b = this;
        try {
            if (!(await copyToClipboard(report))) throw new Error('refused');
            b.classList.add('copied'); b.textContent = window.t('system.debug.copied');
            setTimeout(function () { b.classList.remove('copied'); b.textContent = window.t('system.debug.copy'); }, 1800);
        } catch (e) { /* the report is on screen */ }
    });
    $('phDownload').addEventListener('click', function () {
        var a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob([report], { type: 'text/plain' }));
        a.download = 'freeitsm-page-health-' + new Date().toISOString().slice(0, 10) + '.txt';
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(function () { URL.revokeObjectURL(a.href); }, 5000);
    });

    fetch(API + 'D019_page_health.php', { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (!j.success) throw new Error(j.error || 'error');
            pages = j.pages; version = j.version;
            showLast(j.last);
            build();
            var toCheck = pages.filter(function (p) { return !p.skip; }).length;
            $('phNow').textContent = T('ready', { pages: toCheck });
            $('phProgress').hidden = false;
            $('phRun').disabled = false;
            window.__pageHealth = { run: run, pages: function () { return pages; }, report: function () { return report; }, compile: compile };
        })
        .catch(function (e) { $('phLast').textContent = T('load_failed', { message: e.message }); });
})();
</script>
<?php endif;
