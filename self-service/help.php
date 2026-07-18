<?php
/**
 * Self-Service Portal — Help.
 *
 * Chrome (head, theme, header, nav, footer) comes from includes/header.php and
 * includes/footer.php; shared styling from assets/css/self-service.css.
 */
$pageTitleKey = 'self-service.help.title';   // a KEY: i18n starts in header.php
$activeNav    = 'help';

// Page-specific styling only — shared chrome lives in self-service.css.
$pageStyles = <<<'CSS'
.ss-help-page {
            max-width: 800px;
            margin: 0 auto;
            padding: 32px 24px 64px;
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

require __DIR__ . '/includes/header.php';
?>
    <div class="ss-help-page">
        <h1><?php echo htmlspecialchars(t('self-service.help.heading')); ?></h1>
        <p class="lede"><?php echo htmlspecialchars(t('self-service.help.lede')); ?></p>

        <!-- 1. Welcome -->
        <div class="ss-help-section">
            <h2><span class="num">1</span> <?php echo htmlspecialchars(t('self-service.help.s1_title')); ?></h2>
            <p><?php echo t('self-service.help.s1_p1'); ?></p>
            <p><?php echo t('self-service.help.s1_p2'); ?></p>
        </div>

        <!-- 2. Signing in -->
        <div class="ss-help-section">
            <h2><span class="num">2</span> <?php echo htmlspecialchars(t('self-service.help.s2_title')); ?></h2>
            <p><?php echo htmlspecialchars(t('self-service.help.s2_p1')); ?></p>
            <ol>
                <li><?php echo t('self-service.help.s2_li1'); ?></li>
                <li><?php echo t('self-service.help.s2_li2'); ?></li>
                <li><?php echo t('self-service.help.s2_li3'); ?></li>
            </ol>
            <p class="tip"><?php echo t('self-service.help.s2_tip'); ?></p>
        </div>

        <!-- 3. Raising a ticket -->
        <div class="ss-help-section">
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

        <!-- 4. Screen recording -->
        <div class="ss-help-section">
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
        <div class="ss-help-section">
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
        <div class="ss-help-section">
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
        <div class="ss-help-section">
            <h2><span class="num">7</span> <?php echo htmlspecialchars(t('self-service.help.s7_title')); ?></h2>
            <ul>
                <li><?php echo t('self-service.help.s7_li1'); ?></li>
                <li><?php echo t('self-service.help.s7_li2'); ?></li>
                <li><?php echo t('self-service.help.s7_li3'); ?></li>
                <li><?php echo t('self-service.help.s7_li4'); ?></li>
                <li><?php echo t('self-service.help.s7_li5'); ?></li>
            </ul>
        </div>

    </div>
<?php require __DIR__ . '/includes/footer.php';
