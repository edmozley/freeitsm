/**
 * Global Confirmation Modal
 *
 * One shared OK/Cancel dialog for the whole app. Look + behaviour modelled
 * on the calendar settings delete-category modal (#450). Auto-injects its
 * own markup + styles on first use so individual pages don't need any
 * HTML scaffolding — just call showConfirm({...}).
 *
 * Usage:
 *   // Callback-style:
 *   showConfirm({
 *       title: 'Delete category?',
 *       message: 'This cannot be undone.',
 *       okLabel: 'Delete',
 *       okClass: 'danger',
 *       onConfirm: () => doDelete()
 *   });
 *
 *   // Promise-style:
 *   const ok = await showConfirm({
 *       title: 'Delete category?',
 *       message: 'This cannot be undone.',
 *       okLabel: 'Delete',
 *       okClass: 'danger'
 *   });
 *   if (ok) doDelete();
 *
 * Options:
 *   title       - Modal heading text. Default: 'Confirm'.
 *   message     - Body text (plain text, not HTML).
 *   detailHtml  - Optional extra block under the message, as HTML. TRUSTED: the
 *                 caller must have escaped every value in it. Made for
 *                 assets/js/cmdb-ref-summary.js (GH #312 - "3 configuration items
 *                 point at this record ..."). Omit it or pass '' and the dialog
 *                 is exactly as it always was.
 *   okLabel     - Label for the OK button. Default: 'OK'.
 *   okClass     - 'primary' | 'danger'. Drives the OK button colour. Default: 'primary'.
 *   cancelLabel - Label for the Cancel button. Default: 'Cancel'.
 *   onConfirm   - Callback fired when the user confirms. Receives { checked, text }.
 *   onCancel    - Callback fired when the user cancels (or dismisses).
 *   checkbox    - Optional extra opt-in inside the dialog, for a destructive
 *                 action with a wider and a narrower reading:
 *                   checkbox: { label: 'Also delete unread', checked: false }
 *                 Omit it entirely and the dialog is exactly as it always was.
 *   textarea    - Optional free-text box, for a question that wants a sentence
 *                 rather than a yes (#142 — a one-off note on a closing ticket):
 *                   textarea: { placeholder: 'Have a nice day', label: 'Message' }
 *                 The typed value arrives TRIMMED on onConfirm's `text`, so a
 *                 box holding only spaces reads as empty for every caller.
 *                 Add `focus: true` to start typing straight away (Tasks' New);
 *                 otherwise OK has the focus, as it always had.
 *
 * Returns: Promise<boolean> — resolves true on confirm, false on cancel.
 *
 * ⚠️ THE RETURN TYPE IS A BOOLEAN AND MUST STAY ONE. 114 of the 129 call sites
 * do `if (await showConfirm(...))`. Resolving an object instead would make every
 * one of them treat a CANCEL as a confirm, because `{}` is truthy — a silent,
 * app-wide, destructive regression. So the checkbox state, AND the textarea's
 * text, are reported through onConfirm's argument rather than the promise.
 * Anything added here later must follow the same rule.
 */
