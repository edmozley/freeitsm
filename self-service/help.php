<?php
/**
 * Self-Service Portal — Help.
 *
 * Chrome (head, theme, header, nav, footer) comes from includes/header.php and
 * includes/footer.php; shared styling from assets/css/self-service.css.
 */
$pageTitleKey = 'self-service.help.title';   // a KEY: i18n starts in header.php
$activeNav    = 'help';
// App-shell: the sidebar stays put and only the content column scrolls, which
// is how every analyst help page behaves.
//
// `portal-help` exists only so the phone block in self-service.css can reach
// THIS page. On a phone the guide needs a scroller that no other portal page
// wants: `body.portal-app` is `height: 100vh; overflow: hidden` (correct — the
// panes scroll), and help.css's own 900px block sets `.help-container` to
// `height: auto` and hands the scroll to the document, which then cannot take
// it. Seven thousand pixels of guide with nothing able to scroll. The class
// carries no styling above 768px.
$bodyClass    = 'portal-app portal-help';

$pageScripts = <<<'JS'
/*
 * Scroll-spy for the section sidebar.
 *
 * Listens on .help-main, NOT window: the container is a fixed viewport
 * height and that element is the only thing that scrolls, so window never
 * fires.
 *
 * ⚠️ The selector filters on [data-section]. The workflow help page documents
 * why: matching every .help-nav-link swept up real page links too, and the
 * click handler's preventDefault() then broke them.
 */
document.addEventListener('DOMContentLoaded', function () {
            var main  = document.getElementById('helpMain');
            var links = Array.prototype.slice.call(document.querySelectorAll('.help-nav-link[data-section]'));
            if (!main || !links.length) return;

            var sections = links.map(function (l) {
                return { id: l.dataset.section, el: document.getElementById(l.dataset.section) };
            }).filter(function (s) { return s.el; });

            function markActive(id) {
                links.forEach(function (l) { l.classList.toggle('active', l.dataset.section === id); });
            }

            /*
             * 🔴 WHICH ELEMENT ACTUALLY SCROLLS CHANGES WITH THE WIDTH, and
             * hardcoding `main` made every numbered link on this page tappable
             * and inert on a phone.
             *
             * On a desktop `.help-main` is the scroller — a fixed-height
             * column inside a fixed-height container. Below 900px help.css
             * sets `.help-main { overflow-y: visible }` and `.help-container
             * { height: auto }`, handing the scroll outwards; the portal's
             * phone block then makes `.help-container` the scroller, because
             * `body.portal-app` clips and the document cannot take it.
             *
             * `scrollTo` on an element that does not scroll throws nothing and
             * does nothing, so the highlight never moved off "1" and no chip
             * ever jumped. This is the same fault mobile.js fixed for all
             * seventeen analyst guides in #1464 — and this page cannot use
             * that file, because the portal does not load it.
             *
             * Resolved on every use rather than cached: a desktop browser
             * dragged across the breakpoint changes the answer.
             */
            function scroller() {
                var c = document.querySelector('.help-container');
                if (c && c.scrollHeight > c.clientHeight + 1) {
                    var oy = getComputedStyle(c).overflowY;
                    if (oy === 'auto' || oy === 'scroll') return c;
                }
                return main;
            }

            function onScroll() {
                var sc = scroller();
                var scTop = sc.getBoundingClientRect().top;
                var current = sections.length ? sections[0].id : null;
                sections.forEach(function (s) {
                    // Measured as a delta between rects rather than with
                    // offsetTop, which is relative to the nearest POSITIONED
                    // ancestor and so means something different depending on
                    // which element turned out to be the scroller. The 160px
                    // lead makes a section "current" just before it reaches
                    // the top, which is what reading feels like.
                    if (s.el.getBoundingClientRect().top - scTop - 160 <= 0) current = s.id;
                });
                markActive(current);
            }

            main.addEventListener('scroll', onScroll);
            var container = document.querySelector('.help-container');
            if (container) container.addEventListener('scroll', onScroll);

            links.forEach(function (l) {
                l.addEventListener('click', function (e) {
                    e.preventDefault();
                    var el = document.getElementById(l.dataset.section);
                    if (el) {
                        var sc = scroller();
                        var top = sc.scrollTop + (el.getBoundingClientRect().top - sc.getBoundingClientRect().top) - 16;
                        sc.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
                    }
                    markActive(l.dataset.section);
                });
            });
        });
