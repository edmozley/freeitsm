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
            var links = Array.prototype.slice.call(document.querySelectorAll('.help-nav-link'));
            var sections = links.map(function (l) {
                return { id: l.dataset.section, el: document.getElementById(l.dataset.section) };
            }).filter(function (s) { return s.el; });

            // 🔑 The number badges are NOT stamped here any more. This used to
            // count `.help-section` nodes and insert a badge into each one,
            // which numbered whatever was in the DOM rather than what the
            // sidebar lists — so a section on the page but missing from
            // _registry.php silently shifted every number after it, and no
            // number appeared at all without JavaScript. `helpSectionNum()` in
            // _init.php now resolves it from the same array that builds the
            // sidebar, server-side. See Help Page House Style §5.

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
    <script src="../../assets/js/mobile.js?v=57"></script>
</body>
</html>
