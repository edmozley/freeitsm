/**
 * CMDB schema plan preview (GH #313) - shows what a schema plan would add, line by
 * line, and lets the analyst untick anything before applying it.
 *
 *   CmdbSchemaPreview.open({
 *       resolved,          // cmdbPlanResolve() output (api/cmdb/schema_plan.php GET)
 *       onApply,           // async (selection: string[]) => any; throw to keep the dialog open
 *       title, intro,      // optional heading and lead text
 *       multiCompany,      // true: show "Classes are shared by all companies"
 *       canRelTypes        // false: relationship-type lines cannot be ticked
 *   });
 *
 * Used by Starter classes on CMDB -> Settings -> Classes and meant for the CMDB AI
 * assistant later, so it knows nothing about where the plan came from.
 *
 * A tree: relationship types as a sentence, then each class (New / Existing badge)
 * with its properties and their type chips. Lines that will not be created (already
 * there, a conflict, refused) carry the reason; skipped ones are struck through.
 * Unticking a new class unticks and disables every line that needs it (its
 * properties, and object references pointing at it) - the server enforces the same.
 *
 * Needs window.t (i18n.js) and, for errors, showToast (toast.js). Uses the shared
 * .modal / .modal-content / .modal-header / .modal-body / .modal-footer look.
 */
