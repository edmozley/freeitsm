/**
 * CMDB configuration item preview (GH #313 slice 7) - shows the configuration items
 * the CMDB assistant suggests creating, grouped by class, and lets the analyst untick
 * any before applying. The same look as the schema preview (cmdb-schema-preview.js).
 *
 *   CmdbObjectPreview.open({
 *       resolved,          // cmdbAssistObjectsResolve() output (api/cmdb/assistant.php ?action=preview)
 *       onApply,           // async (selection: string[]) => any; throw to keep the dialog open
 *       title, intro       // optional heading and lead text
 *   });
 *
 * A tree: each class, its configuration items (New / Existing / Skipped with the
 * reason, a tick for a new one), and under each its property values, its parent and
 * its relationships (each with its own tick). A banner names the company they will
 * be created in - always the analyst's active company. Unticking an item unticks and
 * disables everything that needs it (a child, a reference to it, its relationships) -
 * the server enforces the same.
 *
 * Needs window.t (i18n.js) and, for errors, showToast (toast.js). Uses the shared
 * .modal / .modal-content / .modal-header / .modal-body / .modal-footer look.
 *
 * @wiki CMDB-Developer-Guide
 */
(function () {
    'use strict';

    /** Translates a key under cmdb.assistant.* (or cmdb.plan.* with a 'plan.' prefix). */
    const T = (k, p) => (typeof window.t === 'function' ? window.t(k.indexOf('plan.') === 0 ? 'cmdb.' + k : 'cmdb.assistant.' + k, p) : k);
    /** Escapes text for HTML, quotes included. */
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

    const CSS = `
.cop-modal .modal-content { width: 820px; max-width: 95vw; }
.cop-intro { margin: 0 0 14px; color: var(--text-muted,#666); font-size: 13px; }
.cop-banner { margin: 0 0 14px; padding: 10px 12px; border-radius: 6px; font-size: 13px; font-weight: 600;
  background: var(--info-bg,#e0f2fe); color: var(--info-text,#075985); border: 1px solid var(--info-border,#b9def5); }
.cop-h { font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; color: var(--text-muted,#666); margin: 0 0 8px; }
.cop-class { border: 1px solid var(--border,#e0e0e0); border-radius: 6px; padding: 6px 12px; margin-bottom: 10px; background: var(--surface,#fff); }
.cop-class-h { font-weight: 600; font-size: 14px; padding: 4px 0; color: var(--text,#333); }
.cop-line { display: flex; align-items: flex-start; gap: 10px; padding: 6px 0; font-size: 14px; color: var(--text,#333); }
.cop-line input[type=checkbox] { margin-top: 3px; flex: none; width: auto; }
.cop-line .cop-nobox { width: 13px; flex: none; }
.cop-line.cop-off .cop-main { text-decoration: line-through; color: var(--text-dim,#888); }
.cop-main { font-weight: 500; }
.cop-reason { display: block; font-size: 12px; color: var(--text-muted,#666); font-weight: 400; }
.cop-why { display: block; font-size: 12px; color: var(--text-dim,#888); }
.cop-obj { margin-left: 12px; }
.cop-sub { margin-left: 23px; border-left: 2px solid var(--border-soft,#eee); padding-left: 12px; margin-bottom: 4px; }
.cop-prop { font-size: 13px; padding: 2px 0; color: var(--text,#333); }
.cop-prop b { font-weight: 500; color: var(--text-muted,#666); }
.cop-prop .cop-reason { display: inline; margin-left: 6px; }
.cop-badge { display: inline-block; font-size: 11px; font-weight: 600; padding: 1px 8px; border-radius: 10px; margin-left: 6px; vertical-align: 1px; }
.cop-badge.new { background: var(--success-bg,#dcfce7); color: var(--success-text,#166534); }
.cop-badge.existing { background: var(--info-bg,#e0f2fe); color: var(--info-text,#075985); }
.cop-badge.skipped { background: var(--warning-bg,#fef3c7); color: var(--warning-text,#92400e); }
.cop-chip { display: inline-block; font-size: 12px; padding: 1px 8px; border-radius: 10px; margin-left: 6px;
  background: var(--surface-2,#fafafa); border: 1px solid var(--border,#e0e0e0); color: var(--text-muted,#666); }
.cop-summary { margin-right: auto; align-self: center; font-size: 13px; color: var(--text-muted,#666); }
`;

    /** Adds the dialog's styles once. */
    function injectCss() {
        if (document.getElementById('cmdbObjectPreviewCss')) return;
        const s = document.createElement('style');
        s.id = 'cmdbObjectPreviewCss';
        s.textContent = CSS;
        document.head.appendChild(s);
    }

    /** A line's badge: New, Existing or Skipped. */
    function badge(action) {
        if (action === 'create') return `<span class="cop-badge new">${esc(T('plan.badge_new'))}</span>`;
        if (action === 'exists') return `<span class="cop-badge existing">${esc(T('plan.badge_existing'))}</span>`;
        return `<span class="cop-badge skipped">${esc(T('plan.badge_skipped'))}</span>`;
    }
    /** One tickable (or not) line: a checkbox for a new one, the text, a badge and the reason. */
    function lineHtml(l, inner, extra) {
        const box = l.action === 'create'
            ? `<input type="checkbox" checked data-line="${esc(l.line)}" data-deps="${esc((l.depends_on || []).join(' '))}">`
            : '<span class="cop-nobox"></span>';
        return `<label class="cop-line${l.action === 'invalid' ? ' cop-off' : ''}">${box}<span><span class="cop-main">${inner}</span>${badge(l.action)}`
            + (l.reason ? `<span class="cop-reason">${esc(l.reason)}</span>` : '') + (extra || '') + '</span></label>';
    }

    /** The dialog body: the banner, then each class with its items. */
    function render(o) {
        const r = o.resolved || {};
        let h = '';
        if (o.intro) h += `<p class="cop-intro">${esc(o.intro)}</p>`;
        if (r.company && r.company.name) h += `<div class="cop-banner">${esc(T('op_banner', { company: r.company.name }))}</div>`;
        const groups = [];
        const byClass = {};
        (r.objects || []).forEach(ob => {
            const k = ob.class_key || '?';
            if (!byClass[k]) { byClass[k] = { name: ob.class_name || k, items: [] }; groups.push(byClass[k]); }
            byClass[k].items.push(ob);
        });
        if (groups.length) h += `<div class="cop-h">${esc(T('op_heading'))}</div>`;
        groups.forEach(g => {
            h += `<div class="cop-class"><div class="cop-class-h">${esc(g.name)}</div>`;
            g.items.forEach(ob => {
                const why = ob.description ? `<span class="cop-why">${esc(ob.description)}</span>` : '';
                h += '<div class="cop-obj">' + lineHtml(ob, esc(ob.name), why);
                let sub = '';
                if (ob.parent && ob.parent.name) sub += `<div class="cop-prop">${esc(T('op_under', { name: ob.parent.name }))}</div>`;
                (ob.properties || []).forEach(p => {
                    sub += `<div class="cop-prop"><b>${esc(p.label)}:</b> ${p.store === null && !p.ref_line ? '<em>' + esc(T('op_empty')) + '</em>' : esc(p.value)}`
                        + `<span class="cop-chip">${esc(p.type_label || p.type)}</span>`
                        + (p.note ? `<span class="cop-reason">${esc(p.note)}</span>` : '') + '</div>';
                });
                (ob.relationships || []).forEach(rl => { sub += lineHtml(rl, esc(rl.sentence || (ob.name + ' ' + rl.verb + ' ' + rl.to))); });
                if (sub) h += `<div class="cop-sub">${sub}</div>`;
                h += '</div>';
            });
            h += '</div>';
        });
        return h;
    }

    /** Untick + disable any line whose item is unticked (chains too); refresh the summary. */
    function sync(modal) {
        const boxes = [...modal.querySelectorAll('input[type=checkbox][data-line]')];
        const byLine = {};
        boxes.forEach(b => { byLine[b.dataset.line] = b; });
        for (let i = 0; i < 30; i++) {
            let changed = false;
            boxes.forEach(b => {
                const deps = (b.dataset.deps || '').split(' ').filter(Boolean);
                const blocked = deps.some(d => byLine[d] && !byLine[d].checked);
                if (blocked && !b.disabled) { b.dataset.was = b.checked ? '1' : '0'; b.checked = false; b.disabled = true; changed = true; }
                else if (!blocked && b.disabled) { b.disabled = false; b.checked = b.dataset.was !== '0'; changed = true; }
                b.closest('.cop-line').classList.toggle('cop-off', b.disabled);
            });
            if (!changed) break;
        }
        const n = boxes.filter(b => b.checked).length;
        const apply = modal.querySelector('[data-cop=apply]');
        if (apply && !apply.dataset.busy) apply.disabled = n === 0;
        const c = (modal._resolved && modal._resolved.counts) || {};
        const sum = modal.querySelector('.cop-summary');
        if (sum) sum.textContent = boxes.length === 0 ? T('op_nothing') : T('op_summary', { create: n, exists: c.exists || 0, skipped: c.invalid || 0 });
    }

    /** Closes and removes the dialog. */
    function close(modal) {
        modal.classList.remove('active');
        document.removeEventListener('keydown', modal._onKey);
        setTimeout(() => modal.remove(), 300);
    }

    /** Opens the preview. Returns the modal element. */
    function open(o) {
        injectCss();
        const modal = document.createElement('div');
        modal.className = 'modal cop-modal';
        modal.id = 'cmdbObjectPreview';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal._resolved = o.resolved || {};
        modal.innerHTML = `<div class="modal-content">
            <div class="modal-header">${esc(o.title || (o.resolved && o.resolved.title) || '')}</div>
            <div class="modal-body">${render(o)}</div>
            <div class="modal-footer">
                <span class="cop-summary"></span>
                <button type="button" class="btn btn-secondary" data-cop="cancel">${esc(T('plan.cancel'))}</button>
                <button type="button" class="btn btn-primary" data-cop="apply">${esc(T('plan.apply'))}</button>
            </div></div>`;
        document.body.appendChild(modal);
        modal.addEventListener('change', e => { if (e.target.matches('input[type=checkbox]')) sync(modal); });
        modal.querySelector('[data-cop=cancel]').addEventListener('click', () => close(modal));
        modal._onKey = e => { if (e.key === 'Escape') close(modal); };
        document.addEventListener('keydown', modal._onKey);

        const apply = modal.querySelector('[data-cop=apply]');
        apply.addEventListener('click', async () => {
            const selection = [...modal.querySelectorAll('input[type=checkbox][data-line]:checked')].map(b => b.dataset.line);
            if (!selection.length) { if (typeof showToast === 'function') showToast(T('op_none_selected'), 'error'); return; }
            apply.dataset.busy = '1';
            apply.disabled = true;
            apply.textContent = T('op_applying');
            try {
                await o.onApply(selection);
                close(modal);
            } catch (err) {
                if (typeof showToast === 'function') showToast(T('op_apply_failed', { message: err && err.message ? err.message : String(err) }), 'error');
                delete apply.dataset.busy;
                apply.textContent = T('plan.apply');
                sync(modal);
            }
        });
        sync(modal);
        // Force a layout first so the fade still plays, without waiting on a frame
        // that a background tab (or headless Chrome) may never paint.
        void modal.offsetWidth;
        modal.classList.add('active');
        return modal;
    }

    window.CmdbObjectPreview = { open, close };
})();
