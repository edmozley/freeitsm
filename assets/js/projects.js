/**
 * Projects module (3.2.0) - helpers every Projects page shares.
 *
 * window.Prj: api(), esc(), T(), the renderers (icon, health ring, status pill,
 * date phrases), the create / edit project dialog, and celebrate() - so the
 * portfolio and the project page draw a project the same way.
 *
 * Every URL is built from window.PRJ_API (set by the page from BASE_URL) -
 * never a root-relative "/api/...", which 404s on an install in a sub-directory.
 */
(function () {
    'use strict';

    const T = (k, p) => (window.t ? window.t('projects.' + k, p) : k);
    const TC = (k, p) => (window.t ? window.t('common.' + k, p) : k);

    function esc(v) {
        if (v === null || v === undefined) return '';
        return String(v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    async function api(path, body) {
        const opts = body === undefined ? {} : { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) };
        let res;
        try {
            res = await fetch(window.PRJ_API + path, opts);
        } catch (e) {
            throw new Error('The server could not be reached.');
        }
        let data;
        try { data = await res.json(); } catch (e) { throw new Error('The server sent back something unexpected.'); }
        if (!data.success) throw new Error(data.error || 'Something went wrong.');
        return data;
    }

    // ---- Lookups (loaded once per page) --------------------------------------
    let lookupsPromise = null;
    function lookups() {
        if (!lookupsPromise) lookupsPromise = api('lookups.php');
        return lookupsPromise;
    }

    // ---- Icons: keys are stored, markup lives only here ---------------------
    const ICONS = {
        rocket:   '<path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="M12 15l-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/>',
        laptop:   '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M2 20h20"/>',
        building: '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01M16 6h.01M12 6h.01M12 10h.01M12 14h.01M16 10h.01M16 14h.01M8 10h.01M8 14h.01"/>',
        mail:     '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>',
        server:   '<rect x="2" y="3" width="20" height="8" rx="2"/><rect x="2" y="13" width="20" height="8" rx="2"/><path d="M6 7h.01M6 17h.01"/>',
        shield:   '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
        network:  '<rect x="9" y="2" width="6" height="6" rx="1"/><rect x="2" y="16" width="6" height="6" rx="1"/><rect x="16" y="16" width="6" height="6" rx="1"/><path d="M5 16v-3h14v3M12 8v5"/>',
        cloud:    '<path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9z"/>',
        users:    '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        flag:     '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><path d="M4 22v-7"/>',
        wrench:   '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94z"/>',
        box:      '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.27 6.96 12 12.01l8.73-5.05M12 22.08V12"/>',
        phone:    '<rect x="6" y="2" width="12" height="20" rx="2"/><path d="M11 18h2"/>',
        database: '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>',
        star:     '<path d="m12 2 3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>',
        heart:    '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>',
    };
    function icon(key, size) {
        const s = size || 24;
        return '<svg width="' + s + '" height="' + s + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (ICONS[key] || ICONS.rocket) + '</svg>';
    }

    // ---- Colours: the palette comes from the server; a fallback for first paint
    let palette = { coral: ['#f43f5e', '#e11d48'] };
    function gradient(key) {
        const c = palette[key] || palette.coral || ['#f43f5e', '#e11d48'];
        return 'linear-gradient(135deg, ' + c[0] + ', ' + c[1] + ')';
    }
    function setPalette(list) {
        palette = {};
        (list || []).forEach(c => { palette[c.key] = [c.from, c.to]; });
    }

    // ---- Health ring ----------------------------------------------------------
    /**
     * A ring whose arc is the progress and whose colour is the health. The
     * percentage sits inside. Finished projects draw a full, calm ring.
     */
    function ring(progress, health, size, onDark) {
        const s = size || 64, sw = Math.max(5, Math.round(s / 10)), r = (s - sw) / 2, c = 2 * Math.PI * r;
        const pct = Math.max(0, Math.min(100, progress || 0));
        const cls = health ? 'h-' + health : 'h-none';
        const label = health ? T('health.' + health) : T('health.none');
        return '<span class="prj-ring ' + cls + (onDark ? ' on-dark' : '') + '" style="width:' + s + 'px;height:' + s + 'px" title="' + esc(label + ' - ' + pct + '%') + '" role="img" aria-label="' + esc(label + ', ' + pct + '%') + '">'
            + '<svg width="' + s + '" height="' + s + '" viewBox="0 0 ' + s + ' ' + s + '">'
            + '<circle class="prj-ring-track" cx="' + s / 2 + '" cy="' + s / 2 + '" r="' + r + '" stroke-width="' + sw + '" fill="none"/>'
            + '<circle class="prj-ring-arc" cx="' + s / 2 + '" cy="' + s / 2 + '" r="' + r + '" stroke-width="' + sw + '" fill="none" stroke-linecap="round"'
            + ' stroke-dasharray="' + c.toFixed(2) + '" stroke-dashoffset="' + (c * (1 - pct / 100)).toFixed(2) + '" transform="rotate(-90 ' + s / 2 + ' ' + s / 2 + ')"/>'
            + '</svg><span class="prj-ring-pct">' + pct + '<small>%</small></span></span>';
    }

    function statusPill(status) {
        return '<span class="prj-pill s-' + esc(status) + '">' + esc(T('status.' + status)) + '</span>';
    }

    function healthBadge(health) {
        if (!health) return '';
        return '<span class="prj-health-badge h-' + esc(health) + '"><span class="dot"></span>' + esc(T('health.' + health)) + '</span>';
    }

    // ---- Dates ------------------------------------------------------------------
    function fmtDate(d) {
        if (!d) return '';
        return window.fmtNaiveDate ? window.fmtNaiveDate(String(d).slice(0, 10) + 'T00:00:00') : String(d).slice(0, 10);
    }
    function todayStr() {
        const n = new Date();
        return n.getFullYear() + '-' + String(n.getMonth() + 1).padStart(2, '0') + '-' + String(n.getDate()).padStart(2, '0');
    }
    /** Whole days from today to a Y-m-d (negative = in the past). */
    function daysTo(d) {
        if (!d) return null;
        const a = new Date(todayStr() + 'T00:00:00'), b = new Date(String(d).slice(0, 10) + 'T00:00:00');
        return Math.round((b - a) / 86400000);
    }
    /** "Due in 12 days" / "3 days late" / "Finished 4 Mar" for a project. */
    function targetPhrase(p) {
        if (p.status === 'closed' && p.actual_end_date) return { text: T('portfolio.finished_on', { date: fmtDate(p.actual_end_date) }), cls: 'done' };
        if (!p.target_end_date) return { text: T('portfolio.no_target'), cls: 'none' };
        if (p.status === 'closed' || p.status === 'cancelled') return { text: fmtDate(p.target_end_date), cls: 'none' };
        const d = daysTo(p.target_end_date);
        if (d < 0) return { text: T('portfolio.overdue_by', { days: -d }), cls: 'late' };
        if (d === 0) return { text: T('portfolio.due_today'), cls: 'soon' };
        if (d === 1) return { text: T('portfolio.due_tomorrow'), cls: 'soon' };
        return { text: T('portfolio.due_in', { days: d }), cls: d <= 14 ? 'soon' : 'ok' };
    }

    function initials(name) {
        if (!name) return '?';
        const parts = String(name).trim().split(/\s+/);
        return ((parts[0] || '')[0] || '').toUpperCase() + ((parts.length > 1 ? parts[parts.length - 1][0] : '') || '').toUpperCase();
    }

    function timeboxWord(kind, plural) {
        return T('timebox.' + (kind || 'phase') + (plural ? 's' : '')).toLowerCase();
    }

    function toast(msg, type) {
        if (typeof window.showToast === 'function') window.showToast(msg, type || 'success');
    }

    // ---- Modals -------------------------------------------------------------------
    function openModal(id) {
        const m = document.getElementById(id);
        if (!m) return;
        m.classList.add('active');
        m.setAttribute('aria-hidden', 'false');
    }
    function closeModal(id) {
        const m = document.getElementById(id);
        if (!m) return;
        m.classList.remove('active');
        m.setAttribute('aria-hidden', 'true');
    }
    document.addEventListener('click', e => {
        const c = e.target.closest('[data-prj-close]');
        if (c) closeModal(c.getAttribute('data-prj-close'));
        // Click on the dimmed backdrop closes too.
        if (e.target.classList && e.target.classList.contains('modal') && e.target.id && e.target.id.indexOf('prj') === 0) closeModal(e.target.id);
    });
    document.addEventListener('keydown', e => {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('.modal.active[id^="prj"]').forEach(m => closeModal(m.id));
    });

    // ---- The project dialog ---------------------------------------------------------
    let formState = { colour: 'coral', icon: 'rocket', method: 'simple', origMethod: null, onSaved: null };

    function paintBanner() {
        const b = document.getElementById('pfBanner');
        if (!b) return;
        b.style.background = gradient(formState.colour);
        document.getElementById('pfBannerIcon').innerHTML = icon(formState.icon, 26);
        const n = document.getElementById('pfName').value.trim();
        document.getElementById('pfBannerName').textContent = n;
        // The chosen icon wears the chosen colour, so the dialog previews the card.
        document.querySelectorAll('#pfIcons .prj-icon-choice').forEach(el => {
            el.style.background = el.classList.contains('selected') ? gradient(formState.colour) : '';
        });
    }

    function pick(containerId, attr, value) {
        document.querySelectorAll('#' + containerId + ' [' + attr + ']').forEach(el => {
            const on = el.getAttribute(attr) === value;
            el.classList.toggle('selected', on);
            el.setAttribute('aria-checked', on ? 'true' : 'false');
        });
    }

    async function openProjectForm(project, onSaved) {
        const L = await lookups();
        setPalette(L.colours);
        const isEdit = !!(project && project.id);
        formState = {
            colour: (project && project.colour) || L.colours[Math.floor(Math.random() * L.colours.length)].key,
            icon: (project && project.icon) || 'rocket',
            method: (project && project.methodology) || L.default_method || 'simple',
            origMethod: isEdit ? project.methodology : null,
            onSaved: onSaved,
        };
        const $ = id => document.getElementById(id);
        $('pfTitle').textContent = isEdit ? T('form.edit_title') : T('form.new_title');
        $('pfId').value = isEdit ? project.id : '';
        $('pfName').value = isEdit ? (project.name || '') : '';
        $('pfGoal').value = isEdit ? (project.goal || '') : '';
        $('pfSummary').value = isEdit ? (project.summary || '') : '';
        $('pfStart').value = isEdit ? (project.start_date || '') : '';
        $('pfTarget').value = isEdit ? (project.target_end_date || '') : '';
        $('pfActual').value = isEdit ? (project.actual_end_date || '') : '';
        $('pfHealthNote').value = isEdit ? (project.health_note || '') : '';
        $('pfError').hidden = true;
        $('pfSave').textContent = isEdit ? TC('save') : T('form.create');
        document.querySelectorAll('#prjFormModal .prj-edit-only').forEach(el => { el.hidden = !isEdit; });

        $('pfOwner').innerHTML = '<option value="">' + esc(T('portfolio.nobody')) + '</option>'
            + L.analysts.map(a => '<option value="' + a.id + '">' + esc(a.full_name) + '</option>').join('');
        $('pfOwner').value = isEdit ? (project.owner_analyst_id || '') : String(window.PRJ_ME || '');

        const cw = $('pfCompanyWrap');
        if (L.multi_company && L.companies.length > 1 && !isEdit) {
            cw.hidden = false;
            $('pfCompany').innerHTML = L.companies.map(c => '<option value="' + c.id + '">' + esc(c.name) + '</option>').join('');
            if (L.active_company) $('pfCompany').value = String(L.active_company);
        } else {
            cw.hidden = true;
        }

        $('pfStatus').innerHTML = L.statuses.map(s => '<option value="' + s + '">' + esc(T('status.' + s)) + '</option>').join('');
        $('pfStatus').value = isEdit ? project.status : 'proposed';
        $('pfHealth').innerHTML = ['auto', 'green', 'amber', 'red'].map(h => '<option value="' + h + '">' + esc(T('health.' + h)) + '</option>').join('');
        $('pfHealth').value = isEdit ? project.health : 'auto';
        $('pfHealthNoteWrap').hidden = !isEdit || $('pfHealth').value === 'auto';

        $('pfMethods').innerHTML = L.methodologies.map(m =>
            '<button type="button" class="prj-method-card" role="radio" data-method="' + esc(m.key) + '">'
            + '<span class="prj-method-name">' + esc(m.label) + '</span>'
            + '<span class="prj-method-desc">' + esc(m.description) + '</span></button>').join('');
        pick('pfMethods', 'data-method', formState.method);
        $('pfMethodNote').hidden = !isEdit;

        // Tools (edit only): what this project uses, against its method's defaults.
        const on = isEdit ? (project.tools || []) : [];
        $('pfTools').innerHTML = Object.keys(L.tools || {}).map(k =>
            '<label class="prj-tool-check"><input type="checkbox" data-tool-key="' + esc(k) + '"' + (on.includes(k) ? ' checked' : '') + '>'
            + '<span><strong>' + esc(T('tools.' + k)) + '</strong><small>' + esc(T('tools.' + k + '_desc')) + '</small></span></label>').join('');

        $('pfColours').innerHTML = L.colours.map(c =>
            '<button type="button" class="prj-swatch" role="radio" data-colour="' + esc(c.key) + '" title="' + esc(c.key) + '" aria-label="' + esc(c.key) + '" style="background:linear-gradient(135deg,' + esc(c.from) + ',' + esc(c.to) + ')"></button>').join('');
        pick('pfColours', 'data-colour', formState.colour);
        $('pfIcons').innerHTML = L.icons.map(k =>
            '<button type="button" class="prj-icon-choice" role="radio" data-icon="' + esc(k) + '" title="' + esc(k) + '" aria-label="' + esc(k) + '">' + icon(k, 20) + '</button>').join('');
        pick('pfIcons', 'data-icon', formState.icon);

        paintBanner();
        openModal('prjFormModal');
        setTimeout(() => $('pfName').focus(), 60);
    }

    function wireForm() {
        const modal = document.getElementById('prjFormModal');
        if (!modal || modal.dataset.wired) return;
        modal.dataset.wired = '1';
        const $ = id => document.getElementById(id);
        $('pfMethods').addEventListener('click', e => {
            const b = e.target.closest('[data-method]'); if (!b) return;
            formState.method = b.getAttribute('data-method'); pick('pfMethods', 'data-method', formState.method);
        });
        $('pfColours').addEventListener('click', e => {
            const b = e.target.closest('[data-colour]'); if (!b) return;
            formState.colour = b.getAttribute('data-colour'); pick('pfColours', 'data-colour', formState.colour); paintBanner();
        });
        $('pfIcons').addEventListener('click', e => {
            const b = e.target.closest('[data-icon]'); if (!b) return;
            formState.icon = b.getAttribute('data-icon'); pick('pfIcons', 'data-icon', formState.icon); paintBanner();
        });
        $('pfName').addEventListener('input', paintBanner);
        $('pfHealth').addEventListener('change', () => { $('pfHealthNoteWrap').hidden = $('pfHealth').value === 'auto'; });
        $('pfName').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); $('pfSave').click(); } });
        $('pfSave').addEventListener('click', async () => {
            const id = $('pfId').value;
            const body = {
                name: $('pfName').value.trim(),
                goal: $('pfGoal').value.trim(),
                summary: $('pfSummary').value.trim(),
                methodology: formState.method,
                owner_analyst_id: $('pfOwner').value || null,
                start_date: $('pfStart').value || null,
                target_end_date: $('pfTarget').value || null,
                colour: formState.colour,
                icon: formState.icon,
            };
            if (!body.name) { showFormError(T('form.name_required')); $('pfName').focus(); return; }
            if (id) {
                body.id = parseInt(id, 10);
                body.status = $('pfStatus').value;
                body.actual_end_date = $('pfActual').value || null;
                body.health = $('pfHealth').value;
                body.health_note = body.health === 'auto' ? null : ($('pfHealthNote').value.trim() || null);
                const tail = {};
                document.querySelectorAll('#pfTools [data-tool-key]').forEach(c => { tail[c.dataset.toolKey] = c.checked; });
                // A method change resets the tools to the new method's own set.
                if (formState.method === formState.origMethod) body.tailoring = tail;
                else body.tailoring = null;
            } else if (!$('pfCompanyWrap').hidden) {
                body.company_id = $('pfCompany').value;
            }
            $('pfSave').disabled = true;
            try {
                const r = await api('save.php', body);
                closeModal('prjFormModal');
                toast(id ? T('form.saved') : T('form.created'));
                if (typeof formState.onSaved === 'function') formState.onSaved(r.id, !id, body);
            } catch (err) {
                showFormError(err.message);
            } finally {
                $('pfSave').disabled = false;
            }
        });
    }
    function showFormError(msg) {
        const e = document.getElementById('pfError');
        e.textContent = msg; e.hidden = false;
    }

    // ---- Celebrate: a small, tasteful burst ----------------------------------------
    /**
     * Confetti from an element, for the moments worth marking (a stage done, a
     * project closed). Pure CSS transforms on a handful of dots, removed after
     * ~1.2s. Respects prefers-reduced-motion by showing nothing but the toast.
     */
    function celebrate(fromEl) {
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        const r = fromEl ? fromEl.getBoundingClientRect() : { left: window.innerWidth / 2, top: window.innerHeight / 3, width: 0, height: 0 };
        const cx = r.left + r.width / 2, cy = r.top + r.height / 2;
        const colours = ['#f43f5e', '#fb923c', '#f59e0b', '#84cc16', '#14b8a6', '#38bdf8', '#a78bfa', '#f472b6'];
        const layer = document.createElement('div');
        layer.className = 'prj-confetti';
        for (let i = 0; i < 28; i++) {
            const d = document.createElement('i');
            const angle = (Math.PI * 2 * i) / 28 + Math.random() * 0.3;
            const dist = 60 + Math.random() * 90;
            d.style.left = cx + 'px';
            d.style.top = cy + 'px';
            d.style.background = colours[i % colours.length];
            d.style.setProperty('--dx', Math.cos(angle) * dist + 'px');
            d.style.setProperty('--dy', Math.sin(angle) * dist - 40 + 'px');
            d.style.setProperty('--rot', (Math.random() * 540 - 270) + 'deg');
            d.style.animationDelay = (Math.random() * 60) + 'ms';
            layer.appendChild(d);
        }
        document.body.appendChild(layer);
        setTimeout(() => layer.remove(), 1400);
    }

    document.addEventListener('DOMContentLoaded', wireForm);

    window.Prj = {
        T, TC, esc, api, lookups, icon, gradient, setPalette, ring, statusPill, healthBadge,
        fmtDate, daysTo, todayStr, targetPhrase, initials, timeboxWord, toast,
        openModal, closeModal, openProjectForm, celebrate,
    };
})();