JS;

// Page-specific styling only — shared chrome lives in self-service.css.
// The house style for every help guide in the product lives in help.css; the
// portal loads it through $pageHead, the hook for a page that needs one extra
// stylesheet. All this page adds is the portal's own accent.
$pageHead    = '<link rel="stylesheet" href="../assets/css/help.css?v=3">';
$pageStyles  = <<<'CSS'
body {
    --accent:       var(--ss-accent);
    --accent-hover: var(--ss-accent-hover);
    --accent-soft:  var(--ss-accent-soft);
    --on-accent:    var(--ss-on-accent);
}
CSS;

// The sidebar's sections, in page order. One list drives both the nav and the
// numbering, so a section can't be added to the page and forgotten in the
// sidebar — the failure the analyst help pages avoid the same way.
//
// ⚠️ KEYS, not translated strings: t() does not exist until header.php has
// booted i18n, and calling it up here is a fatal. The same reason $pageTitleKey
// is a key rather than a title.
// Page order, which is also the order we'd like people to try things: look for
// an answer BEFORE raising a ticket, and request-something right after it.
$helpNav = ['s1', 's2', 'kb', 'st', 's3', 'cat', 's4', 's5', 'tr', 's6', 's7'];

// A section's number is its position in that list, worked out here rather than
// typed into the heading. Two sections used to carry an empty number because
// they were inserted later and the surrounding ones were never renumbered —
// this makes that impossible, and it no longer depends on JavaScript running.
$secNum = function ($id) use ($helpNav) {
    $i = array_search($id, $helpNav, true);
    return $i === false ? '' : $i + 1;
};

