/**
 * Keeps a module header on one line at desktop widths (see inbox.css,
 * "Keeping the header on one screen").
 *
 * The header is measured as it is drawn; if it is wider than the window it is
 * stepped down - tighter nav buttons first, then icon-only buttons - until it
 * fits. It is measured again whenever the header changes width. Phones
 * (<= 768px) use mobile.css's drawer, so nothing is done there.
 */
(function () {
    'use strict';

    var LEVELS = ['header-fit-tight', 'header-fit-icons'];
    var mq = window.matchMedia ? window.matchMedia('(max-width: 768px)') : null;

    function overflows(header) {
        if (header.scrollWidth > header.clientWidth + 1) return true;
        var nav = header.querySelector('.header-nav');
        return !!nav && nav.scrollWidth > nav.clientWidth + 1;
    }

    function fit(header) {
        // Start from the full layout each time, so widening the window brings the
        // labels back.
        LEVELS.forEach(function (c) { header.classList.remove(c); });
        if (mq && mq.matches) return;
        for (var i = 0; i < LEVELS.length && overflows(header); i++) {
            header.classList.add(LEVELS[i]);
        }
    }

    function init() {
        var header = document.querySelector('.header');
        if (!header) return;
        var pending = false;
        function schedule() {
            if (pending) return;
            pending = true;
            requestAnimationFrame(function () { pending = false; fit(header); });
        }
        fit(header);
        if (window.ResizeObserver) {
            new ResizeObserver(schedule).observe(header);
        } else {
            window.addEventListener('resize', schedule);
        }
        // Web fonts can change the labels' widths after the first measurement.
        if (document.fonts && document.fonts.ready) document.fonts.ready.then(schedule);
        window.addEventListener('load', schedule);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
