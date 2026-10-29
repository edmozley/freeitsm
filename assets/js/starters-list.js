/**
 * Starters & leavers - the cases page (starters/index.php).
 *
 * Loads every case once, then filters by kind, status and search in the browser
 * so the counts beside each view are always the whole picture. A row opens the
 * case; so does Enter on a focused row.
 */
(function () {
    'use strict';
    const { T, esc, api, when, daysFrom, date, kindPill, statusPill, bar } = window.Jml;

    let cases = [];
    let kind = '', status = 'open', q = '';
    const $ = id => document.getElementById(id);

    /** Does a case pass the filters? `ignore` leaves one filter out, for that filter's own counts. */
    function passes(c, ignore) {
        if (ignore !== 'kind' && kind && c.kind !== kind) return false;
        if (ignore !== 'status' && status && c.status !== status) return false;
        if (q) {
            const hay = ((c.person_name || '') + ' ' + (c.person_email || '')).toLowerCase();
            if (!hay.includes(q)) return false;
        }
        return true;
    }

    function row(c) {
        const d = daysFrom(c.effective_date);
        const soon = c.status === 'open' && d !== null && d >= 0 && d <= 7;
        const cells = [
            '<div class="jml-name">' + esc(c.person_name) + '</div>' + (c.person_email ? '<div class="jml-dim">' + esc(c.person_email) + '</div>' : ''),
            kindPill(c.kind),
            esc(date(c.effective_date)) + '<div class="jml-when' + (soon ? ' soon' : '') + '">' + esc(c.status === 'open' ? when(c.effective_date) : '') + '</div>',
            esc(c.template_name || ''),
            bar(c.steps_done || 0, c.steps_total || 0),
            esc(c.owner_name || ''),
        ];
        if (window.JML_MULTI) cells.push(esc(c.company_name || ''));
        cells.push(statusPill(c.status));
        const heads = [...document.querySelectorAll('.jml-table thead th')].map(th => th.textContent);
        return '<tr tabindex="0" data-id="' + c.id + '">' + cells.map((h, i) => '<td data-label="' + esc(heads[i] || '') + '">' + h + '</td>').join('') + '</tr>';
    }

    function draw() {
        const shown = cases.filter(c => passes(c));
        $('jmlEmpty').hidden = cases.length > 0;
        $('jmlTableCard').hidden = cases.length === 0;
        $('jmlRows').innerHTML = shown.map(row).join('');
        $('jmlFilteredEmpty').hidden = shown.length > 0 || cases.length === 0;
        const count = $('jmlCount');
        if (count) count.textContent = shown.length ? String(shown.length) : '';

        document.querySelectorAll('[data-count-kind]').forEach(b => {
            const k = b.dataset.countKind;
            b.textContent = cases.filter(c => passes(c, 'kind') && (!k || c.kind === k)).length || '';
        });
        document.querySelectorAll('[data-count-status]').forEach(b => {
            const s = b.dataset.countStatus;
            b.textContent = cases.filter(c => passes(c, 'status') && (!s || c.status === s)).length || '';
        });
    }

    function choose(listId, attr, set) {
        $(listId).addEventListener('click', e => {
            const li = e.target.closest('li');
            if (!li) return;
            $(listId).querySelectorAll('li').forEach(x => x.classList.toggle('active', x === li));
            set(li.dataset[attr]);
            draw();
        });
    }

    async function load() {
        try {
            cases = (await api('list.php')).cases;
        } catch (e) {
            $('jmlTableCard').hidden = false;
            $('jmlFilteredEmpty').hidden = false;
            $('jmlFilteredEmpty').textContent = T('list.load_failed', { error: e.message });
            return;
        }
        draw();
    }

    document.addEventListener('DOMContentLoaded', () => {
        choose('jmlKinds', 'kind', v => { kind = v; });
        choose('jmlStatuses', 'status', v => { status = v; });
        $('jmlSearch').addEventListener('input', e => { q = e.target.value.trim().toLowerCase(); draw(); });
        $('jmlRows').addEventListener('click', e => {
            const tr = e.target.closest('tr[data-id]');
            if (tr) location.href = 'view.php?id=' + tr.dataset.id;
        });
        $('jmlRows').addEventListener('keydown', e => {
            const tr = e.target.closest('tr[data-id]');
            if (!tr) return;
            if (e.key === 'Enter') location.href = 'view.php?id=' + tr.dataset.id;
            else if (e.key === 'ArrowDown' && tr.nextElementSibling) { e.preventDefault(); tr.nextElementSibling.focus(); }
            else if (e.key === 'ArrowUp' && tr.previousElementSibling) { e.preventDefault(); tr.previousElementSibling.focus(); }
        });
        // ?new=leaver (a workflow's or the People page's link) opens the dialog straight away.
        const want = new URLSearchParams(location.search).get('new');
        if (want) window.JmlNew.open(['starter', 'mover', 'leaver'].includes(want) ? want : 'starter');
        load();
    });
})();
