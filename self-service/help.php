<?php
/**
 * Self-Service Portal - Help / Guide page
 * Targeted at end users (not analysts). Covers registration, raising tickets,
 * screen recording, viewing tickets, and account/MFA management.
 */
session_start();
require_once '../config.php';
require_once '../includes/i18n.php';
I18n::initFromSession();
require_once 'includes/auth.php';

$translationNamespaces = ['common', 'self-service'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('self-service.help.title')); ?></title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        body { overflow: auto; height: auto; background: #f5f5f5; }

        .portal-header {
            background: #0078d4;
            color: white;
            padding: 0 24px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 1px 3px rgba(0,0,0,0.15);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .portal-brand { display: flex; align-items: center; gap: 12px; font-weight: 600; font-size: 15px; }
        .portal-brand img { height: 28px; filter: brightness(0) invert(1); }
        .portal-nav { display: flex; align-items: center; gap: 4px; }
        .portal-nav a {
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            padding: 6px 14px;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.15s;
        }
        .portal-nav a:hover  { background: rgba(255,255,255,0.15); color: white; }
        .portal-nav a.active { background: rgba(255,255,255,0.2);  color: white; }

        .ss-help-page {
            max-width: 800px;
            margin: 0 auto;
            padding: 32px 24px 64px;
        }
        .ss-help-page h1 {
            font-size: 28px;
            font-weight: 600;
            color: #222;
            margin: 0 0 6px;
        }
        .ss-help-page p.lede {
            font-size: 15px;
            color: #666;
            line-height: 1.55;
            margin-bottom: 32px;
        }

        .ss-help-section {
            background: white;
            border-radius: 10px;
            padding: 26px 30px;
            margin-bottom: 22px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .ss-help-section h2 {
            font-size: 18px;
            font-weight: 600;
            color: #222;
            margin: 0 0 14px;
            padding-bottom: 12px;
            border-bottom: 1px solid #eee;
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
            background: #0078d4;
            color: white;
            font-size: 13px;
        }
        .ss-help-section h3 {
            font-size: 15px;
            font-weight: 600;
            color: #333;
            margin: 18px 0 8px;
        }
        .ss-help-section p {
            font-size: 14px;
            line-height: 1.6;
            color: #444;
            margin: 0 0 12px;
        }
        .ss-help-section ol, .ss-help-section ul {
            padding-left: 20px;
            margin: 0 0 14px;
        }
        .ss-help-section li {
            font-size: 14px;
            line-height: 1.7;
            color: #444;
            margin-bottom: 4px;
        }
        .ss-help-section .tip {
            background: #eff6ff;
            border-left: 4px solid #2563eb;
            padding: 12px 16px;
            border-radius: 4px;
            font-size: 13px;
            line-height: 1.55;
            color: #1e3a8a;
            margin: 14px 0 0;
        }
        .ss-help-section code {
            background: #f5f5f5;
            padding: 1px 5px;
            border-radius: 3px;
            font-size: 12px;
        }
    </style>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../assets/js/i18n.js?v=2"></script>
</head>
<body>
    <div class="portal-header">
        <div class="portal-brand">
            <img src="../assets/images/CompanyLogo.png" alt="Logo">
            <span><?php echo htmlspecialchars(t('self-service.portal')); ?></span>
        </div>
        <nav class="portal-nav">
            <a href="index.php"><?php echo htmlspecialchars(t('self-service.nav.dashboard')); ?></a>
            <a href="new-ticket.php"><?php echo htmlspecialchars(t('self-service.nav.new_ticket')); ?></a>
            <a href="help.php" class="active"><?php echo htmlspecialchars(t('self-service.nav.help')); ?></a>
        </nav>
        <?php include 'includes/user-menu.php'; ?>
    </div>

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
</body>
</html>
