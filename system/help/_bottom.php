<?php
/**
 * Closes a System help topic page (content area) and emits the scroll-spy +
 * auto-numbering script. Pair with _top.php.
 */
?>
            </div>
        </div>
    </div>

    <script>
        (function () {
            var main = document.getElementById('helpMain');
            var links = Array.prototype.slice.call(document.querySelectorAll('.syshelp-nav-link'));
            var sections = links.map(function (l) {
                return { id: l.dataset.section, el: document.getElementById(l.dataset.section) };
            }).filter(function (s) { return s.el; });

            // Stamp a number badge before each section heading to match the sidebar.
            document.querySelectorAll('.syshelp-section').forEach(function (sec, i) {
                var hdr = sec.querySelector('.syshelp-section-header');
                if (hdr && !hdr.querySelector('.syshelp-section-num')) {
                    var num = document.createElement('span');
                    num.className = 'syshelp-section-num';
                    num.textContent = (i + 1);
                    hdr.insertBefore(num, hdr.firstChild);
                }
            });

            if (main) main.addEventListener('scroll', function () {
                var top = main.scrollTop, current = sections.length ? sections[0].id : null;
                sections.forEach(function (s) { if (s.el.offsetTop - 160 <= top) current = s.id; });
                links.forEach(function (l) { l.classList.toggle('active', l.dataset.section === current); });
            });

            links.forEach(function (l) {
                l.addEventListener('click', function (e) {
                    e.preventDefault();
                    var el = document.getElementById(l.dataset.section);
                    if (el && main) {
                        var ct = main.getBoundingClientRect().top, et = el.getBoundingClientRect().top;
                        main.scrollTo({ top: main.scrollTop + (et - ct) - 16, behavior: 'smooth' });
                    }
                    links.forEach(function (x) { x.classList.remove('active'); });
                    l.classList.add('active');
                });
            });
        })();
    </script>
    <script src="../../assets/js/mobile.js?v=55"></script>
</body>
</html>