(function () {
    'use strict';

    const T = (k, p) => (typeof window.t === 'function' ? window.t('cmdb.plan.' + k, p) : k);
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

    const CSS = `
.csp-modal .modal-content { width: 820px; max-width: 95vw; }
.csp-intro { margin: 0 0 14px; color: var(--text-muted,#666); font-size: 13px; }
.csp-banner { margin: 0 0 14px; padding: 10px 12px; border-radius: 6px; font-size: 13px;
  background: var(--info-bg,#e0f2fe); color: var(--info-text,#075985); border: 1px solid var(--info-border,#b9def5); }
.csp-h { font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: .03em;
  color: var(--text-muted,#666); margin: 18px 0 8px; }
.csp-h:first-child { margin-top: 0; }
.csp-line { display: flex; align-items: flex-start; gap: 10px; padding: 6px 0; font-size: 14px; color: var(--text,#333); }
.csp-line input[type=checkbox] { margin-top: 3px; flex: none; width: auto; }
.csp-line .csp-nobox { width: 13px; flex: none; }
.csp-line.csp-off .csp-main { text-decoration: line-through; color: var(--text-dim,#888); }
.csp-main { font-weight: 500; }
.csp-reason { display: block; font-size: 12px; color: var(--text-muted,#666); font-weight: 400; text-decoration: none; }
.csp-why { display: block; font-size: 12px; color: var(--text-dim,#888); }
.csp-class { border: 1px solid var(--border,#e0e0e0); border-radius: 6px; padding: 6px 12px; margin-bottom: 10px; background: var(--surface,#fff); }
.csp-class > .csp-line { font-weight: 600; }
.csp-props { margin-left: 23px; border-left: 2px solid var(--border-soft,#eee); padding-left: 12px; }
.csp-badge { display: inline-block; font-size: 11px; font-weight: 600; padding: 1px 8px; border-radius: 10px; margin-left: 6px; vertical-align: 1px; text-decoration: none; }
.csp-badge.new { background: var(--success-bg,#dcfce7); color: var(--success-text,#166534); }
.csp-badge.existing { background: var(--info-bg,#e0f2fe); color: var(--info-text,#075985); }
.csp-badge.skipped { background: var(--warning-bg,#fef3c7); color: var(--warning-text,#92400e); }
.csp-chip { display: inline-block; font-size: 12px; padding: 1px 8px; border-radius: 10px; margin-left: 6px;
  background: var(--surface-2,#fafafa); border: 1px solid var(--border,#e0e0e0); color: var(--text-muted,#666); }
.csp-chip.dep { border-color: var(--cmdb-accent,#be185d); color: var(--cmdb-accent,#be185d); }
.csp-usage { margin: 0; padding-left: 18px; font-size: 13px; color: var(--text-muted,#666); }
.csp-note { font-size: 12px; color: var(--text-dim,#888); margin: 4px 0 0; }
.csp-summary { margin-right: auto; align-self: center; font-size: 13px; color: var(--text-muted,#666); }
`;

    function injectCss() {
        if (document.getElementById('cmdbSchemaPreviewCss')) return;
        const s = document.createElement('style');
        s.id = 'cmdbSchemaPreviewCss';
        s.textContent = CSS;
        document.head.appendChild(s);
    }

    /** One line: a checkbox (or a spacer), the text, a badge and the reason. */
    function lineHtml(l, inner, opts) {
        const creatable = l.action === 'create' && opts.allowed;
        const off = l.action === 'conflict' || l.action === 'invalid' || (l.action === 'create' && !opts.allowed);
        const badge = opts.badge === false ? '' : (
            l.action === 'create' ? (opts.allowed ? `<span class="csp-badge new">${esc(T('badge_new'))}</span>` : `<span class="csp-badge skipped">${esc(T('badge_skipped'))}</span>`)
            : l.action === 'exists' ? `<span class="csp-badge existing">${esc(T('badge_existing'))}</span>`
            : `<span class="csp-badge skipped">${esc(T('badge_skipped'))}</span>`);
        const reason = !opts.allowed && l.action === 'create' ? opts.deniedReason : l.reason;
        const box = creatable
            ? `<input type="checkbox" checked data-line="${esc(l.line)}" data-deps="${esc((l.depends_on || []).join(' '))}">`
            : '<span class="csp-nobox"></span>';
        return `<label class="csp-line${off ? ' csp-off' : ''}">${box}<span><span class="csp-main">${inner}</span>${badge}`
            + (reason ? `<span class="csp-reason">${esc(reason)}</span>` : '')
            + (l.why ? `<span class="csp-why">${esc(l.why)}</span>` : '')
            + '</span></label>';
    }

    /** A property's type chips: the type, an object reference's target, dropdown options. */
    function chips(p) {
        let out = '';
        if (p.type === 'object_ref') {
            out += `<span class="csp-chip${p.spreads_impact ? ' dep' : ''}">&rarr; ${esc(p.target_name || p.target)}${p.spreads_impact ? ' (' + esc(T('dependency')) + ')' : ''}</span>`;
        } else {
            out += `<span class="csp-chip">${esc(p.type_label || p.type)}</span>`;
        }
        if (p.type === 'dropdown' && p.options && p.options.length) out += `<span class="csp-chip">${esc(p.options.join(' / '))}</span>`;
        if (p.required) out += `<span class="csp-chip">${esc(T('required'))}</span>`;
        return out;
    }

    function render(o) {
        const r = o.resolved || {};
        const canRel = o.canRelTypes !== false;
        const names = {};
        (r.classes || []).forEach(c => { names[c.key] = c.name; });
        let h = '';
        if (o.intro) h += `<p class="csp-intro">${esc(o.intro)}</p>`;
        if (o.multiCompany) h += `<div class="csp-banner">${esc(T('shared_banner'))}</div>`;

        if ((r.relationship_types || []).length) {
            h += `<div class="csp-h">${esc(T('rel_types_heading'))}</div>`;
            r.relationship_types.forEach(rt => {
                const impact = T('impact_' + (rt.impact_direction || 'none'));
                const sentence = T('rel_sentence', { verb: rt.verb, inverse: rt.inverse_verb });
                h += lineHtml(rt, `${esc(sentence)} <span class="csp-chip">${esc(impact)}</span>`,
                    { allowed: canRel, deniedReason: T('reason_no_permission') });
            });
        }

        if ((r.classes || []).length) {
            h += `<div class="csp-h">${esc(T('classes_heading'))}</div>`;
            r.classes.forEach(c => {
                h += '<div class="csp-class">' + lineHtml(c, esc(c.name), { allowed: true });
                if ((c.properties || []).length) {
                    h += '<div class="csp-props">';
                    c.properties.forEach(p => {
                        h += lineHtml(p, esc(p.label) + chips(p), { allowed: true, badge: c.action === 'exists' || p.action !== 'create' });
                    });
                    h += '</div>';
                }
                h += '</div>';
            });
        }

        if ((r.usage || []).length) {
            h += `<div class="csp-h">${esc(T('usage_heading'))}</div><ul class="csp-usage">`;
            r.usage.forEach(u => { h += `<li>${esc(names[u.from] || u.from)} ${esc(u.verb)} ${esc(names[u.to] || u.to)}</li>`; });
            h += `</ul><p class="csp-note">${esc(T('usage_note'))}</p>`;
        }
        return h;
    }

    /** Untick + disable any line whose class or target is unticked; refresh the summary. */
    function sync(modal) {
        const boxes = [...modal.querySelectorAll('input[type=checkbox][data-line]')];
        const byLine = {};
        boxes.forEach(b => { byLine[b.dataset.line] = b; });
        // Two passes is enough: a dependency is only ever a class.
        for (let i = 0; i < 2; i++) {
            boxes.forEach(b => {
                const deps = (b.dataset.deps || '').split(' ').filter(Boolean);
                const blocked = deps.some(d => byLine[d] && !byLine[d].checked);
                if (blocked) { if (!b.disabled) b.dataset.was = b.checked ? '1' : '0'; b.checked = false; b.disabled = true; }
                else if (b.disabled) { b.disabled = false; b.checked = b.dataset.was !== '0'; }
                b.closest('.csp-line').classList.toggle('csp-off', b.disabled);
            });
        }
        const n = boxes.filter(b => b.checked).length;
        const apply = modal.querySelector('[data-csp=apply]');
        if (apply && !apply.dataset.busy) apply.disabled = n === 0;
        const sum = modal.querySelector('.csp-summary');
        const c = (modal._resolved && modal._resolved.counts) || {};
        if (sum) {
            sum.textContent = boxes.length === 0 ? T('nothing_to_add')
                : T('summary', { create: n, exists: c.exists || 0, skipped: (c.conflict || 0) + (c.invalid || 0) });
        }
    }

    function close(modal) {
        modal.classList.remove('active');
        document.removeEventListener('keydown', modal._onKey);
        setTimeout(() => modal.remove(), 300);
    }

    /** Opens the preview. Returns the modal element. */
    function open(o) {
        injectCss();
        const modal = document.createElement('div');
        modal.className = 'modal csp-modal';
        modal.id = 'cmdbSchemaPreview';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal._resolved = o.resolved || {};
        modal.innerHTML = `<div class="modal-content">
            <div class="modal-header">${esc(o.title || (o.resolved && o.resolved.title) || '')}</div>
            <div class="modal-body">${render(o)}</div>
            <div class="modal-footer">
                <span class="csp-summary"></span>
                <button type="button" class="btn btn-secondary" data-csp="cancel">${esc(T('cancel'))}</button>
                <button type="button" class="btn btn-primary" data-csp="apply">${esc(T('apply'))}</button>
            </div></div>`;
        document.body.appendChild(modal);
        modal.addEventListener('change', e => { if (e.target.matches('input[type=checkbox]')) sync(modal); });
        modal.querySelector('[data-csp=cancel]').addEventListener('click', () => close(modal));
        modal._onKey = e => { if (e.key === 'Escape') close(modal); };
        document.addEventListener('keydown', modal._onKey);

        const apply = modal.querySelector('[data-csp=apply]');
        apply.addEventListener('click', async () => {
            const selection = [...modal.querySelectorAll('input[type=checkbox][data-line]:checked')].map(b => b.dataset.line);
            if (!selection.length) { if (typeof showToast === 'function') showToast(T('none_selected'), 'error'); return; }
            apply.dataset.busy = '1';
            apply.disabled = true;
            apply.textContent = T('applying');
            try {
                await o.onApply(selection);
                close(modal);
            } catch (err) {
                if (typeof showToast === 'function') showToast(T('apply_failed', { message: err && err.message ? err.message : String(err) }), 'error');
                delete apply.dataset.busy;
                apply.textContent = T('apply');
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

    window.CmdbSchemaPreview = { open, close };
})();
