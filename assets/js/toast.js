/**
 * Global Toast Notification System
 * Position preference stored in localStorage ('toast_position')
 * Usage: showToast('Message text', 'success') — types: success, error, warning, info
 */
(function() {
    var style = document.createElement('style');
    style.textContent =
        '.toast-container{position:fixed;z-index:99999;display:flex;flex-direction:column;gap:8px;pointer-events:none;max-width:380px}' +
        '.toast-container.top-left{top:20px;left:20px;align-items:flex-start}' +
        '.toast-container.top-center{top:20px;left:50%;transform:translateX(-50%);align-items:center}' +
        '.toast-container.top-right{top:20px;right:20px;align-items:flex-end}' +
        '.toast-container.middle-left{top:50%;left:20px;transform:translateY(-50%);align-items:flex-start}' +
        '.toast-container.middle-center{top:50%;left:50%;transform:translate(-50%,-50%);align-items:center}' +
        '.toast-container.middle-right{top:50%;right:20px;transform:translateY(-50%);align-items:flex-end}' +
        '.toast-container.bottom-left{bottom:20px;left:20px;align-items:flex-start;flex-direction:column-reverse}' +
        '.toast-container.bottom-center{bottom:20px;left:50%;transform:translateX(-50%);align-items:center;flex-direction:column-reverse}' +
        '.toast-container.bottom-right{bottom:20px;right:20px;align-items:flex-end;flex-direction:column-reverse}' +
        '.toast-item{display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:8px;background:#fff;' +
            'box-shadow:0 4px 12px rgba(0,0,0,0.15);border-left:4px solid #ccc;font-size:14px;' +
            'font-family:"Segoe UI",Tahoma,Geneva,Verdana,sans-serif;color:#333;pointer-events:auto;' +
            'min-width:280px;max-width:380px;opacity:0;transition:opacity 0.3s,transform 0.3s}' +
        '.toast-item.show{opacity:1;transform:none!important}' +
        '.toast-container.top-left .toast-item,.toast-container.top-center .toast-item,.toast-container.top-right .toast-item{transform:translateY(-20px)}' +
        '.toast-container.bottom-left .toast-item,.toast-container.bottom-center .toast-item,.toast-container.bottom-right .toast-item{transform:translateY(20px)}' +
        '.toast-container.middle-left .toast-item{transform:translateX(-20px)}' +
        '.toast-container.middle-right .toast-item{transform:translateX(20px)}' +
        '.toast-container.middle-center .toast-item{transform:scale(0.95)}' +
        '.toast-item.toast-success{border-left-color:#22c55e}' +
        '.toast-item.toast-error{border-left-color:#ef4444}' +
        '.toast-item.toast-warning{border-left-color:#f59e0b}' +
        '.toast-item.toast-info{border-left-color:#3b82f6}' +
        '.toast-icon{flex-shrink:0;width:20px;height:20px}' +
        '.toast-success .toast-icon{color:#22c55e}' +
        '.toast-error .toast-icon{color:#ef4444}' +
        '.toast-warning .toast-icon{color:#f59e0b}' +
        '.toast-info .toast-icon{color:#3b82f6}' +
        '.toast-msg{flex:1;line-height:1.4}' +
        '.toast-close{flex-shrink:0;background:none;border:none;color:#999;cursor:pointer;font-size:18px;padding:0 2px;line-height:1}' +
        '.toast-close:hover{color:#333}';
    document.head.appendChild(style);

    var icons = {
        success: '<svg viewBox="0 0 20 20" fill="currentColor" class="toast-icon"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>',
        error: '<svg viewBox="0 0 20 20" fill="currentColor" class="toast-icon"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>',
        warning: '<svg viewBox="0 0 20 20" fill="currentColor" class="toast-icon"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>',
        info: '<svg viewBox="0 0 20 20" fill="currentColor" class="toast-icon"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>'
    };

    var container = null;
    var currentPosition = null;

    function getPosition() {
        return localStorage.getItem('toast_position') || 'bottom-right';
    }

    function getContainer() {
        var pos = getPosition();
        if (container && currentPosition === pos) return container;
        if (container) container.remove();
        container = document.createElement('div');
        container.className = 'toast-container ' + pos;
        document.body.appendChild(container);
        currentPosition = pos;
        return container;
    }

    window.showToast = function(message, type) {
        type = type || 'info';
        var c = getContainer();

        var toast = document.createElement('div');
        toast.className = 'toast-item toast-' + type;

        var iconEl = document.createElement('span');
        iconEl.innerHTML = icons[type] || icons.info;
        toast.appendChild(iconEl);

        var msgEl = document.createElement('span');
        msgEl.className = 'toast-msg';
        msgEl.textContent = message;
        toast.appendChild(msgEl);

        var closeEl = document.createElement('button');
        closeEl.className = 'toast-close';
        closeEl.textContent = '\u00D7';
        closeEl.onclick = function() { toast.remove(); };
        toast.appendChild(closeEl);

        c.appendChild(toast);

        requestAnimationFrame(function() {
            requestAnimationFrame(function() {
                toast.classList.add('show');
            });
        });

        setTimeout(function() {
            toast.classList.remove('show');
            setTimeout(function() { toast.remove(); }, 300);
        }, 4000);
    };
})();