(function() {
    if (window.showConfirm) return; // already loaded

    // Colours go through theme tokens with light-colour fallbacks: on pages
    // without a palette (no theme.css) the fallbacks render the exact original
    // light dialog (zero change app-wide); where a dark palette is active
    // (data-theme set, e.g. Tickets) the dialog themes to match automatically.
    var style = document.createElement('style');
    style.textContent =
        '.fitsm-confirm-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.5);' +
            'z-index:99998;display:flex;align-items:center;justify-content:center;' +
            'opacity:0;visibility:hidden;transition:opacity 0.2s ease,visibility 0.2s ease;' +
            'font-family:"Segoe UI",Tahoma,Geneva,Verdana,sans-serif}' +
        '.fitsm-confirm-overlay.active{opacity:1;visibility:visible}' +
        '.fitsm-confirm-modal{background:var(--surface,#fff);border-radius:8px;width:450px;max-width:90vw;' +
            'box-shadow:0 10px 40px var(--shadow,rgba(0,0,0,0.2));transform:scale(0.95) translateY(-10px);' +
            'transition:transform 0.2s ease}' +
        '.fitsm-confirm-overlay.active .fitsm-confirm-modal{transform:scale(1) translateY(0)}' +
        '.fitsm-confirm-header{padding:20px;border-bottom:1px solid var(--border,#e0e0e0)}' +
        '.fitsm-confirm-header h3{margin:0;font-size:18px;font-weight:600;color:var(--text,#333)}' +
        '.fitsm-confirm-body{padding:20px;color:var(--text-muted,#555);font-size:14px;line-height:1.5}' +
        '.fitsm-confirm-body p{margin:0;white-space:pre-wrap}' +
        // The optional detail block (GH #312): a warning under the question.
        '.fitsm-confirm-detail{margin-top:12px;padding:10px 12px;border-radius:4px;font-size:13px;' +
            'line-height:1.45;background:var(--warning-bg,#fff8e1);color:var(--warning-text,#7a5300);' +
            'border:1px solid var(--warning-border,#ffe08a);overflow-wrap:anywhere}' +
        '.fitsm-confirm-detail[hidden]{display:none}' +
        '.fitsm-confirm-footer{padding:15px 20px;border-top:1px solid var(--border,#e0e0e0);display:flex;' +
            'justify-content:flex-end;gap:10px}' +
        '.fitsm-confirm-btn{padding:8px 16px;border-radius:4px;font-size:13px;cursor:pointer;' +
            'border:none;transition:background 0.15s;font-family:inherit;font-weight:500}' +
        '.fitsm-confirm-btn:focus-visible{outline:2px solid var(--accent,#0078d4);outline-offset:2px}' +
        '.fitsm-confirm-btn-secondary{background:var(--surface-hover,#f0f0f0);color:var(--text,#333);border:1px solid var(--border,#ddd)}' +
        '.fitsm-confirm-btn-secondary:hover{background:var(--surface-2,#e0e0e0)}' +
        '.fitsm-confirm-btn-primary{background:var(--accent,#0078d4);color:#fff}' +
        '.fitsm-confirm-btn-primary:hover{background:var(--accent-hover,#005ea5)}' +
        '.fitsm-confirm-btn-danger{background:#c62828;color:#fff}' +
        '.fitsm-confirm-btn-danger:hover{background:#a02020}' +
        // The optional checkbox. Sits under the message inside the body, so it
        // reads as part of the question rather than as a third button.
        '.fitsm-confirm-check{display:flex;align-items:flex-start;gap:8px;margin-top:14px;' +
            'cursor:pointer;color:var(--text,#333);font-size:13px;line-height:1.4}' +
        '.fitsm-confirm-check[hidden]{display:none}' +
        '.fitsm-confirm-check input{margin:1px 0 0;flex:none;width:15px;height:15px;cursor:pointer;' +
            'accent-color:var(--accent,#0078d4)}' +
        // The optional textarea (#142). Same placement reasoning as the
        // checkbox: inside the body, under the message, so it reads as part of
        // the question rather than as a second dialogue.
        '.fitsm-confirm-text{display:block;margin-top:14px}' +
        '.fitsm-confirm-text[hidden]{display:none}' +
        '.fitsm-confirm-text textarea{width:100%;box-sizing:border-box;min-height:82px;resize:vertical;' +
            'padding:8px 10px;font-size:13px;line-height:1.45;font-family:inherit;' +
            'color:var(--text,#333);background:var(--surface,#fff);' +
            'border:1px solid var(--border,#ddd);border-radius:4px}' +
        '.fitsm-confirm-text textarea:focus{outline:2px solid var(--accent,#0078d4);outline-offset:-1px}';
    document.head.appendChild(style);

    var overlay = null;
    var titleEl = null;
    var bodyEl = null;
    var detailEl = null;
    var cancelBtn = null;
    var okBtn = null;
    var checkWrap = null;
    var checkInput = null;
    var checkLabel = null;
    var textWrap = null;
    var textInput = null;
    var currentOnConfirm = null;
    var currentOnCancel = null;
    var currentResolve = null;
    var keyHandler = null;

    /**
     * Creates the shared dialog's markup (title, message, optional tickbox and text box, Cancel and OK) the first time it is needed.
     */
    function build() {
        if (overlay) return;
        overlay = document.createElement('div');
        overlay.className = 'fitsm-confirm-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');

        var modal = document.createElement('div');
        modal.className = 'fitsm-confirm-modal';

        var header = document.createElement('div');
        header.className = 'fitsm-confirm-header';
        titleEl = document.createElement('h3');
        header.appendChild(titleEl);
        modal.appendChild(header);

        var body = document.createElement('div');
        body.className = 'fitsm-confirm-body';
        bodyEl = document.createElement('p');
        body.appendChild(bodyEl);

        detailEl = document.createElement('div');
        detailEl.className = 'fitsm-confirm-detail';
        detailEl.hidden = true;
        body.appendChild(detailEl);

        checkWrap = document.createElement('label');
        checkWrap.className = 'fitsm-confirm-check';
        checkWrap.hidden = true;
        checkInput = document.createElement('input');
        checkInput.type = 'checkbox';
        checkLabel = document.createElement('span');
        checkWrap.appendChild(checkInput);
        checkWrap.appendChild(checkLabel);
        body.appendChild(checkWrap);

        textWrap = document.createElement('div');
        textWrap.className = 'fitsm-confirm-text';
        textWrap.hidden = true;
        textInput = document.createElement('textarea');
        textWrap.appendChild(textInput);
        body.appendChild(textWrap);

        modal.appendChild(body);

        var footer = document.createElement('div');
        footer.className = 'fitsm-confirm-footer';
        cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'fitsm-confirm-btn fitsm-confirm-btn-secondary';
        cancelBtn.addEventListener('click', handleCancel);
        footer.appendChild(cancelBtn);

        okBtn = document.createElement('button');
        okBtn.type = 'button';
        okBtn.addEventListener('click', handleConfirm);
        footer.appendChild(okBtn);

        modal.appendChild(footer);
        overlay.appendChild(modal);

        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) handleCancel();
        });

        document.body.appendChild(overlay);
    }

    /**
     * OK: closes the dialog, calls onConfirm with the tickbox and text box state, and resolves the promise with true.
     */
    function handleConfirm() {
        var cb = currentOnConfirm;
        var resolve = currentResolve;
        // Read the box BEFORE close() resets anything, and hand it to the
        // callback. The promise still resolves a plain boolean — see the header.
        var state = {
            checked: !!(checkInput && !checkWrap.hidden && checkInput.checked),
            // Trimmed here so every caller gets the same answer for a box
            // containing only whitespace: the empty string.
            text: (textInput && !textWrap.hidden) ? textInput.value.trim() : ''
        };
        close();
        if (typeof cb === 'function') cb(state);
        if (typeof resolve === 'function') resolve(true);
    }

    /**
     * Cancel, Escape or a backdrop click: closes the dialog, calls onCancel and resolves the promise with false.
     */
    function handleCancel() {
        var cb = currentOnCancel;
        var resolve = currentResolve;
        close();
        if (typeof cb === 'function') cb();
        if (typeof resolve === 'function') resolve(false);
    }

    /** Hides the dialog, removes its key handler and forgets the current callbacks. */
    function close() {
        if (!overlay) return;
        overlay.classList.remove('active');
        if (keyHandler) {
            document.removeEventListener('keydown', keyHandler);
            keyHandler = null;
        }
        currentOnConfirm = null;
        currentOnCancel = null;
        currentResolve = null;
    }

    /**
     * Shows the shared OK/Cancel dialog with the given options and returns a promise of true (OK) or false (Cancel).
     *
     * See the file header for the options.
     */
    window.showConfirm = function(opts) {
        opts = opts || {};
        build();

        // tf() is translate-or-this-English (i18n.js). Guarded because this
        // file is injected by pages that do not all load i18n.js, and a dialog
        // whose buttons read "common.ok" is worse than one reading "OK".
        var cd = function (key, english) {
            return window.tf ? window.tf(key, english) : english;
        };

        titleEl.textContent = opts.title || cd('common.confirm_dialog.title', 'Confirm');
        bodyEl.textContent = opts.message || '';
        // Reset every time: one reused element, so a warning from a previous
        // question must not appear on an unrelated one.
        detailEl.innerHTML = opts.detailHtml || '';
        detailEl.hidden = !opts.detailHtml;
        okBtn.textContent = opts.okLabel || cd('common.ok', 'OK');
        cancelBtn.textContent = opts.cancelLabel || cd('common.cancel', 'Cancel');

        var cls = (opts.okClass === 'danger') ? 'danger' : 'primary';
        okBtn.className = 'fitsm-confirm-btn fitsm-confirm-btn-' + cls;

        // Reset every time: the dialog is a single reused element, so a checkbox
        // left over from a previous call would appear on an unrelated one.
        checkWrap.hidden = !opts.checkbox;
        checkInput.checked = false;
        if (opts.checkbox) {
            checkLabel.textContent = opts.checkbox.label || '';
            checkInput.checked = !!opts.checkbox.checked;
        }

        // Reset every time, same reason as the checkbox: one reused element,
        // so yesterday's typing must not surface on an unrelated question.
        textWrap.hidden = !opts.textarea;
        textInput.value = '';
        if (opts.textarea) {
            textInput.placeholder = opts.textarea.placeholder || '';
            textInput.setAttribute('aria-label', opts.textarea.label || opts.title || '');
        }

        currentOnConfirm = opts.onConfirm || null;
        currentOnCancel = opts.onCancel || null;

        return new Promise(function(resolve) {
            currentResolve = resolve;

            /** Escape cancels; Enter confirms unless Cancel has the focus. */
            keyHandler = function(e) {
                if (e.key === 'Escape') handleCancel();
                else if (e.key === 'Enter' && document.activeElement !== cancelBtn) handleConfirm();
            };
            document.addEventListener('keydown', keyHandler);

            /** Shows the dialog on the next frame so its transition runs, then focuses OK on the frame after. */
            requestAnimationFrame(function() {
                overlay.classList.add('active');
                requestAnimationFrame(function() {
                    (opts.textarea && opts.textarea.focus ? textInput : okBtn).focus();
                });
            });
        });
    };
})();
