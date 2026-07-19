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
$bodyClass    = 'portal-app';

$pageScripts = <<<'JS'
/*
 * Scroll-spy for the section sidebar.
 *
 * Listens on .ss-help-main, NOT window: the container is a fixed viewport
 * height and that element is the only thing that scrolls, so window never
 * fires.
 *
 * ⚠️ The selector filters on [data-section]. The workflow help page documents
 * why: matching every .ss-help-nav-link swept up real page links too, and the
 * click handler's preventDefault() then broke them.
 */
document.addEventListener('DOMContentLoaded', function () {
            var main  = document.getElementById('helpMain');
            var links = Array.prototype.slice.call(document.querySelectorAll('.ss-help-nav-link[data-section]'));
            if (!main || !links.length) return;

            var sections = links.map(function (l) {
                return { id: l.dataset.section, el: document.getElementById(l.dataset.section) };
            }).filter(function (s) { return s.el; });

            // Stamp each section's number from its position in the nav, so the
            // heading and the sidebar can never disagree. Inserting a section
            // used to mean hand-renumbering every one after it — which is
            // exactly the kind of edit that gets half-done.
            sections.forEach(function (s, i) {
                var num = s.el.querySelector('.num');
                if (num) num.textContent = String(i + 1);
            });

            function markActive(id) {
                links.forEach(function (l) { l.classList.toggle('active', l.dataset.section === id); });
            }

            main.addEventListener('scroll', function () {
                var top = main.scrollTop;
                var current = sections.length ? sections[0].id : null;
                sections.forEach(function (s) {
                    // offsetTop is relative to the scrolling parent; the 160px lead
                    // means a section counts as "current" just before it reaches
                    // the top, which is what reading feels like.
                    if (s.el.offsetTop - 160 <= top) current = s.id;
                });
                markActive(current);
            });

            links.forEach(function (l) {
                l.addEventListener('click', function (e) {
                    e.preventDefault();
                    var el = document.getElementById(l.dataset.section);
                    if (el) {
                        var containerTop = main.getBoundingClientRect().top;
                        var elTop = el.getBoundingClientRect().top;
                        main.scrollTo({ top: main.scrollTop + (elTop - containerTop) - 16, behavior: 'smooth' });
                    }
                    markActive(l.dataset.section);
                });
            });
        });
JS;

// Page-specific styling only — shared chrome lives in self-service.css.
$pageStyles = <<<'CSS'
/* ── Module help-page layout ────────────────────────────────────────────
   Same shape as every analyst module's help page (system/help/_top.php): a
   fixed sidebar of section links beside a scrolling column.

   ⚠️ The sidebar is NOT position:sticky. The CONTAINER is a fixed viewport
   height and only .ss-help-main scrolls — which is why the scroll-spy listens
   on that element rather than on window. */
