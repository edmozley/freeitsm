/**
 * Settings: Tabs or One page (GH #399).
 *
 * Every module's settings page draws its tab bar with renderSettingsTabBar()
 * (includes/settings_manifest.php), which also loads this file. Tabs is the page as it
 * always was. One page shows every panel at once, an index down the left that follows
 * the scroll, and a search box that hides the sections that do not match.
 *
 * It works through the page's OWN tab buttons rather than around them: switching to One
 * page clicks each tab once, so whatever a tab loads when opened (lookups, lists, a
 * designer) is loaded exactly as if the person had opened it. Then every panel is shown.
 * Nothing is moved in the DOM - the index sits in a grid column beside the panels - so
 * ids, page scripts and styles keep working.
 *
 * Steps aside, leaving the tabs alone, when: the page has fewer than two panels it can
 * match to tabs (the SSO pages draw a tab bar with no panels); the screen is a phone.
 * The choice is the person's, saved as user_preferences.settings_view and used on every
 * module's settings page.
 */
(function () {
    'use strict';
    const cfg = window.SETTINGS_VIEW || { mode: '', labels: {} };
    const L = (k, fallback) => (cfg.labels && cfg.labels[k]) || fallback;
    const phone = window.matchMedia('(max-width: 768px)');

    // TRAP: never panel.scrollIntoView() here. Several settings pages (Tickets is one)
    // scroll inside .container under a fixed-height shell whose body is overflow:hidden.
    // scrollIntoView scrolls EVERY scrollable ancestor - and a script may scroll an
    // overflow:hidden element - so it also scrolled the body, pushing the navbar off the
    // top and leaving a gap at the bottom that the person cannot scroll back (Ed, 2026-11-20).
    // Scroll only the nearest ancestor that really scrolls, or the window if none does.
    function goTo(panel, smooth) {
        let el = panel.parentElement;
        while (el && el !== document.body && el !== document.documentElement) {
            const oy = getComputedStyle(el).overflowY;
            if ((oy === 'auto' || oy === 'scroll') && el.scrollHeight > el.clientHeight) break;
            el = el.parentElement;
        }
        const behavior = smooth ? 'smooth' : 'auto';
        const gap = 12;
        if (el && el !== document.body && el !== document.documentElement) {
            const top = panel.getBoundingClientRect().top - el.getBoundingClientRect().top + el.scrollTop - gap;
            el.scrollTo({ top, behavior });
        } else {
            window.scrollTo({ top: panel.getBoundingClientRect().top + window.scrollY - gap, behavior });
        }
    }

    function start() {
        const bar = document.querySelector('.tabs');
        if (!bar || bar.dataset.svReady) return;
        const items = Array.from(bar.querySelectorAll('.tab[data-tab]')).map(btn => {
            const id = btn.dataset.tab;
            return {
                btn, id,
                label: btn.textContent.replace(/\s*↗\s*$/, '').trim(),
                moved: btn.tagName === 'A',
                panel: btn.tagName === 'A' ? null
                     : (document.getElementById(id + '-tab') || document.getElementById('tab-' + id) || document.getElementById(id)),
            };
        });
        const panels = items.filter(i => i.panel && i.panel.classList.contains('tab-content'));
        if (panels.length < 2) return;   // nothing to gather, or a tab bar that is not a settings page (SSO pages have none)
        bar.dataset.svReady = '1';
        const container = bar.parentElement;
        // TRAP: never assume the page calls it .container - Morning Checks uses .settings-container.
        // The One page CSS targets this class, which the script sets itself.
        container.classList.add('sv-host');
        let warmed = false;
        let mode = cfg.mode === 'page' ? 'page' : 'tabs';

        // ---- the switch, beside the tab bar ----
        const sw = document.createElement('div');
        sw.className = 'sv-switch';
        sw.setAttribute('role', 'group');
        sw.setAttribute('aria-label', L('view', 'View'));
        const mk = (value, text) => {
            const b = document.createElement('button');
            b.type = 'button'; b.dataset.view = value; b.textContent = text;
            b.addEventListener('click', () => setMode(value, true));
            sw.appendChild(b);
            return b;
        };
        mk('tabs', L('tabs', 'Tabs'));
        mk('page', L('page', 'One page'));
        bar.insertAdjacentElement('beforebegin', sw);

        // ---- the index (built once, shown in One page) ----
        // TRAP: a div with role=navigation, not a <nav>: a module stylesheet may style every
        // <nav> on its page (Morning Checks made this one a visible flex row in Tabs view).
        const index = document.createElement('div');
        index.setAttribute('role', 'navigation');
        index.className = 'sv-index';
        index.setAttribute('aria-label', L('index', 'Settings'));
        const search = document.createElement('input');
        search.type = 'search'; search.className = 'sv-search'; search.placeholder = L('search', 'Search settings');
        search.setAttribute('aria-label', L('search', 'Search settings'));
        index.appendChild(search);
        const list = document.createElement('div');
        list.className = 'sv-list';
        index.appendChild(list);
        const none = document.createElement('div');
        none.className = 'sv-none'; none.hidden = true; none.textContent = L('none', 'No settings match');
        index.appendChild(none);
        items.forEach(it => {
            const a = document.createElement('a');
            a.className = 'sv-link';
            a.textContent = it.label;
            if (it.moved) {
                a.href = it.btn.getAttribute('href');
                a.classList.add('sv-out');
                a.title = L('elsewhere', 'On another page');
                a.textContent = it.label + ' ↗';
            } else if (it.panel) {
                a.href = '#' + it.panel.id;
                a.addEventListener('click', e => { e.preventDefault(); goTo(it.panel, true); });
            } else return;
            it.link = a;
            list.appendChild(a);
        });
        container.insertBefore(index, container.firstChild);

        search.addEventListener('input', () => {
            const q = search.value.trim().toLowerCase();
            let shown = 0;
            panels.forEach(it => {
                const hit = !q || it.label.toLowerCase().includes(q) || it.panel.textContent.toLowerCase().includes(q)
                    || Array.from(it.panel.querySelectorAll('input, select, textarea')).some(el => (el.value || '').toLowerCase().includes(q) || (el.placeholder || '').toLowerCase().includes(q));
                it.panel.classList.toggle('sv-hidden', !hit);
                if (it.link) it.link.classList.toggle('sv-dim', !hit);
                if (hit) shown++;
            });
            none.hidden = shown > 0;
        });

        // ---- the index follows the scroll ----
        let spy = null;
        function watch(on) {
            if (spy) { spy.disconnect(); spy = null; }
            if (!on || !('IntersectionObserver' in window)) return;
            spy = new IntersectionObserver(entries => {
                entries.forEach(en => {
                    if (!en.isIntersecting) return;
                    items.forEach(it => { if (it.link) it.link.classList.toggle('sv-here', it.panel === en.target); });
                });
            }, { rootMargin: '0px 0px -70% 0px' });
            panels.forEach(it => spy.observe(it.panel));
        }

        /** Open every tab once, so each loads whatever it loads when opened; then return to the one that was open. */
        function warm() {
            if (warmed) return;
            warmed = true;
            const wasActive = items.find(it => it.btn.classList.contains('active'));
            const url = location.href;
            panels.forEach(it => { try { it.btn.click(); } catch (e) { /* a tab that fails to open must not stop the rest */ } });
            if (wasActive) { try { wasActive.btn.click(); } catch (e) { /* ignore */ } }
            if (location.href !== url) history.replaceState(history.state, '', url);   // the tab switches may have rewritten ?tab=
        }

        function setMode(next, save) {
            if (phone.matches) next = 'tabs';
            mode = next;
            sw.querySelectorAll('button').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.view === mode)));
            if (mode === 'page') warm();
            document.body.classList.toggle('sv-page', mode === 'page');
            // The switch sits at the top of the index in One page, beside the tab bar in Tabs.
            if (mode === 'page') index.insertBefore(sw, index.firstChild); else bar.insertAdjacentElement('beforebegin', sw);
            watch(mode === 'page');
            if (mode === 'page') {
                // Arrived on ?tab=x (a link from elsewhere): go to that section.
                const want = new URLSearchParams(location.search).get('tab') || location.hash.replace('#', '');
                const it = want && panels.find(p => p.id === want || p.panel.id === want);
                if (it && !save) setTimeout(() => goTo(it.panel, false), 50);
            } else {
                search.value = '';
                panels.forEach(it => it.panel.classList.remove('sv-hidden'));
            }
            if (save && cfg.save) {
                fetch(cfg.save, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ key: 'settings_view', value: mode === 'page' ? 'page' : '' }) }).catch(() => {});
            }
        }
        phone.addEventListener && phone.addEventListener('change', () => setMode(mode, false));
        setMode(mode, false);
    }

    // After the page's own start-up code: its DOMContentLoaded handlers were registered first.
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => setTimeout(start, 0));
    else setTimeout(start, 0);
})();
