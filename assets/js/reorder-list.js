/**
 * ReorderList - a list you put in order by dragging, by buttons, or from the
 * keyboard (GH #187). One control for every "choose the order" setting, so the
 * next one is not a fourth copy-pasted drag handler with no keyboard support.
 *
 *   const rl = ReorderList.mount(containerEl, {
 *       items: [{ key: 'tickets', label: 'Tickets', icon: '<svg…>' }, …],   // current order
 *       // an item may carry `html` - the row's own markup (escaped by the caller), e.g. a tick box -
 *       // in place of the plain label; `label` is still its accessible name
 *       onChange: keys => …,      // after every completed move, with the new order
 *       label: 'Module order',    // the list's accessible name
 *   });
 *   rl.getOrder(); rl.setOrder(keys);
 *
 * Three ways to move an item, because each one fails somewhere the others work:
 *   - DRAG by the grip. Native HTML5 drag: on a phone it starts with a long press
 *     on the grip, which needs touch-action:none (Mobile-Friendly-Techniques §22).
 *     Only the grip is draggable, so a swipe elsewhere still scrolls the page.
 *   - The ↑ / ↓ buttons. Work everywhere, including where drag does not.
 *   - Alt+↑ / Alt+↓ on a focused row. Focus stays on the item that moved, and the
 *     new position is announced to screen readers.
 * The list is real DOM order, so reading order and tab order always match what
 * is on screen.
 *
 * Labels come from window.t('common.reorder.*'), with English fallbacks for a
 * page that does not export the 'common' namespace.
 *
 * @wiki Display-Order-Developer-Guide
 */
