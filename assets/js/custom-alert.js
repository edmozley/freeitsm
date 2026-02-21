/**
 * customAlert — styled modal replacement for native alert() and confirm().
 *
 * Usage:
 *   await customAlert({ title: 'Done', message: 'Article saved.' });
 *   const ok = await customAlert({ title: 'Delete?', message: 'This cannot be undone.', type: 'danger', confirm: true });
 *
 * Options:
 *   title      (string)  — modal heading
 *   message    (string)  — body text (supports HTML)
 *   type       (string)  — 'info' | 'warning' | 'danger' | 'success'  (default: 'info')
 *   confirm    (boolean) — true = OK + Cancel, resolves true/false; false = OK only (default: false)
 *   okText     (string)  — label for OK button (default: 'OK')
 *   cancelText (string)  — label for Cancel button (default: 'Cancel')
 */

(function () {
    // Inject CSS once
    if (!document.getElementById('customAlertStyles')) {
        const style = document.createElement('style');
        style.id = 'customAlertStyles';
        style.textContent = `
            .ca-overlay {
                position: fixed; inset: 0;
                background: rgba(0,0,0,0.45);
                z-index: 50000;
                display: flex; align-items: center; justify-content: center;
                opacity: 0; transition: opacity 0.2s ease;
            }
            .ca-overlay.ca-active { opacity: 1; }
            .ca-box {
                background: #fff;
                border-radius: 10px;
                width: 420px; max-width: 92vw;
                box-shadow: 0 8px 32px rgba(0,0,0,0.22);
                overflow: hidden;
                transform: scale(0.92) translateY(10px);
                transition: transform 0.2s ease;
            }
            .ca-overlay.ca-active .ca-box { transform: scale(1) translateY(0); }
            .ca-bar { height: 5px; }
            .ca-bar.ca-info    { background: #0078d4; }
            .ca-bar.ca-warning { background: #f59e0b; }
            .ca-bar.ca-danger  { background: #d32f2f; }
            .ca-bar.ca-success { background: #2e7d32; }
            .ca-body { padding: 24px 28px 16px; }
            .ca-title {
                margin: 0 0 8px;
                font-size: 17px; font-weight: 600; color: #1a1a1a;
            }
            .ca-message {
                margin: 0;
                font-size: 14px; line-height: 1.6; color: #444;
            }
            .ca-actions {
                display: flex; justify-content: flex-end; gap: 10px;
                padding: 12px 28px 20px;
            }
            .ca-btn {
                padding: 8px 22px;
                border: none; border-radius: 6px;
                font-size: 14px; font-weight: 500;
                cursor: pointer; transition: background 0.15s, box-shadow 0.15s;
            }
            .ca-btn:focus-visible { outline: 2px solid #0078d4; outline-offset: 2px; }
            .ca-btn-cancel {
                background: #f0f0f0; color: #333;
            }
            .ca-btn-cancel:hover { background: #e0e0e0; }
            .ca-btn-ok {
                color: #fff;
            }
            .ca-btn-ok.ca-info    { background: #0078d4; }
            .ca-btn-ok.ca-info:hover    { background: #005a9e; }
            .ca-btn-ok.ca-warning { background: #f59e0b; }
            .ca-btn-ok.ca-warning:hover { background: #d97706; }
            .ca-btn-ok.ca-danger  { background: #d32f2f; }
            .ca-btn-ok.ca-danger:hover  { background: #b71c1c; }
            .ca-btn-ok.ca-success { background: #2e7d32; }
            .ca-btn-ok.ca-success:hover { background: #1b5e20; }
        `;
        document.head.appendChild(style);
    }

    window.customAlert = function (opts = {}) {
        const type = opts.type || 'info';
        const isConfirm = !!opts.confirm;
        const okText = opts.okText || 'OK';
        const cancelText = opts.cancelText || 'Cancel';

        return new Promise(resolve => {
            const overlay = document.createElement('div');
            overlay.className = 'ca-overlay';
            overlay.innerHTML = `
                <div class="ca-box">
                    <div class="ca-bar ca-${type}"></div>
                    <div class="ca-body">
                        <h3 class="ca-title">${opts.title || ''}</h3>
                        <p class="ca-message">${opts.message || ''}</p>
                    </div>
                    <div class="ca-actions">
                        ${isConfirm ? `<button class="ca-btn ca-btn-cancel">${cancelText}</button>` : ''}
                        <button class="ca-btn ca-btn-ok ca-${type}">${okText}</button>
                    </div>
                </div>
            `;

            document.body.appendChild(overlay);
            requestAnimationFrame(() => overlay.classList.add('ca-active'));

            const okBtn = overlay.querySelector('.ca-btn-ok');
            const cancelBtn = overlay.querySelector('.ca-btn-cancel');

            function close(result) {
                overlay.classList.remove('ca-active');
                setTimeout(() => overlay.remove(), 200);
                resolve(result);
            }

            okBtn.addEventListener('click', () => close(true));
            if (cancelBtn) cancelBtn.addEventListener('click', () => close(false));

            overlay.addEventListener('keydown', e => {
                if (e.key === 'Escape') close(isConfirm ? false : true);
            });

            okBtn.focus();
        });
    };
})();