require __DIR__ . '/includes/header.php';
?>
    <div class="help-container">
        <nav class="help-sidebar">
            <h3><?php echo htmlspecialchars(t('self-service.help.on_this_page')); ?></h3>
            <?php $i = 0; foreach ($helpNav as $id): $i++; ?>
            <a href="#<?php echo $id; ?>" class="help-nav-link<?php echo $i === 1 ? ' active' : ''; ?>" data-section="<?php echo $id; ?>">
                <span class="help-nav-num"><?php echo $i; ?></span>
                <?php echo htmlspecialchars(t('self-service.help.' . $id . '_title')); ?>
            </a>
            <?php endforeach; ?>
        </nav>

        <div class="help-main" id="helpMain">
            <div class="help-hero">
                <h1><?php echo htmlspecialchars(t('self-service.help.heading')); ?></h1>
                <p><?php echo htmlspecialchars(t('self-service.help.lede')); ?></p>
            </div>

            <div class="help-content">

        <!-- 1. Welcome -->
        <div class="help-section" id="s1">
            <div class="help-section-header">
                <span class="help-section-num"><?php echo $secNum('s1'); ?></span>
                <div><h3><?php echo htmlspecialchars(t('self-service.help.s1_title')); ?></h3></div>
            </div>
            <p><?php echo t('self-service.help.s1_p1'); ?></p>
            <p><?php echo t('self-service.help.s1_p2'); ?></p>
        </div>

        <!-- 2. Signing in -->
        <div class="help-section" id="s2">
            <div class="help-section-header">
                <span class="help-section-num"><?php echo $secNum('s2'); ?></span>
                <div><h3><?php echo htmlspecialchars(t('self-service.help.s2_title')); ?></h3></div>
            </div>
            <p><?php echo htmlspecialchars(t('self-service.help.s2_p1')); ?></p>
            <ol>
                <li><?php echo t('self-service.help.s2_li1'); ?></li>
                <li><?php echo t('self-service.help.s2_li2'); ?></li>
                <li><?php echo t('self-service.help.s2_li3'); ?></li>
            </ol>
            <p class="help-note"><?php echo t('self-service.help.s2_tip'); ?></p>
        </div>

        <!-- Finding an answer yourself. Deliberately BEFORE "raising a ticket":
             the order on the page is the order we'd like people to try. -->
        <div class="help-section" id="kb">
            <div class="help-section-header">
                <span class="help-section-num"><?php echo $secNum('kb'); ?></span>
                <div><h3><?php echo htmlspecialchars(t('self-service.help.kb_title')); ?></h3></div>
            </div>
            <p><?php echo t('self-service.help.kb_p1'); ?></p>
            <ol>
                <li><?php echo t('self-service.help.kb_li1'); ?></li>
                <li><?php echo t('self-service.help.kb_li2'); ?></li>
                <li><?php echo t('self-service.help.kb_li3'); ?></li>
            </ol>
            <p><?php echo t('self-service.help.kb_p2'); ?></p>
            <div class="help-note"><?php echo t('self-service.help.kb_tip'); ?></div>
        </div>

        <div class="help-section" id="st">
            <div class="help-section-header">
                <span class="help-section-num"><?php echo $secNum('st'); ?></span>
                <div><h3><?php echo htmlspecialchars(t('self-service.help.st_title')); ?></h3></div>
            </div>
            <p><?php echo t('self-service.help.st_p1'); ?></p>
            <p><?php echo t('self-service.help.st_p2'); ?></p>
            <div class="help-note"><?php echo t('self-service.help.st_tip'); ?></div>
        </div>

        <!-- 3. Raising a ticket -->
        <div class="help-section" id="s3">
            <div class="help-section-header">
                <span class="help-section-num"><?php echo $secNum('s3'); ?></span>
                <div><h3><?php echo htmlspecialchars(t('self-service.help.s3_title')); ?></h3></div>
            </div>
            <p><?php echo t('self-service.help.s3_p1'); ?></p>
            <ul>
                <li><?php echo t('self-service.help.s3_li1'); ?></li>
                <li><?php echo t('self-service.help.s3_li2'); ?></li>
                <li><?php echo t('self-service.help.s3_li3'); ?></li>
                <li><?php echo t('self-service.help.s3_li4'); ?></li>
                <li><?php echo t('self-service.help.s3_li5'); ?></li>
                <li><?php echo t('self-service.help.s3_li_category'); ?></li>
            </ul>
            <p><?php echo t('self-service.help.s3_p2'); ?></p>
            <p class="help-note"><?php echo t('self-service.help.s3_tip'); ?></p>
        </div>

        <!-- Requesting something — sits after raising a ticket, because it's the
             "this isn't a fault" alternative to it. -->
        <div class="help-section" id="cat">
            <div class="help-section-header">
                <span class="help-section-num"><?php echo $secNum('cat'); ?></span>
                <div><h3><?php echo htmlspecialchars(t('self-service.help.cat_title')); ?></h3></div>
            </div>
            <p><?php echo t('self-service.help.cat_p1'); ?></p>
            <ol>
                <li><?php echo t('self-service.help.cat_li1'); ?></li>
                <li><?php echo t('self-service.help.cat_li2'); ?></li>
                <li><?php echo t('self-service.help.cat_li3'); ?></li>
            </ol>
            <p><?php echo t('self-service.help.cat_p2'); ?></p>
        </div>

        <!-- 4. Screen recording -->
        <div class="help-section" id="s4">
            <div class="help-section-header">
                <span class="help-section-num"><?php echo $secNum('s4'); ?></span>
                <div><h3><?php echo htmlspecialchars(t('self-service.help.s4_title')); ?></h3></div>
            </div>
            <p><?php echo t('self-service.help.s4_p1'); ?></p>
            <ol>
                <li><?php echo t('self-service.help.s4_li1'); ?></li>
                <li><?php echo t('self-service.help.s4_li2'); ?></li>
                <li><?php echo t('self-service.help.s4_li3'); ?></li>
                <li><?php echo t('self-service.help.s4_li4'); ?></li>
                <li><?php echo t('self-service.help.s4_li5'); ?></li>
                <li><?php echo t('self-service.help.s4_li6'); ?></li>
                <li><?php echo t('self-service.help.s4_li7'); ?></li>
            </ol>
            <p class="help-note"><?php echo t('self-service.help.s4_tip1'); ?></p>
            <p class="help-note"><?php echo t('self-service.help.s4_tip2'); ?></p>
        </div>

        <!-- 5. Viewing & tracking tickets -->
        <div class="help-section" id="s5">
            <div class="help-section-header">
                <span class="help-section-num"><?php echo $secNum('s5'); ?></span>
                <div><h3><?php echo htmlspecialchars(t('self-service.help.s5_title')); ?></h3></div>
            </div>
            <p><?php echo t('self-service.help.s5_p1'); ?></p>
            <ul>
                <li><?php echo t('self-service.help.s5_li1'); ?></li>
                <li><?php echo t('self-service.help.s5_li2'); ?></li>
                <li><?php echo t('self-service.help.s5_li3'); ?></li>
            </ul>
            <p><?php echo t('self-service.help.s5_p2'); ?></p>
            <ul>
                <li><?php echo t('self-service.help.s5_li4'); ?></li>
                <li><?php echo t('self-service.help.s5_li5'); ?></li>
                <li><?php echo t('self-service.help.s5_li6'); ?></li>
                <li><?php echo t('self-service.help.s5_li7'); ?></li>
            </ul>
            <p><?php echo t('self-service.help.s5_p3'); ?></p>
        </div>

        <!-- Training. Only some portals use it, and the section says so in its
             first line rather than assuming everyone reading has a Training tab. -->
        <div class="help-section" id="tr">
            <div class="help-section-header">
                <span class="help-section-num"><?php echo $secNum('tr'); ?></span>
                <div><h3><?php echo htmlspecialchars(t('self-service.help.tr_title')); ?></h3></div>
            </div>
            <p><?php echo t('self-service.help.tr_p1'); ?></p>
            <ol>
                <li><?php echo t('self-service.help.tr_li1'); ?></li>
                <li><?php echo t('self-service.help.tr_li2'); ?></li>
                <li><?php echo t('self-service.help.tr_li3'); ?></li>
                <li><?php echo t('self-service.help.tr_li4'); ?></li>
            </ol>
            <p><?php echo t('self-service.help.tr_p2'); ?></p>
            <p class="help-note"><?php echo t('self-service.help.tr_tip'); ?></p>
        </div>

        <!-- 6. Account & security -->
        <div class="help-section" id="s6">
            <div class="help-section-header">
                <span class="help-section-num"><?php echo $secNum('s6'); ?></span>
                <div><h3><?php echo htmlspecialchars(t('self-service.help.s6_title')); ?></h3></div>
            </div>
            <p><?php echo t('self-service.help.s6_p1'); ?></p>
            <ul>
                <li><?php echo t('self-service.help.s6_li1'); ?></li>
                <li><?php echo t('self-service.help.s6_li_details'); ?></li>
                <li><?php echo t('self-service.help.s6_li2'); ?></li>
                <li><?php echo t('self-service.help.s6_li3'); ?></li>
            </ul>
            <p class="help-note"><?php echo t('self-service.help.s6_tip'); ?></p>
        </div>

        <!-- 7. Tips -->
        <div class="help-section" id="s7">
            <div class="help-section-header">
                <span class="help-section-num"><?php echo $secNum('s7'); ?></span>
                <div><h3><?php echo htmlspecialchars(t('self-service.help.s7_title')); ?></h3></div>
            </div>
            <ul>
                <li><?php echo t('self-service.help.s7_li1'); ?></li>
                <li><?php echo t('self-service.help.s7_li2'); ?></li>
                <li><?php echo t('self-service.help.s7_li3'); ?></li>
                <li><?php echo t('self-service.help.s7_li4'); ?></li>
                <li><?php echo t('self-service.help.s7_li5'); ?></li>
            </ul>
        </div>

            </div><!-- /.help-content -->
        </div><!-- /.help-main -->
    </div><!-- /.help-container -->
<?php require __DIR__ . '/includes/footer.php';
