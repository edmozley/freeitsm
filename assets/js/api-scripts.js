/**
 * System -> API -> Scripts: the API script library.
 *
 * Lists the ready-made PowerShell and Python scripts shipped in script-library/
 * (through api/system/api_keys/scripts.php), filters them, and shows one with
 * Copy and Download. Read only - nothing here runs a script.
 *
 * TRAP: a script is shown with textContent, never innerHTML. The files are ours
 * and reviewed, but they are full of < > & and HTML in strings (the knowledge
 * migration builds HTML), and textContent is the only way they are guaranteed
 * to show as text. Comment lines are wrapped in spans built with textContent too.
 */
(function () {
    const API = '../../api/system/api_keys/scripts.php';
    const t = (k, v) => window.t('system.api.scripts.' + k, v);
    let scripts = [];
    let loaded = false;
    let current = null;          // { slug, lang }
    const el = id => document.getElementById(id);
    // Read the deep link NOW: showTab() rewrites the address to ?tab=scripts before load() runs.
    const initial = new URLSearchParams(location.search);

    // The app's own module names (common.modules.*), so the filter says "Sager"
    // in Danish like everywhere else. "general" (the key check) is the one extra.
    function moduleName(key) {
        const tr = k => { const v = window.t(k); return v && v !== k ? v : ''; };
        if (key === 'general') return t('mod_general');
        return tr('common.modules.' + key + '.name_short') || tr('common.modules.' + key + '.name') || key;
    }

    async function load() {
        if (loaded) return;
        loaded = true;
        try {
            const r = await fetch(API);
            const d = await r.json();
            if (!d.success) throw new Error(d.error || '');
            scripts = d.scripts || [];
        } catch (e) {
            el('scrList').textContent = t('load_failed');
            return;
        }
        // Modules that actually have scripts, in name order.
        const mods = [...new Set(scripts.map(s => s.module))].sort((a, b) => moduleName(a).localeCompare(moduleName(b)));
        for (const m of mods) el('scrModule').add(new Option(moduleName(m), m));
        render();
        // Deep link: ?tab=scripts&script=<slug>&lang=python
        if (initial.get('script')) {
            if (initial.get('lang')) el('scrLang').value = initial.get('lang');
            show(initial.get('script'), el('scrLang').value);
        }
    }

    function matches(s) {
        const q = el('scrSearch').value.trim().toLowerCase();
        if (el('scrModule').value && s.module !== el('scrModule').value) return false;
        if (el('scrChanges').value && String(s.changes ? 'yes' : 'no') !== el('scrChanges').value) return false;
        if (el('scrLevel').value && s.level !== el('scrLevel').value) return false;
        if (!q) return true;
        const hay = [s.title, s.summary, s.module, moduleName(s.module), ...(s.endpoints || []).map(e => e.join(' ')), ...(s.permissions || [])]
            .join(' ').toLowerCase();
        return q.split(/\s+/).every(w => hay.includes(w));
    }

    function render() {
        const list = el('scrList');
        list.replaceChildren();
        const shown = scripts.filter(matches);
        el('scrCount').textContent = t('count', { n: shown.length });
        if (!shown.length) {
            const li = document.createElement('li');
            li.className = 'scr-empty';
            li.textContent = t('none');
            list.appendChild(li);
            return;
        }
        for (const s of shown) {
            const li = document.createElement('li');
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'scr-item' + (current && current.slug === s.slug ? ' active' : '');
            const title = document.createElement('span');
            title.className = 'scr-item-title';
            title.textContent = s.title;
            const meta = document.createElement('span');
            meta.className = 'scr-item-meta';
            meta.textContent = moduleName(s.module) + ' · ' + t('level_' + s.level) + (s.changes ? ' · ' + t('kind_write') : '');
            b.append(title, meta);
            b.addEventListener('click', () => show(s.slug, el('scrLang').value));
            li.appendChild(b);
            list.appendChild(li);
        }
    }

    /** Text -> <code> with comment lines picked out. Built from text nodes only. */
    function paint(code, text) {
        code.replaceChildren();
        let inBlock = false;   // PowerShell <# ... #> block comments
        for (const line of text.split(/\r?\n/)) {
            const trimmed = line.trim();
            const starts = trimmed.startsWith('<#');
            const isComment = inBlock || starts || trimmed.startsWith('#');
            if (starts) inBlock = !trimmed.includes('#>', 2);
            else if (inBlock && trimmed.includes('#>')) inBlock = false;
            if (isComment) {
                const span = document.createElement('span');
                span.className = 'scr-comment';
                span.textContent = line + '\n';
                code.appendChild(span);
            } else {
                code.appendChild(document.createTextNode(line + '\n'));
            }
        }
    }

    async function show(slug, lang) {
        const s = scripts.find(x => x.slug === slug);
        if (!s) return;
        if (!s.languages.includes(lang)) lang = s.languages[0];
        current = { slug, lang };
        render();
        const view = el('scrView');
        view.querySelector('.scr-pick').hidden = true;
        el('scrDetail').hidden = false;
        el('scrTitle').textContent = s.title;
        el('scrSummary').textContent = s.summary;
        el('scrNote').textContent = s.changes ? t('changes_note') : t('read_note');
        el('scrNote').className = 'scr-note' + (s.changes ? ' writes' : '');
        // Endpoints and permissions: a list of chips each.
        const fill = (id, items, empty) => {
            const box = el(id);
            box.replaceChildren();
            if (!items.length) { box.textContent = empty; return; }
            for (const it of items) {
                const c = document.createElement('code');
                c.className = 'scr-chip';
                c.textContent = it;
                box.appendChild(c);
            }
        };
        fill('scrEndpoints', (s.endpoints || []).map(e => e.join(' ')), '');
        fill('scrPerms', s.permissions || [], t('permissions_none'));
        document.querySelectorAll('.scr-lang').forEach(b => {
            b.classList.toggle('active', b.dataset.lang === lang);
            b.disabled = !s.languages.includes(b.dataset.lang);
        });
        el('scrDownload').href = API + '?slug=' + encodeURIComponent(slug) + '&lang=' + encodeURIComponent(lang) + '&download=1';
        const code = el('scrCode');
        code.textContent = '…';
        try {
            const r = await fetch(API + '?slug=' + encodeURIComponent(slug) + '&lang=' + encodeURIComponent(lang));
            const d = await r.json();
            if (!d.success) throw new Error(d.error || '');
            if (!current || current.slug !== slug || current.lang !== lang) return;   // a later click won
            el('scrFile').textContent = d.filename;
            paint(code, d.text);
            code.dataset.raw = d.text;
        } catch (e) {
            code.textContent = t('load_failed');
        }
        try { history.replaceState(null, '', '?tab=scripts&script=' + encodeURIComponent(slug) + '&lang=' + encodeURIComponent(lang)); } catch (e) {}
    }

    function wire() {
        ['scrSearch', 'scrModule', 'scrChanges', 'scrLevel'].forEach(id => el(id).addEventListener('input', render));
        el('scrLang').addEventListener('change', () => { if (current) show(current.slug, el('scrLang').value); });
        document.querySelectorAll('.scr-lang').forEach(b => b.addEventListener('click', () => {
            el('scrLang').value = b.dataset.lang;
            if (current) show(current.slug, b.dataset.lang);
        }));
        el('scrCopy').addEventListener('click', () => {
            const b = el('scrCopy');
            copyToClipboard(el('scrCode').dataset.raw || '').then(ok => {
                const old = b.textContent;
                b.textContent = ok ? t('copied') : t('copy_failed');
                setTimeout(() => { b.textContent = old; }, 1600);
            });
        });
    }

    window.ApiScripts = { load };
    document.addEventListener('DOMContentLoaded', () => { if (el('tabScripts')) wire(); });
})();
