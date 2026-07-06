/**
 * CMDB shared dropdown-options editor.
 *
 * Used by both the Settings page property modal and the floating
 * Edit-Property modal on the object detail page. Renders one row per option:
 * value text input + colour swatch + ⬆⬇ reorder + × delete, plus an "Add"
 * button below the list.
 *
 * API:
 *   renderOptionsEditor(containerId, initialOptions)
 *     containerId — element to render into
 *     initialOptions — array of either:
 *         strings (legacy: ["Low", "Medium"])
 *       or
 *         {value, colour} objects (new: [{value:"Low", colour:"#22c55e"}, ...])
 *
 *   collectOptionsFromEditor(containerId) -> [{value, colour}]
 *     Reads the current rows back as a normalised array of objects with the
 *     hex colour or null. Empty values are dropped.
 *
 * No external dependencies.
 */

(function (global) {
    function escapeHtmlAttr(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
    }

    function normalizeInitial(opts) {
        if (!Array.isArray(opts)) return [];
        return opts.map(o => {
            if (typeof o === 'string') return { value: o, colour: '' };
            return {
                value: String(o.value ?? ''),
                colour: o.colour ? String(o.colour) : ''
            };
        });
    }

    function renderRow(value, colour, index) {
        const colourValue = colour && /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(colour) ? colour : '#cccccc';
        const hasColour = !!colour;
        return `
            <div class="cmdb-opt-row" data-idx="${index}">
                <input type="text" class="cmdb-opt-val" value="${escapeHtmlAttr(value)}" placeholder="${escapeHtmlAttr(window.t('cmdb.options_editor.value_placeholder'))}" maxlength="255">
                <label class="cmdb-opt-colour-wrap" title="${escapeHtmlAttr(window.t('cmdb.options_editor.colour_title'))}">
                    <input type="color" class="cmdb-opt-colour" value="${colourValue}" data-has-colour="${hasColour ? '1' : '0'}">
                    <span class="cmdb-opt-colour-swatch" style="${hasColour ? `background:${colourValue};` : ''}"></span>
                </label>
                <button type="button" class="cmdb-opt-clear-colour" title="${escapeHtmlAttr(window.t('cmdb.options_editor.remove_colour'))}">×</button>
                <button type="button" class="cmdb-opt-up" title="${escapeHtmlAttr(window.t('cmdb.options_editor.move_up'))}">↑</button>
                <button type="button" class="cmdb-opt-down" title="${escapeHtmlAttr(window.t('cmdb.options_editor.move_down'))}">↓</button>
                <button type="button" class="cmdb-opt-del" title="${escapeHtmlAttr(window.t('cmdb.options_editor.delete_option'))}">🗑</button>
            </div>`;
    }

    function injectStylesOnce() {
        if (document.getElementById('cmdb-opt-editor-styles')) return;
        const style = document.createElement('style');
        style.id = 'cmdb-opt-editor-styles';
        style.textContent = `
            .cmdb-opt-list { display: flex; flex-direction: column; gap: 6px; }
            .cmdb-opt-row {
                display: grid;
                grid-template-columns: 1fr auto auto auto auto auto;
                gap: 6px;
                align-items: center;
            }
            .cmdb-opt-val {
                box-sizing: border-box;
                height: 30px;
                padding: 6px 10px;
                background: var(--surface, #ffffff);
                color: var(--text, #1f2937);
                border: 1px solid var(--border, #d1d5db);
                border-radius: 4px;
                font-size: 13px;
            }
            .cmdb-opt-val:focus { outline: none; border-color: var(--cmdb-accent, #be185d); }
            .cmdb-opt-colour-wrap {
                position: relative;
                display: inline-flex;
                align-items: center;
                cursor: pointer;
            }
            .cmdb-opt-colour {
                position: absolute;
                inset: 0;
                opacity: 0;
                width: 30px;
                height: 30px;
                cursor: pointer;
            }
            .cmdb-opt-colour-swatch {
                box-sizing: border-box;
                display: inline-block;
                width: 30px;
                height: 30px;
                border-radius: 4px;
                /* Always a neutral frame; the chosen colour is shown as the FILL
                   only (set in renderRow/oninput), so a coloured chip and an empty
                   one share the exact same footprint and line up in the column. */
                border: 1px solid var(--border, #d1d5db);
                /* "No colour" state: a diagonal slash on the surface colour, so an
                   unset swatch reads as empty rather than as a picked black — this
                   is what looked almost-black on dark before. A chosen colour
                   overrides the surface fill via an inline background. */
                background-color: var(--surface, #ffffff);
                background-image: linear-gradient(to top right, transparent calc(50% - 1px), var(--border, #d1d5db) 50%, transparent calc(50% + 1px));
            }
            .cmdb-opt-clear-colour, .cmdb-opt-up, .cmdb-opt-down, .cmdb-opt-del {
                box-sizing: border-box;
                background: var(--surface, #ffffff);
                border: 1px solid var(--border, #e5e7eb);
                color: var(--text-muted, #6b7280);
                width: 30px;
                height: 30px;
                border-radius: 4px;
                cursor: pointer;
                font-size: 13px;
                line-height: 1;
                padding: 0;
            }
            .cmdb-opt-clear-colour:hover, .cmdb-opt-del:hover { color: var(--danger-text, #b91c1c); border-color: #fecaca; }
            .cmdb-opt-up:hover, .cmdb-opt-down:hover { color: var(--cmdb-accent, #be185d); border-color: #fbcfe8; }
            [data-theme-mode="dark"] .cmdb-opt-clear-colour:hover, [data-theme-mode="dark"] .cmdb-opt-del:hover { border-color: rgba(248,113,113,0.5); }
            [data-theme-mode="dark"] .cmdb-opt-up:hover, [data-theme-mode="dark"] .cmdb-opt-down:hover { border-color: rgba(190,24,93,0.5); }
            .cmdb-opt-add {
                background: #fdf2f8;
                color: var(--cmdb-accent, #be185d);
                border: 1px dashed #fbcfe8;
                padding: 7px 12px;
                border-radius: 4px;
                cursor: pointer;
                font-size: 13px;
                font-weight: 500;
                margin-top: 6px;
                width: 100%;
            }
            .cmdb-opt-add:hover { background: #fce7f3; border-style: solid; }
            [data-theme-mode="dark"] .cmdb-opt-add { background: rgba(190,24,93,0.12); border-color: rgba(190,24,93,0.45); }
            [data-theme-mode="dark"] .cmdb-opt-add:hover { background: rgba(190,24,93,0.2); }
        `;
        document.head.appendChild(style);
    }

    function wireRowHandlers(container) {
        // Delete row
        container.querySelectorAll('.cmdb-opt-del').forEach(btn => {
            btn.onclick = (e) => {
                e.preventDefault();
                btn.closest('.cmdb-opt-row').remove();
            };
        });
        // Move up / down
        container.querySelectorAll('.cmdb-opt-up').forEach(btn => {
            btn.onclick = (e) => {
                e.preventDefault();
                const row = btn.closest('.cmdb-opt-row');
                const prev = row.previousElementSibling;
                if (prev) row.parentNode.insertBefore(row, prev);
            };
        });
        container.querySelectorAll('.cmdb-opt-down').forEach(btn => {
            btn.onclick = (e) => {
                e.preventDefault();
                const row = btn.closest('.cmdb-opt-row');
                const next = row.nextElementSibling;
                if (next) row.parentNode.insertBefore(next, row);
            };
        });
        // Colour picker — when changed, mark as set so save persists it
        container.querySelectorAll('.cmdb-opt-colour').forEach(input => {
            input.oninput = () => {
                input.dataset.hasColour = '1';
                const swatch = input.parentElement.querySelector('.cmdb-opt-colour-swatch');
                swatch.style.background = input.value;
            };
        });
        // Clear colour — back to "no colour set"
        container.querySelectorAll('.cmdb-opt-clear-colour').forEach(btn => {
            btn.onclick = (e) => {
                e.preventDefault();
                const row = btn.closest('.cmdb-opt-row');
                const colourInput = row.querySelector('.cmdb-opt-colour');
                const swatch = row.querySelector('.cmdb-opt-colour-swatch');
                colourInput.dataset.hasColour = '0';
                // Clear the inline styles so the CSS "no colour" slash returns.
                swatch.style.background = '';
                swatch.style.borderColor = '';
            };
        });
    }

    global.renderOptionsEditor = function (containerId, initialOptions) {
        injectStylesOnce();
        const container = document.getElementById(containerId);
        if (!container) return;
        const opts = normalizeInitial(initialOptions);
        const listHtml = `<div class="cmdb-opt-list">${opts.map((o, i) => renderRow(o.value, o.colour, i)).join('')}</div>
            <button type="button" class="cmdb-opt-add">${escapeHtmlAttr(window.t('cmdb.options_editor.add_option'))}</button>`;
        container.innerHTML = listHtml;

        const list = container.querySelector('.cmdb-opt-list');
        wireRowHandlers(list);

        container.querySelector('.cmdb-opt-add').onclick = (e) => {
            e.preventDefault();
            const div = document.createElement('div');
            div.innerHTML = renderRow('', '', list.children.length).trim();
            const newRow = div.firstChild;
            list.appendChild(newRow);
            wireRowHandlers(list);
            const valInput = newRow.querySelector('.cmdb-opt-val');
            if (valInput) valInput.focus();
        };
    };

    global.collectOptionsFromEditor = function (containerId) {
        const container = document.getElementById(containerId);
        if (!container) return [];
        const rows = container.querySelectorAll('.cmdb-opt-row');
        const out = [];
        rows.forEach(row => {
            const value = (row.querySelector('.cmdb-opt-val').value || '').trim();
            if (value === '') return;
            const colourInput = row.querySelector('.cmdb-opt-colour');
            const colour = (colourInput.dataset.hasColour === '1') ? colourInput.value : '';
            out.push({ value, colour });
        });
        return out;
    };
})(window);