(function () {
    'use strict';

    const T = (k, p, fallback) => {
        const key = 'common.reorder.' + k;
        const v = typeof window.t === 'function' ? window.t(key, p) : key;
        if (v && v !== key) return v;
        return String(fallback).replace(/\{(\w+)\}/g, (m, n) => (p && p[n] !== undefined ? p[n] : m));
    };
    const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    /** The control's styles, once per page. Theme tokens only (theme.css), so dark mode follows. */
    function injectStyles() {
        if (document.getElementById('reorderListStyles')) return;
        const st = document.createElement('style');
        st.id = 'reorderListStyles';
        st.textContent = [
            '.rl-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:4px;max-width:520px}',
            '.rl-item{display:flex;align-items:center;gap:10px;padding:6px 8px;border:1px solid var(--border,#ddd);border-radius:6px;background:var(--surface,#fff);color:var(--text,#333)}',
            '.rl-item:focus{outline:2px solid var(--accent,#546e7a);outline-offset:1px}',
            '.rl-item.rl-dragging{opacity:.45}',
            '.rl-item.rl-over-top{box-shadow:0 -3px 0 var(--accent,#546e7a)}',
            '.rl-item.rl-over-bottom{box-shadow:0 3px 0 var(--accent,#546e7a)}',
            '.rl-item.rl-moved{background:var(--surface-2,#f7f8fa)}',
            '.rl-grip{cursor:grab;touch-action:none;user-select:none;-webkit-user-select:none;color:var(--text-dim,#888);padding:4px 2px;line-height:1;font-size:var(--font-size-xl, 16px);letter-spacing:-3px}',
            '.rl-pos{min-width:22px;text-align:right;font-size:var(--font-size-sm, 12px);color:var(--text-dim,#888);font-variant-numeric:tabular-nums}',
            '.rl-item .rl-icon{display:inline-flex;width:26px;height:26px;border-radius:6px;align-items:center;justify-content:center;flex-shrink:0;transform:none}',
            '.rl-item .rl-icon svg{width:16px;height:16px}',
            '.rl-label{flex:1;min-width:0;font-size:var(--font-size-lg, 14px);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
            '.rl-note{font-size:var(--font-size-sm, 12px);color:var(--text-dim,#888)}',
            '.rl-label.rl-html{white-space:normal;overflow:visible}',
            '.rl-btn{border:1px solid var(--border,#ddd);background:var(--surface,#fff);color:var(--text,#333);border-radius:5px;width:32px;height:32px;cursor:pointer;font-size:var(--font-size-lg, 14px);line-height:1;padding:0}',
            '.rl-btn:hover:not(:disabled){background:var(--surface-2,#f7f8fa)}',
            '.rl-btn:disabled{opacity:.35;cursor:default}',
            '.rl-live{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}',
            // Phones: bigger tap targets for the buttons and the grip.
            '@media (max-width:768px){.rl-btn{width:40px;height:40px}.rl-grip{padding:8px 4px}.rl-item{gap:8px}}',
        ].join('\n');
        document.head.appendChild(st);
    }

    /**
     * Builds the list inside a container.
     *
     * @param {HTMLElement} box
     * @param {{items: Array<{key:string,label:string,icon?:string,iconClass?:string,note?:string}>, onChange?: Function, label?: string}} opts
     */
    function mount(box, opts) {
        injectStyles();
        const byKey = {};
        opts.items.forEach(it => { byKey[it.key] = it; });
        let order = opts.items.map(it => it.key);
        let dragKey = null;

        box.innerHTML = '<ol class="rl-list" aria-label="' + esc(opts.label || '') + '"></ol><div class="rl-live" aria-live="polite"></div>';
        const list = box.querySelector('.rl-list');
        const live = box.querySelector('.rl-live');

        function rowHtml(key, i) {
            const it = byKey[key];
            const n = order.length;
            return '<li class="rl-item" data-key="' + esc(key) + '" tabindex="0" aria-label="' + esc(T('position', { name: it.label, n: i + 1, total: n }, '{name}, {n} of {total}')) + '"'
                + ' aria-keyshortcuts="Alt+ArrowUp Alt+ArrowDown">'
                + '<span class="rl-grip" draggable="true" aria-hidden="true" title="' + esc(T('drag', null, 'Drag to move')) + '">⋮⋮</span>'
                + '<span class="rl-pos" aria-hidden="true">' + (i + 1) + '</span>'
                + (it.icon ? '<span class="rl-icon ' + esc(it.iconClass || '') + '" aria-hidden="true">' + it.icon + '</span>' : '')
                + (it.html ? '<div class="rl-label rl-html">' + it.html + '</div>'
                           : '<span class="rl-label">' + esc(it.label) + (it.note ? ' <span class="rl-note">' + esc(it.note) + '</span>' : '') + '</span>')
                + '<button type="button" class="rl-btn" data-rl-move="-1" tabindex="-1" aria-label="' + esc(T('up', { name: it.label }, 'Move {name} up')) + '"' + (i === 0 ? ' disabled' : '') + '>↑</button>'
                + '<button type="button" class="rl-btn" data-rl-move="1" tabindex="-1" aria-label="' + esc(T('down', { name: it.label }, 'Move {name} down')) + '"' + (i === n - 1 ? ' disabled' : '') + '>↓</button>'
                + '</li>';
        }

        function render() { list.innerHTML = order.map(rowHtml).join(''); }

        /** Moves one item to an index, redraws, keeps focus on it, announces, and reports. */
        function moveTo(key, to, keepFocus) {
            const from = order.indexOf(key);
            to = Math.max(0, Math.min(order.length - 1, to));
            if (from < 0 || from === to) return;
            order.splice(from, 1);
            order.splice(to, 0, key);
            render();
            const row = list.querySelector('[data-key="' + CSS.escape(key) + '"]');
            if (row) {
                row.classList.add('rl-moved');
                setTimeout(() => row.classList.remove('rl-moved'), 600);
                if (keepFocus) row.focus();
            }
            live.textContent = T('moved', { name: byKey[key].label, n: to + 1, total: order.length }, '{name} moved to position {n} of {total}');
            if (opts.onChange) opts.onChange(order.slice());
        }

        list.addEventListener('click', e => {
            const b = e.target.closest('[data-rl-move]');
            if (!b || b.disabled) return;
            const key = b.closest('.rl-item').dataset.key;
            moveTo(key, order.indexOf(key) + parseInt(b.dataset.rlMove, 10), true);
        });

        list.addEventListener('keydown', e => {
            const row = e.target.closest('.rl-item');
            if (!row || e.target !== row) return;
            const key = row.dataset.key;
            if (e.altKey && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
                e.preventDefault();
                moveTo(key, order.indexOf(key) + (e.key === 'ArrowUp' ? -1 : 1), true);
            } else if (!e.altKey && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
                // Plain arrows walk the list, so a keyboard user can reach every row.
                e.preventDefault();
                const sib = e.key === 'ArrowUp' ? row.previousElementSibling : row.nextElementSibling;
                if (sib) sib.focus();
            }
        });

        // ---- Drag (grip only) ----
        list.addEventListener('dragstart', e => {
            const grip = e.target.closest && e.target.closest('.rl-grip');
            if (!grip) { e.preventDefault(); return; }
            const row = grip.closest('.rl-item');
            dragKey = row.dataset.key;
            row.classList.add('rl-dragging');
            e.dataTransfer.effectAllowed = 'move';
            try { e.dataTransfer.setData('text/plain', dragKey); } catch (err) { /* old Edge */ }
            try { e.dataTransfer.setDragImage(row, 20, 16); } catch (err) { /* not everywhere */ }
        });
        const clearOver = () => list.querySelectorAll('.rl-over-top,.rl-over-bottom').forEach(r => r.classList.remove('rl-over-top', 'rl-over-bottom'));
        /** Where a drop at this pointer position would put the dragged item. */
        function dropIndex(row, clientY) {
            const r = row.getBoundingClientRect();
            const after = clientY > r.top + r.height / 2;
            let to = order.indexOf(row.dataset.key) + (after ? 1 : 0);
            if (order.indexOf(dragKey) < to) to--;   // removing it first shifts everything below up one
            return { to, after };
        }
        list.addEventListener('dragover', e => {
            if (!dragKey) return;
            const row = e.target.closest('.rl-item');
            if (!row) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            clearOver();
            if (row.dataset.key !== dragKey) row.classList.add(dropIndex(row, e.clientY).after ? 'rl-over-bottom' : 'rl-over-top');
        });
        list.addEventListener('drop', e => {
            if (!dragKey) return;
            e.preventDefault();
            const row = e.target.closest('.rl-item');
            const key = dragKey;
            clearOver();
            dragKey = null;
            if (row && row.dataset.key !== key) moveTo(key, dropIndex2(row, e.clientY, key), false);
            else render();
        });
        /** dropIndex() for a key that is no longer held in dragKey. */
        function dropIndex2(row, clientY, key) {
            const r = row.getBoundingClientRect();
            let to = order.indexOf(row.dataset.key) + (clientY > r.top + r.height / 2 ? 1 : 0);
            if (order.indexOf(key) < to) to--;
            return to;
        }
        list.addEventListener('dragend', () => {
            clearOver();
            list.querySelectorAll('.rl-dragging').forEach(r => r.classList.remove('rl-dragging'));
            dragKey = null;
        });

        render();
        return {
            getOrder: () => order.slice(),
            /** Puts the list in this order (unknown keys ignored, missing ones kept at the end), without reporting a change. */
            setOrder(keys) {
                const known = keys.filter(k => byKey[k]);
                order = known.concat(order.filter(k => !known.includes(k)));
                render();
            },
        };
    }

    window.ReorderList = { mount };
})();