.ss-help-container {
            display: flex;
            height: calc(100vh - 48px);   /* 48px = the portal header */
        }
        .ss-help-sidebar {
            width: 250px;
            flex-shrink: 0;
            background: var(--surface, #fff);
            border-right: 1px solid var(--border, #e5e7eb);
            padding: 20px;
            overflow-y: auto;
            box-sizing: border-box;
        }
        .ss-help-sidebar h3 {
            font-size: 11px;
            font-weight: 700;
            color: var(--text-dim, #9aa);
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin: 0 0 12px;
        }
        .ss-help-nav-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 11px;
            border-radius: 6px;
            font-size: 13px;
            color: var(--text-muted, #555);
            text-decoration: none;
            margin-bottom: 2px;
        }
        .ss-help-nav-link:hover { background: var(--surface-hover, #f3f4f6); color: var(--text, #222); }
        /* Active state tinted with the PORTAL's accent, the way each module
           tints its own (workflow uses --wf-accent-soft, etc.). */
        .ss-help-nav-link.active {
            background: var(--ss-accent-soft, #d1fae5);
            color: var(--ss-accent-hover, #059669);
            font-weight: 600;
        }
        .ss-help-nav-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 22px;
            height: 22px;
            border-radius: 50%;
            background: var(--surface-hover, #eee);
            color: var(--text-dim, #888);
            font-weight: 700;
            font-size: 11px;
            flex-shrink: 0;
        }
        .ss-help-nav-link.active .ss-help-nav-num { background: var(--ss-accent, #10b981); color: #fff; }

        .ss-help-main { flex: 1; overflow-y: auto; min-width: 0; }
        .ss-help-hero {
            background: linear-gradient(135deg, var(--ss-accent, #10b981) 0%, var(--ss-accent-hover, #059669) 50%, #047857 100%);
            color: #fff;
            padding: 36px 40px 30px;
        }
        .ss-help-hero h1 { font-size: 24px; font-weight: 600; margin: 0 0 6px; color: #fff; }
        .ss-help-hero p  { margin: 0; font-size: 14px; opacity: 0.92; }
        /* The hero is a saturated brand block; dark palettes knock it back so it
           doesn't glare, exactly as the module help pages do. */
        [data-theme-mode="dark"] .ss-help-hero { filter: brightness(0.82); }

        @media (max-width: 900px) {
            .ss-help-container { flex-direction: column; height: auto; }
            .ss-help-sidebar { display: none; }   /* the content reads fine linearly */
        }

        /* Full width of the content column. ⚠️ `max-width: none` alone would NOT
           be enough — the `margin: 0 auto` centring has to go too, or the block
           stays put at its natural width. Padding matches the hero's 40px so the
           left edge lines up down the page. */
        .ss-help-page {
            max-width: none;
            margin: 0;
            padding: 28px 40px 64px;
        }
        .ss-help-page h1 {
            font-size: 28px;
            font-weight: 600;
            color: var(--text, #222);
            margin: 0 0 6px;
        }
        .ss-help-page p.lede {
            font-size: 15px;
            color: var(--text-muted, #666);
            line-height: 1.55;
            margin-bottom: 32px;
        }

        .ss-help-section {
            background: var(--surface, #fff);
            border-radius: 10px;
            padding: 26px 30px;
            margin-bottom: 22px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .ss-help-section h2 {
            font-size: 18px;
            font-weight: 600;
            color: var(--text, #222);
            margin: 0 0 14px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border, #eee);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .ss-help-section h2 .num {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: var(--ss-accent, #0078d4);
            color: white;
            font-size: 13px;
        }
        .ss-help-section h3 {
            font-size: 15px;
            font-weight: 600;
            color: var(--text, #333);
            margin: 18px 0 8px;
        }
        .ss-help-section p {
            font-size: 14px;
            line-height: 1.6;
            color: var(--text, #444);
            margin: 0 0 12px;
        }
        .ss-help-section ol, .ss-help-section ul {
            padding-left: 20px;
            margin: 0 0 14px;
        }
        .ss-help-section li {
            font-size: 14px;
            line-height: 1.7;
            color: var(--text, #444);
            margin-bottom: 4px;
        }
        .ss-help-section .tip {
            background: var(--ss-accent-soft, #eff6ff);
            border-left: 4px solid var(--ss-accent, #2563eb);
            padding: 12px 16px;
            border-radius: 4px;
            font-size: 13px;
            line-height: 1.55;
            color: var(--text, #1e3a8a);
            margin: 14px 0 0;
        }
        .ss-help-section code {
            background: var(--app-bg, #f5f5f5);
            padding: 1px 5px;
            border-radius: 3px;
            font-size: 12px;
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
$helpNav = ['s1', 's2', 'kb', 's3', 'cat', 's4', 's5', 's6', 's7'];

require __DIR__ . '/includes/header.php';
?>
    <div class="ss-help-container">
        <nav class="ss-help-sidebar">
            <h3><?php echo htmlspecialchars(t('self-service.help.on_this_page')); ?></h3>
            <?php $i = 0; foreach ($helpNav as $id): $i++; ?>
            <a href="#<?php echo $id; ?>" class="ss-help-nav-link<?php echo $i === 1 ? ' active' : ''; ?>" data-section="<?php echo $id; ?>">
                <span class="ss-help-nav-num"><?php echo $i; ?></span>
                <?php echo htmlspecialchars(t('self-service.help.' . $id . '_title')); ?>
            </a>
            <?php endforeach; ?>
        </nav>

        <div class="ss-help-main" id="helpMain">
            <div class="ss-help-hero">
                <h1><?php echo htmlspecialchars(t('self-service.help.heading')); ?></h1>
                <p><?php echo htmlspecialchars(t('self-service.help.lede')); ?></p>
            </div>

            <div class="ss-help-page">

        <!-- 1. Welcome -->
        <div class="ss-help-section" id="s1">
            <h2><span class="num">1</span> <?php echo htmlspecialchars(t('self-service.help.s1_title')); ?></h2>
            <p><?php echo t('self-service.help.s1_p1'); ?></p>
            <p><?php echo t('self-service.help.s1_p2'); ?></p>
        </div>

        <!-- 2. Signing in -->
        <div class="ss-help-section" id="s2">
            <h2><span class="num">2</span> <?php echo htmlspecialchars(t('self-service.help.s2_title')); ?></h2>
            <p><?php echo htmlspecialchars(t('self-service.help.s2_p1')); ?></p>
            <ol>
                <li><?php echo t('self-service.help.s2_li1'); ?></li>
                <li><?php echo t('self-service.help.s2_li2'); ?></li>
                <li><?php echo t('self-service.help.s2_li3'); ?></li>
            </ol>
            <p class="tip"><?php echo t('self-service.help.s2_tip'); ?></p>
        </div>

        <!-- Finding an answer yourself. Deliberately BEFORE "raising a ticket":
             the order on the page is the order we'd like people to try. -->
        <div class="ss-help-section" id="kb">
            <h2><span class="num"></span> <?php echo htmlspecialchars(t('self-service.help.kb_title')); ?></h2>
            <p><?php echo t('self-service.help.kb_p1'); ?></p>
            <ol>
                <li><?php echo t('self-service.help.kb_li1'); ?></li>
                <li><?php echo t('self-service.help.kb_li2'); ?></li>
                <li><?php echo t('self-service.help.kb_li3'); ?></li>
            </ol>
            <p><?php echo t('self-service.help.kb_p2'); ?></p>
            <div class="tip"><?php echo t('self-service.help.kb_tip'); ?></div>
        </div>

        <!-- 3. Raising a ticket -->
        <div class="ss-help-section" id="s3">
            <h2><span class="num">3</span> <?php echo htmlspecialchars(t('self-service.help.s3_title')); ?></h2>
            <p><?php echo t('self-service.help.s3_p1'); ?></p>
            <ul>
                <li><?php echo t('self-service.help.s3_li1'); ?></li>
                <li><?php echo t('self-service.help.s3_li2'); ?></li>
                <li><?php echo t('self-service.help.s3_li3'); ?></li>
                <li><?php echo t('self-service.help.s3_li4'); ?></li>
                <li><?php echo t('self-service.help.s3_li5'); ?></li>
            </ul>
            <p><?php echo t('self-service.help.s3_p2'); ?></p>
            <p class="tip"><?php echo t('self-service.help.s3_tip'); ?></p>
        </div>

        <!-- Requesting something — sits after raising a ticket, because it's the
             "this isn't a fault" alternative to it. -->
        <div class="ss-help-section" id="cat">
            <h2><span class="num"></span> <?php echo htmlspecialchars(t('self-service.help.cat_title')); ?></h2>
            <p><?php echo t('self-service.help.cat_p1'); ?></p>
            <ol>
                <li><?php echo t('self-service.help.cat_li1'); ?></li>
                <li><?php echo t('self-service.help.cat_li2'); ?></li>
                <li><?php echo t('self-service.help.cat_li3'); ?></li>
            </ol>
            <p><?php echo t('self-service.help.cat_p2'); ?></p>
        </div>

        <!-- 4. Screen recording -->
        <div class="ss-help-section" id="s4">
            <h2><span class="num">4</span> <?php echo htmlspecialchars(t('self-service.help.s4_title')); ?></h2>
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
            <p class="tip"><?php echo t('self-service.help.s4_tip1'); ?></p>
            <p class="tip"><?php echo t('self-service.help.s4_tip2'); ?></p>
        </div>

        <!-- 5. Viewing & tracking tickets -->
        <div class="ss-help-section" id="s5">
            <h2><span class="num">5</span> <?php echo htmlspecialchars(t('self-service.help.s5_title')); ?></h2>
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

        <!-- 6. Account & security -->
        <div class="ss-help-section" id="s6">
            <h2><span class="num">6</span> <?php echo htmlspecialchars(t('self-service.help.s6_title')); ?></h2>
            <p><?php echo t('self-service.help.s6_p1'); ?></p>
            <ul>
                <li><?php echo t('self-service.help.s6_li1'); ?></li>
                <li><?php echo t('self-service.help.s6_li2'); ?></li>
                <li><?php echo t('self-service.help.s6_li3'); ?></li>
            </ul>
            <p class="tip"><?php echo t('self-service.help.s6_tip'); ?></p>
        </div>

        <!-- 7. Tips -->
        <div class="ss-help-section" id="s7">
            <h2><span class="num">7</span> <?php echo htmlspecialchars(t('self-service.help.s7_title')); ?></h2>
            <ul>
                <li><?php echo t('self-service.help.s7_li1'); ?></li>
                <li><?php echo t('self-service.help.s7_li2'); ?></li>
                <li><?php echo t('self-service.help.s7_li3'); ?></li>
                <li><?php echo t('self-service.help.s7_li4'); ?></li>
                <li><?php echo t('self-service.help.s7_li5'); ?></li>
            </ul>
        </div>

            </div><!-- /.ss-help-page -->
        </div><!-- /.ss-help-main -->
    </div><!-- /.ss-help-container -->
<?php require __DIR__ . '/includes/footer.php';
