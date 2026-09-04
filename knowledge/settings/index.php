<?php
/**
 * Knowledge Settings - Configure outbound email settings
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/ai_settings_panel.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../../auth/login.php');
    exit;
}
require_once '../../includes/settings_manifest.php';
requireModuleAccess('knowledge');

// RBAC Layer 2: only the tabs this analyst may see are rendered.
$settingsManifest = settingsManifestFor('knowledge');
$visibleTabs      = settingsVisibleTabs(connectToDatabase(), (int) $_SESSION['analyst_id'], $settingsManifest);
$activeTabId      = settingsFirstTabId($visibleTabs);

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$current_page = 'settings';
$path_prefix = '../../';  // Two levels up from knowledge/settings/
$translationNamespaces = ['common', 'knowledge'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('knowledge.browser_title.settings')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=62">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <script src="../../assets/js/ai-settings.js?v=2"></script>
    <style>
        /* Page-specific overrides for settings page */
        .container {
            height: calc(100vh - 48px);
            overflow-y: auto;
            max-width: none;
            margin: 0;
            padding: 16px 30px 24px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-weight: 500;
            margin-bottom: 6px;
            color: var(--text, #333);
        }

        /* Text-style inputs only — scoping by :not(...) so radios / checkboxes
           on this page (e.g. the Left panel tab) aren't stretched to 100%. */
        .form-group input:not([type="radio"]):not([type="checkbox"]),
        .form-group select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border, #ddd);
            border-radius: 4px;
            font-size: 14px;
        }

        .form-group input:not([type="radio"]):not([type="checkbox"]):focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--kb-accent, #8764b8);
            box-shadow: 0 0 0 2px rgba(135, 100, 184, 0.1);
        }

        .form-group small {
            display: block;
            margin-top: 4px;
            color: var(--text-dim, #888);
            font-size: 12px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .radio-group {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .radio-option {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 15px;
            border: 1px solid var(--border, #ddd);
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .radio-option:hover {
            background: var(--surface-2, #f9f9f9);
        }

        .radio-option.selected {
            border-color: var(--kb-accent, #8764b8);
            background: var(--kb-accent-soft, #f8f5fb);
        }

        .radio-option input[type="radio"] {
            margin-top: 3px;
        }

        .radio-option-content {
            flex: 1;
        }

        .radio-option-title {
            font-weight: 500;
            color: var(--text, #333);
            margin-bottom: 4px;
        }

        .radio-option-desc {
            font-size: 13px;
            color: var(--text-muted, #666);
        }

        .smtp-settings {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--border, #e0e0e0);
            display: none;
        }

        .smtp-settings.active {
            display: block;
        }

        .mailbox-settings {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--border, #e0e0e0);
            display: none;
        }

        .mailbox-settings.active {
            display: block;
        }

        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid var(--border, #e0e0e0);
        }

        .btn {
            padding: 10px 20px;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
        }

        .btn-primary {
            background: var(--kb-accent, #8764b8);
            color: var(--kb-on-accent, white);
        }

        .btn-primary:hover {
            background: var(--kb-accent-hover, #6b4fa2);
        }

        .btn-secondary {
            background: var(--surface-hover, #f0f0f0);
            color: var(--text, #333);
            border: 1px solid var(--border, #ddd);
        }

        .btn-secondary:hover {
            background: var(--border, #e0e0e0);
        }

        .btn-test {
            background: #107c10;
            color: white;
        }

        .btn-test:hover {
            background: #0b5c0b;
        }

        .save-message {
            color: var(--success-text, #155724);
            margin-left: 15px;
            display: none;
        }

        .save-message.error {
            color: var(--danger-accent, #d13438);
        }

        .test-result {
            margin-top: 15px;
            padding: 15px;
            border-radius: 6px;
            display: none;
        }

        .test-result.success {
            display: block;
            background: var(--success-bg, #d4edda);
            border: 1px solid var(--success-bg, #c3e6cb);
            color: var(--success-text, #155724);
        }

        .test-result.error {
            display: block;
            background: var(--danger-bg, #f8d7da);
            border: 1px solid var(--danger-bg, #f5c6cb);
            color: var(--danger-text, #721c24);
        }

        /* The blast-radius note on the folder-permission setting. Lives here
           rather than in knowledge.css because this page does not link that
           file — a rule written there would simply never have applied, which is
           invisible rather than broken. Neutral by default; coloured only when
           the change would WIDEN access, since that is the direction that
           discloses rather than merely inconveniences. */
        .kb-perm-preview {
            margin: 14px 0;
            padding: 10px 14px;
            border-radius: 6px;
            border: 1px solid var(--border, #ddd);
            background: var(--surface, #fff);
            font-size: 13px;
            color: var(--text, #333);
        }
        .kb-perm-preview.is-widening {
            /* --danger-* , never a bare --danger: that token does not exist, and
               a phantom just inherits and looks plausible in one theme. */
            border-color: var(--danger-border, #f5c6cb);
            background: var(--danger-bg, #f8d7da);
            color: var(--danger-text, #721c24);
        }
    </style>
    <!-- Mobile: LAYER 15e handles a settings page built on .container + renderSettingsTabBar, which is this one - hence the marker on <body>. -->
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=130">
</head>
<body data-mobile-page="settings">
    <?php include '../includes/header.php'; ?>

    <div class="container">
        <?php renderSettingsTabBar($visibleTabs, $activeTabId); ?>

        <!-- Email Tab -->
        <?php if (settingsTabVisible($visibleTabs, 'email')): ?>
        <div class="tab-content<?php echo $activeTabId === 'email' ? ' active' : ''; ?>" id="email-tab" data-capability="<?php echo Cap::KNOWLEDGE_EMAIL; ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('knowledge.settings.email_heading')); ?></h2>
            </div>
            <p style="color: var(--text-muted, #666); margin-bottom: 20px;"><?php echo htmlspecialchars(t('knowledge.settings.email_intro')); ?></p>

            <form id="emailSettingsForm">
                <div class="radio-group">
                    <label class="radio-option" id="optionSmtp">
                        <input type="radio" name="email_method" value="smtp" onchange="toggleEmailMethod('smtp')">
                        <div class="radio-option-content">
                            <div class="radio-option-title"><?php echo htmlspecialchars(t('knowledge.settings.method_smtp_title')); ?></div>
                            <div class="radio-option-desc"><?php echo htmlspecialchars(t('knowledge.settings.method_smtp_desc')); ?></div>
                        </div>
                    </label>

                    <label class="radio-option" id="optionMailbox">
                        <input type="radio" name="email_method" value="mailbox" onchange="toggleEmailMethod('mailbox')">
                        <div class="radio-option-content">
                            <div class="radio-option-title"><?php echo htmlspecialchars(t('knowledge.settings.method_mailbox_title')); ?></div>
                            <div class="radio-option-desc"><?php echo htmlspecialchars(t('knowledge.settings.method_mailbox_desc')); ?></div>
                        </div>
                    </label>

                    <label class="radio-option" id="optionDisabled">
                        <input type="radio" name="email_method" value="disabled" onchange="toggleEmailMethod('disabled')">
                        <div class="radio-option-content">
                            <div class="radio-option-title"><?php echo htmlspecialchars(t('knowledge.settings.method_disabled_title')); ?></div>
                            <div class="radio-option-desc"><?php echo htmlspecialchars(t('knowledge.settings.method_disabled_desc')); ?></div>
                        </div>
                    </label>
                </div>

                <!-- SMTP Settings -->
                <div class="smtp-settings" id="smtpSettings">
                    <h3 style="font-size: 16px; margin-bottom: 15px;"><?php echo htmlspecialchars(t('knowledge.settings.smtp_config')); ?></h3>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="smtpHost"><?php echo htmlspecialchars(t('knowledge.settings.smtp_host')); ?></label>
                            <input type="text" id="smtpHost" placeholder="<?php echo htmlspecialchars(t('knowledge.settings.smtp_host_placeholder')); ?>">
                        </div>
                        <div class="form-group">
                            <label for="smtpPort"><?php echo htmlspecialchars(t('knowledge.settings.smtp_port')); ?></label>
                            <input type="number" id="smtpPort" placeholder="<?php echo htmlspecialchars(t('knowledge.settings.smtp_port_placeholder')); ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="smtpEncryption"><?php echo htmlspecialchars(t('knowledge.settings.smtp_encryption')); ?></label>
                            <select id="smtpEncryption">
                                <option value="none"><?php echo htmlspecialchars(t('knowledge.settings.enc_none')); ?></option>
                                <option value="tls" selected><?php echo htmlspecialchars(t('knowledge.settings.enc_tls')); ?></option>
                                <option value="ssl"><?php echo htmlspecialchars(t('knowledge.settings.enc_ssl')); ?></option>
                            </select>
                            <small><?php echo htmlspecialchars(t('knowledge.settings.smtp_enc_help')); ?></small>
                        </div>
                        <div class="form-group">
                            <label for="smtpAuth"><?php echo htmlspecialchars(t('knowledge.settings.smtp_auth')); ?></label>
                            <select id="smtpAuth" onchange="toggleSmtpAuth()">
                                <option value="yes" selected><?php echo htmlspecialchars(t('knowledge.settings.auth_yes')); ?></option>
                                <option value="no"><?php echo htmlspecialchars(t('knowledge.settings.auth_no')); ?></option>
                            </select>
                        </div>
                    </div>

                    <div id="smtpAuthFields">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="smtpUsername"><?php echo htmlspecialchars(t('knowledge.settings.smtp_username')); ?></label>
                                <input type="text" id="smtpUsername" placeholder="<?php echo htmlspecialchars(t('knowledge.settings.smtp_username_placeholder')); ?>">
                            </div>
                            <div class="form-group">
                                <label for="smtpPassword"><?php echo htmlspecialchars(t('knowledge.settings.smtp_password')); ?></label>
                                <input type="password" id="smtpPassword" placeholder="<?php echo htmlspecialchars(t('knowledge.settings.smtp_password_placeholder')); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="smtpFromEmail"><?php echo htmlspecialchars(t('knowledge.settings.smtp_from_email')); ?></label>
                        <input type="email" id="smtpFromEmail" placeholder="<?php echo htmlspecialchars(t('knowledge.settings.smtp_from_email_placeholder')); ?>">
                        <small><?php echo htmlspecialchars(t('knowledge.settings.smtp_from_email_help')); ?></small>
                    </div>

                    <div class="form-group">
                        <label for="smtpFromName"><?php echo htmlspecialchars(t('knowledge.settings.smtp_from_name')); ?></label>
                        <input type="text" id="smtpFromName" placeholder="<?php echo htmlspecialchars(t('knowledge.settings.smtp_from_name_placeholder')); ?>">
                        <small><?php echo htmlspecialchars(t('knowledge.settings.smtp_from_name_help')); ?></small>
                    </div>

                    <button type="button" class="btn btn-test" onclick="testSmtp()"><?php echo htmlspecialchars(t('knowledge.settings.test')); ?></button>
                    <div class="test-result" id="smtpTestResult"></div>
                </div>

                <!-- Mailbox Settings -->
                <div class="mailbox-settings" id="mailboxSettings">
                    <h3 style="font-size: 16px; margin-bottom: 15px;"><?php echo htmlspecialchars(t('knowledge.settings.mailbox_heading')); ?></h3>

                    <div class="form-group">
                        <label for="selectedMailbox"><?php echo htmlspecialchars(t('knowledge.settings.mailbox_select')); ?></label>
                        <select id="selectedMailbox">
                            <option value=""><?php echo htmlspecialchars(t('knowledge.settings.mailbox_loading')); ?></option>
                        </select>
                        <small><?php echo htmlspecialchars(t('knowledge.settings.mailbox_help')); ?></small>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('knowledge.settings.save')); ?></button>
                </div>
            </form>
        </div>

        <!-- AI Assistant Tab -->
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'ai')): ?>
        <div class="tab-content<?php echo $activeTabId === 'ai' ? ' active' : ''; ?>" id="ai-tab" data-capability="<?php echo Cap::KNOWLEDGE_AI; ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('knowledge.settings.ai_heading')); ?></h2>
            </div>
            <p style="color: var(--text-muted, #666); margin-bottom: 20px;"><?php echo htmlspecialchars(t('knowledge.settings.ai_intro')); ?></p>

            <!-- Assistant provider/model/key — reusable block (Anthropic / OpenAI / OpenRouter). -->
            <h3 style="font-size: 16px; margin-bottom: 15px;"><?php echo htmlspecialchars(t('knowledge.settings.ai_chat_heading')); ?></h3>
            <?php renderAiSettingsPanel('knowledge_ai'); ?>

            <!-- The assistant: judging whether a ticket contains an article, and
                 drafting it. Its own provider/model/key so the spend is its own
                 line on the bill — but it falls back to the chat key above if
                 left blank, so nobody has to configure two things to get going
                 (see writeupAiConfig() in includes/knowledge/writeup_ai.php). -->
            <h3 style="font-size: 16px; margin: 25px 0 15px 0; padding-top: 20px; border-top: 1px solid var(--border, #e0e0e0);">Assistant</h3>
            <p style="color: var(--text-muted, #666); margin-bottom: 15px; font-size: 13px;">
                Finds the questions your service desk keeps answering that the knowledge base
                does not cover, and drafts the article. Leave the key blank to reuse the one above.
            </p>
            <?php renderAiSettingsPanel('knowledge_writeup'); ?>

            <h4 style="font-size: 14px; margin: 22px 0 12px 0;">When the assistant speaks up</h4>
            <form id="assistantSettingsForm">
                <div class="form-group">
                    <label for="gapMinCluster">Ask the same question this many times before suggesting an article</label>
                    <input type="number" id="gapMinCluster" min="2" max="50" step="1">
                    <small>Lower it if you want an article for anything that comes up twice; raise it on a busy desk.</small>
                </div>
                <div class="form-group">
                    <label for="gapLookback">How far back to read (days)</label>
                    <input type="number" id="gapLookback" min="7" max="730" step="1">
                    <small>Older than this and the answer has usually changed anyway.</small>
                </div>
                <div class="form-group">
                    <label for="gapArticleThreshold">How close an existing article must be to count as covering a ticket</label>
                    <input type="number" id="gapArticleThreshold" min="0.3" max="0.95" step="0.01">
                    <small>Higher means stricter, so more tickets are treated as gaps. 0.75 is a sensible default. Only used when an OpenAI key is set.</small>
                </div>
                <div class="form-group">
                    <label for="gapClusterThreshold">How alike two tickets must be to count as the same question</label>
                    <input type="number" id="gapClusterThreshold" min="0.5" max="0.99" step="0.01">
                    <small>Higher means tighter groups. Too low and unrelated tickets get lumped together. Only used when an OpenAI key is set.</small>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>

            <!-- OpenAI key for semantic-search embeddings — a separate concern from the chat provider. -->
            <h3 style="font-size: 16px; margin: 25px 0 15px 0; padding-top: 20px; border-top: 1px solid var(--border, #e0e0e0);"><?php echo htmlspecialchars(t('knowledge.settings.ai_openai_heading')); ?></h3>
            <form id="aiSettingsForm">
                <div class="form-group">
                    <label for="openaiApiKey"><?php echo htmlspecialchars(t('knowledge.settings.ai_openai_key')); ?></label>
                    <input type="password" id="openaiApiKey" placeholder="sk-proj-...">
                    <small><?php echo t('knowledge.settings.ai_openai_help', ['link' => '<a href="https://platform.openai.com/api-keys" target="_blank" style="color:var(--kb-accent,#8764b8);">platform.openai.com</a>']); ?></small>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('knowledge.settings.save')); ?></button>
                </div>
            </form>
        </div>

        <!-- Embeddings Tab -->
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'embeddings')): ?>
        <div class="tab-content<?php echo $activeTabId === 'embeddings' ? ' active' : ''; ?>" id="embeddings-tab" data-capability="<?php echo Cap::KNOWLEDGE_EMBEDDINGS; ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('knowledge.settings.embeddings_heading')); ?></h2>
            </div>
            <p style="color: var(--text-muted, #666); margin-bottom: 20px;"><?php echo htmlspecialchars(t('knowledge.settings.embeddings_intro')); ?></p>

            <div id="embeddingStatus" style="padding: 15px; background: var(--surface-2, #f9f9f9); border-radius: 6px; margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <strong><?php echo htmlspecialchars(t('knowledge.settings.embedding_status')); ?></strong>
                        <div id="embeddingStats" style="margin-top: 5px; color: var(--text-muted, #666); font-size: 13px;"><?php echo htmlspecialchars(t('knowledge.settings.embed_loading')); ?></div>
                    </div>
                    <button type="button" class="btn btn-primary" id="generateEmbeddingsBtn" onclick="generateEmbeddings()"><?php echo htmlspecialchars(t('knowledge.settings.generate')); ?></button>
                </div>
            </div>

            <div id="embeddingProgress" style="display: none;">
                <div style="margin-bottom: 10px;">
                    <span id="embeddingProgressText"><?php echo htmlspecialchars(t('knowledge.settings.processing')); ?></span>
                </div>
                <div style="background: var(--border, #e0e0e0); border-radius: 4px; height: 8px; overflow: hidden;">
                    <div id="embeddingProgressBar" style="background: var(--kb-accent, #8764b8); height: 100%; width: 0%; transition: width 0.3s;"></div>
                </div>
            </div>

            <div class="test-result" id="embeddingResult"></div>
        </div>

        <!-- Recycle Bin Tab -->
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'recycle-bin')): ?>
        <div class="tab-content<?php echo $activeTabId === 'recycle-bin' ? ' active' : ''; ?>" id="recycle-bin-tab" data-capability="<?php echo Cap::KNOWLEDGE_RECYCLE_BIN; ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('knowledge.settings.recycle_heading')); ?></h2>
            </div>
            <p style="color: var(--text-muted, #666); margin-bottom: 20px;"><?php echo htmlspecialchars(t('knowledge.settings.recycle_intro')); ?></p>
            <form id="recycleBinSettingsForm">
                <div class="form-group">
                    <label for="recycleBinDays"><?php echo htmlspecialchars(t('knowledge.settings.recycle_days_label')); ?></label>
                    <input type="number" id="recycleBinDays" min="0" max="999" value="30" style="max-width: 200px;">
                    <small><?php echo htmlspecialchars(t('knowledge.settings.recycle_days_help')); ?></small>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('knowledge.settings.save')); ?></button>
                </div>
            </form>
        </div>

        <!-- Left panel tab — per-analyst preference -->
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'permissions')): ?>
        <div class="tab-content<?php echo $activeTabId === 'permissions' ? ' active' : ''; ?>" id="permissions-tab" data-capability="<?php echo Cap::KNOWLEDGE_MANAGE; ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('knowledge.settings.perm_model_heading')); ?></h2>
            </div>
            <p style="color: var(--text-muted, #666); margin-bottom: 20px;"><?php echo htmlspecialchars(t('knowledge.settings.perm_model_intro')); ?></p>

            <form id="permModelForm" autocomplete="off" onsubmit="event.preventDefault();">
                <div class="form-group">
                    <label style="display: block; padding: 10px 14px; border: 1px solid var(--border, #ddd); border-radius: 6px; margin-bottom: 8px; cursor: pointer;">
                        <input type="radio" name="kbPermModel" value="containers" onchange="previewPermModel(this.value)">
                        <strong><?php echo htmlspecialchars(t('knowledge.settings.perm_containers_title')); ?></strong>
                        <span style="display: block; font-size: 12px; color: var(--text-dim, #777); margin-top: 4px; margin-left: 22px;">
                            <?php echo htmlspecialchars(t('knowledge.settings.perm_containers_desc')); ?>
                        </span>
                    </label>
                    <label style="display: block; padding: 10px 14px; border: 1px solid var(--border, #ddd); border-radius: 6px; cursor: pointer;">
                        <input type="radio" name="kbPermModel" value="filing" onchange="previewPermModel(this.value)">
                        <strong><?php echo htmlspecialchars(t('knowledge.settings.perm_filing_title')); ?></strong>
                        <span style="display: block; font-size: 12px; color: var(--text-dim, #777); margin-top: 4px; margin-left: 22px;">
                            <?php echo htmlspecialchars(t('knowledge.settings.perm_filing_desc')); ?>
                        </span>
                    </label>
                </div>

                <!-- What the change would actually do, counted, BEFORE anything
                     is written. This setting changes who can read live documents
                     with no per-document change to point at, so a bare "are you
                     sure?" would be asking about something nobody can see. -->
                <div id="permModelPreview" class="kb-perm-preview" style="display:none;"></div>

                <div class="form-actions">
                    <button type="button" class="btn btn-primary" id="permModelApply" style="display:none;" onclick="applyPermModel()">
                        <?php echo htmlspecialchars(t('knowledge.settings.save')); ?>
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Left panel — per-analyst display preference; no capability. -->
        <div class="tab-content<?php echo $activeTabId === 'left-panel' ? ' active' : ''; ?>" id="left-panel-tab" data-capability="none">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('knowledge.settings.left_panel_heading')); ?></h2>
            </div>
            <p style="color: var(--text-muted, #666); margin-bottom: 20px;"><?php echo htmlspecialchars(t('knowledge.settings.left_panel_intro')); ?></p>

            <form id="leftPanelForm" autocomplete="off" onsubmit="event.preventDefault();">
                <div class="form-group">
                    <label style="display: block; margin-bottom: 10px; font-weight: 500; color: var(--text, #333);"><?php echo htmlspecialchars(t('knowledge.settings.visibility')); ?></label>
                    <label style="display: block; padding: 10px 14px; border: 1px solid var(--border, #ddd); border-radius: 6px; margin-bottom: 8px; cursor: pointer;">
                        <input type="radio" name="kbSidebarMode" value="always" onchange="saveSidebarMode(this.value)">
                        <strong><?php echo htmlspecialchars(t('knowledge.settings.always_title')); ?></strong>
                        <span style="display: block; font-size: 12px; color: var(--text-dim, #777); margin-top: 4px; margin-left: 22px;">
                            <?php echo htmlspecialchars(t('knowledge.settings.always_desc')); ?>
                        </span>
                    </label>
                    <label style="display: block; padding: 10px 14px; border: 1px solid var(--border, #ddd); border-radius: 6px; cursor: pointer;">
                        <input type="radio" name="kbSidebarMode" value="hover" onchange="saveSidebarMode(this.value)">
                        <strong><?php echo htmlspecialchars(t('knowledge.settings.hover_title')); ?></strong>
                        <span style="display: block; font-size: 12px; color: var(--text-dim, #777); margin-top: 4px; margin-left: 22px;">
                            <?php echo htmlspecialchars(t('knowledge.settings.hover_desc')); ?>
                        </span>
                    </label>
                </div>

                <!-- WHERE you browse folders. The left panel already carries
                     search, folders, tags, buttons and the bin; folders are the
                     newest and largest thing on it, so being able to move them
                     out is the point of this setting. -->
                <div class="form-group" style="margin-top: 24px;">
                    <label style="display: block; margin-bottom: 10px; font-weight: 500; color: var(--text, #333);"><?php echo htmlspecialchars(t('knowledge.settings.browse_heading')); ?></label>
                    <label style="display: block; padding: 10px 14px; border: 1px solid var(--border, #ddd); border-radius: 6px; margin-bottom: 8px; cursor: pointer;">
                        <input type="radio" name="kbBrowseMode" value="panel" onchange="saveBrowseMode(this.value)">
                        <strong><?php echo htmlspecialchars(t('knowledge.settings.browse_panel_title')); ?></strong>
                        <span style="display: block; font-size: 12px; color: var(--text-dim, #777); margin-top: 4px; margin-left: 22px;">
                            <?php echo htmlspecialchars(t('knowledge.settings.browse_panel_desc')); ?>
                        </span>
                    </label>
                    <label style="display: block; padding: 10px 14px; border: 1px solid var(--border, #ddd); border-radius: 6px; cursor: pointer;">
                        <input type="radio" name="kbBrowseMode" value="explorer" onchange="saveBrowseMode(this.value)">
                        <strong><?php echo htmlspecialchars(t('knowledge.settings.browse_explorer_title')); ?></strong>
                        <span style="display: block; font-size: 12px; color: var(--text-dim, #777); margin-top: 4px; margin-left: 22px;">
                            <?php echo htmlspecialchars(t('knowledge.settings.browse_explorer_desc')); ?>
                        </span>
                    </label>
                </div>
            </form>
        </div>
    </div>

    <script>
        const API_BASE = '../../api/knowledge/';

        // Switch tabs
        function switchTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            const btn = document.querySelector('.tab[data-tab="' + tab + '"]');
            if (btn) btn.classList.add('active');
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.getElementById(tab + '-tab').classList.add('active');
            // Lazy-load the Left panel preference the first time the tab opens.
            if (tab === 'left-panel') { loadSidebarMode(); loadBrowseMode(); }
        }

        // --- Left panel preference ------------------------------------
        // Matches the process-mapper pattern from #324: 'always' vs 'hover',
        // stored per-analyst via user_preferences. The main knowledge page
        // (assets/js/knowledge.js) reads the same key on load and applies a
        // .sidebar-hover class to the .knowledge-container.
        const SIDEBAR_MODE_KEY = 'knowledge_sidebar_mode';
        let sidebarModeLoaded = false;
        async function loadSidebarMode() {
            if (sidebarModeLoaded) return;
            sidebarModeLoaded = true;
            try {
                const r = await fetch('../../api/system/get_user_preference.php?key=' + encodeURIComponent(SIDEBAR_MODE_KEY), { credentials: 'same-origin' });
                const d = await r.json();
                const mode = (d.success && (d.value === 'always' || d.value === 'hover')) ? d.value : 'always';
                document.querySelectorAll('input[name="kbSidebarMode"]').forEach(i => { i.checked = (i.value === mode); });
            } catch (e) {
                const first = document.querySelector('input[name="kbSidebarMode"][value="always"]');
                if (first) first.checked = true;
            }
        }

        // ── The folder permission model ────────────────────────────────────
        // Nothing is saved by picking a radio. Choosing shows what the change
        // would DO — counted, per person — and only then offers to apply it.
        // This is the one setting in the module that changes who can read live
        // documents, with no per-document change to point at afterwards, so a
        // bare "are you sure?" would be asking about something invisible.
        let permModelCurrent = 'containers';

        async function loadPermModel() {
            try {
                const r = await fetch('../../api/knowledge/permission_model.php?action=get');
                const d = await r.json();
                if (!d.success) return;
                permModelCurrent = d.model;
                const el = document.querySelector(`input[name="kbPermModel"][value="${d.model}"]`);
                if (el) el.checked = true;
            } catch (e) { /* leave both unticked rather than showing a guess as fact */ }
        }

        async function previewPermModel(model) {
            const box = document.getElementById('permModelPreview');
            const btn = document.getElementById('permModelApply');
            if (model === permModelCurrent) { box.style.display = 'none'; btn.style.display = 'none'; return; }

            box.style.display = '';
            box.className = 'kb-perm-preview';
            box.textContent = '…';
            btn.style.display = 'none';
            try {
                const r = await fetch('../../api/knowledge/permission_model.php?action=preview&model=' + encodeURIComponent(model));
                const d = await r.json();
                if (!d.success) { box.textContent = d.error || 'Could not work out what this would change.'; return; }

                if (!d.gain && !d.lose) {
                    box.textContent = '<?php echo addslashes(t('knowledge.settings.perm_preview_none')); ?>';
                } else {
                    const parts = [];
                    if (d.gain) parts.push('<?php echo addslashes(t('knowledge.settings.perm_preview_gain')); ?>'.replace('{count}', d.gain));
                    if (d.lose) parts.push('<?php echo addslashes(t('knowledge.settings.perm_preview_lose')); ?>'.replace('{count}', d.lose));
                    box.textContent = parts.join(' ');
                    // Gaining access is the direction worth colouring: it is the
                    // one that discloses rather than merely inconveniences.
                    box.className = 'kb-perm-preview' + (d.gain ? ' is-widening' : '');
                }
                if (d.capped) {
                    box.textContent += ' <?php echo addslashes(t('knowledge.settings.perm_preview_capped')); ?>'.replace('{count}', d.sampled);
                }
                btn.style.display = '';
            } catch (e) {
                box.textContent = 'Could not work out what this would change.';
            }
        }

        async function applyPermModel() {
            const chosen = document.querySelector('input[name="kbPermModel"]:checked');
            if (!chosen) return;
            try {
                const r = await fetch('../../api/knowledge/permission_model.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'set', model: chosen.value })
                });
                const d = await r.json();
                if (!d.success) { showToast(d.error || 'Could not save', 'error'); return; }
                permModelCurrent = d.model;
                document.getElementById('permModelPreview').style.display = 'none';
                document.getElementById('permModelApply').style.display = 'none';
                showToast('<?php echo addslashes(t('knowledge.settings.perm_saved')); ?>', 'success');
            } catch (e) { showToast('Could not save', 'error'); }
        }

        document.addEventListener('DOMContentLoaded', loadPermModel);

        async function saveSidebarMode(value) {
            if (value !== 'always' && value !== 'hover') return;
            try {
                const r = await fetch('../../api/system/set_user_preference.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ key: SIDEBAR_MODE_KEY, value: value })
                });
                const d = await r.json();
                if (d.success) showToast(window.t('knowledge.settings.toast_saved'), 'success');
            } catch (e) { /* no-op */ }
        }

        // Where folders are browsed. Same shape as the sidebar mode above; both
        // are per-analyst display preferences rather than install settings,
        // because two people sharing a service desk genuinely disagree about
        // this and neither is wrong.
        const BROWSE_MODE_KEY = 'knowledge_browse_mode';
        async function loadBrowseMode() {
            try {
                const r = await fetch('../../api/system/get_user_preference.php?key=' + encodeURIComponent(BROWSE_MODE_KEY), { credentials: 'same-origin' });
                const d = await r.json();
                const mode = (d.success && d.value === 'explorer') ? 'explorer' : 'panel';
                document.querySelectorAll('input[name="kbBrowseMode"]').forEach(i => { i.checked = (i.value === mode); });
            } catch (e) {
                const first = document.querySelector('input[name="kbBrowseMode"][value="panel"]');
                if (first) first.checked = true;
            }
        }
        async function saveBrowseMode(value) {
            if (value !== 'panel' && value !== 'explorer') return;
            try {
                const r = await fetch('../../api/system/set_user_preference.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ key: BROWSE_MODE_KEY, value: value })
                });
                const d = await r.json();
                if (d.success) showToast(window.t('knowledge.settings.toast_saved'), 'success');
            } catch (e) { /* no-op */ }
        }

        // Load settings on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadMailboxes();
            loadSettings();
        });

        function toggleEmailMethod(method) {
            // Update radio option styling
            document.querySelectorAll('.radio-option').forEach(opt => opt.classList.remove('selected'));

            if (method === 'smtp') {
                document.getElementById('optionSmtp').classList.add('selected');
                document.getElementById('smtpSettings').classList.add('active');
                document.getElementById('mailboxSettings').classList.remove('active');
            } else if (method === 'mailbox') {
                document.getElementById('optionMailbox').classList.add('selected');
                document.getElementById('smtpSettings').classList.remove('active');
                document.getElementById('mailboxSettings').classList.add('active');
            } else {
                document.getElementById('optionDisabled').classList.add('selected');
                document.getElementById('smtpSettings').classList.remove('active');
                document.getElementById('mailboxSettings').classList.remove('active');
            }
        }

        function toggleSmtpAuth() {
            const authRequired = document.getElementById('smtpAuth').value === 'yes';
            document.getElementById('smtpAuthFields').style.display = authRequired ? 'block' : 'none';
        }

        async function loadMailboxes() {
            try {
                const response = await fetch('../../api/tickets/get_mailboxes.php');
                const data = await response.json();

                const select = document.getElementById('selectedMailbox');
                select.innerHTML = '<option value="">' + window.t('knowledge.settings.mailbox_default') + '</option>';

                if (data.success && data.mailboxes) {
                    data.mailboxes.forEach(mailbox => {
                        const option = document.createElement('option');
                        option.value = mailbox.id;
                        option.textContent = `${mailbox.name} (${mailbox.mailbox_email})`;
                        select.appendChild(option);
                    });
                }
            } catch (error) {
                console.error('Error loading mailboxes:', error);
            }
        }

        async function loadSettings() {
            try {
                const response = await fetch(API_BASE + 'get_email_settings.php');
                const data = await response.json();

                if (data.success && data.settings) {
                    const s = data.settings;

                    // Set email method
                    const method = s.email_method || 'disabled';
                    document.querySelector(`input[name="email_method"][value="${method}"]`).checked = true;
                    toggleEmailMethod(method);

                    // Set SMTP fields
                    document.getElementById('smtpHost').value = s.smtp_host || '';
                    document.getElementById('smtpPort').value = s.smtp_port || '587';
                    document.getElementById('smtpEncryption').value = s.smtp_encryption || 'tls';
                    document.getElementById('smtpAuth').value = s.smtp_auth || 'yes';
                    document.getElementById('smtpUsername').value = s.smtp_username || '';
                    // Don't populate password for security
                    document.getElementById('smtpFromEmail').value = s.smtp_from_email || '';
                    document.getElementById('smtpFromName').value = s.smtp_from_name || '';
                    toggleSmtpAuth();

                    // Set mailbox
                    if (s.mailbox_id) {
                        document.getElementById('selectedMailbox').value = s.mailbox_id;
                    }
                }
            } catch (error) {
                console.error('Error loading settings:', error);
            }
        }

        document.getElementById('emailSettingsForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const settings = {
                email_method: document.querySelector('input[name="email_method"]:checked').value,
                smtp_host: document.getElementById('smtpHost').value,
                smtp_port: document.getElementById('smtpPort').value,
                smtp_encryption: document.getElementById('smtpEncryption').value,
                smtp_auth: document.getElementById('smtpAuth').value,
                smtp_username: document.getElementById('smtpUsername').value,
                smtp_password: document.getElementById('smtpPassword').value,
                smtp_from_email: document.getElementById('smtpFromEmail').value,
                smtp_from_name: document.getElementById('smtpFromName').value,
                mailbox_id: document.getElementById('selectedMailbox').value
            };

            try {
                const response = await fetch(API_BASE + 'save_email_settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ settings })
                });
                const data = await response.json();

                if (data.success) {
                    showToast(window.t('knowledge.settings.toast_settings_saved'), 'success');
                } else {
                    showToast(window.t('knowledge.settings.toast_error', { message: data.error }), 'error');
                }
            } catch (error) {
                console.error('Error saving settings:', error);
                showToast(window.t('knowledge.settings.toast_save_failed'), 'error');
            }
        });

        async function testSmtp() {
            const resultDiv = document.getElementById('smtpTestResult');
            resultDiv.className = 'test-result';
            resultDiv.style.display = 'block';
            resultDiv.textContent = window.t('knowledge.settings.test_connecting');

            const settings = {
                smtp_host: document.getElementById('smtpHost').value,
                smtp_port: document.getElementById('smtpPort').value,
                smtp_encryption: document.getElementById('smtpEncryption').value,
                smtp_auth: document.getElementById('smtpAuth').value,
                smtp_username: document.getElementById('smtpUsername').value,
                smtp_password: document.getElementById('smtpPassword').value
            };

            try {
                const response = await fetch(API_BASE + 'test_smtp.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(settings)
                });
                const data = await response.json();

                if (data.success) {
                    resultDiv.className = 'test-result success';
                    resultDiv.textContent = window.t('knowledge.settings.test_smtp_ok');
                } else {
                    resultDiv.className = 'test-result error';
                    resultDiv.textContent = window.t('knowledge.settings.test_smtp_failed', { message: data.error });
                }
            } catch (error) {
                resultDiv.className = 'test-result error';
                resultDiv.textContent = window.t('knowledge.settings.test_error', { message: error.message });
            }
        }

        // === AI Settings ===

        // Load AI API key and recycle bin settings on page load
        loadAiSettings();
        loadEmbeddingStats();
        loadRecycleBinSettings();

        async function loadAiSettings() {
            try {
                const response = await fetch(API_BASE + 'get_email_settings.php');
                const data = await response.json();
                if (data.success && data.settings) {
                    // The Anthropic/chat key is now owned by the reusable AI panel above;
                    // this form only handles the OpenAI embeddings key.
                    if (data.settings.openai_api_key) {
                        document.getElementById('openaiApiKey').placeholder = window.t('knowledge.settings.key_saved_placeholder');
                    }
                }
            } catch (error) {
                console.error('Error loading AI settings:', error);
            }
        }

        /* The assistant's tunables. Values are clamped server-side too — the
           number inputs' min/max are a convenience, not the guard. */
        (function initAssistantSettings() {
            const form = document.getElementById('assistantSettingsForm');
            if (!form) return;

            const fields = {
                gapMinCluster:       'knowledge_gap_min_cluster',
                gapLookback:         'knowledge_gap_lookback_days',
                gapArticleThreshold: 'knowledge_gap_article_threshold',
                gapClusterThreshold: 'knowledge_gap_cluster_threshold'
            };

            fetch(API_BASE + 'assistant_settings.php')
                .then(r => r.json())
                .then(d => {
                    if (!d.success) return;
                    Object.entries(fields).forEach(([id, key]) => {
                        const el = document.getElementById(id);
                        if (el && d.settings[key] !== undefined) el.value = d.settings[key];
                    });
                })
                .catch(() => { /* defaults still apply server-side */ });

            form.addEventListener('submit', async function(e) {
                e.preventDefault();
                const payload = {};
                Object.entries(fields).forEach(([id, key]) => {
                    const el = document.getElementById(id);
                    if (el && el.value !== '') payload[key] = el.value;
                });
                try {
                    const res = await fetch(API_BASE + 'assistant_settings.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    });
                    const data = await res.json();
                    if (data.success) {
                        // Reflect what the server actually stored — a value it
                        // clamped must not keep showing the number you typed.
                        Object.entries(fields).forEach(([id, key]) => {
                            const el = document.getElementById(id);
                            if (el && data.saved[key] !== undefined) el.value = data.saved[key];
                        });
                        showToast('Saved', 'success');
                    } else {
                        showToast(data.error || 'Could not save', 'error');
                    }
                } catch (err) {
                    showToast('Could not save', 'error');
                }
            });
        })();

        document.getElementById('aiSettingsForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            // This form now saves only the OpenAI embeddings key — the chat
            // provider/model/key is handled by the reusable AI panel above.
            const openaiKey = document.getElementById('openaiApiKey').value.trim();

            if (!openaiKey) {
                showToast(window.t('knowledge.settings.toast_need_key'), 'error');
                return;
            }

            try {
                const response = await fetch(API_BASE + 'save_email_settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ settings: { openai_api_key: openaiKey } })
                });
                const data = await response.json();

                if (data.success) {
                    showToast(window.t('knowledge.settings.toast_ai_saved'), 'success');
                    document.getElementById('openaiApiKey').value = '';
                    document.getElementById('openaiApiKey').placeholder = window.t('knowledge.settings.key_saved_placeholder');
                } else {
                    showToast(window.t('knowledge.settings.toast_error', { message: data.error }), 'error');
                }
            } catch (error) {
                console.error('Error saving AI settings:', error);
                showToast(window.t('knowledge.settings.toast_ai_save_failed'), 'error');
            }
        });

        // === Embedding Functions ===

        async function loadEmbeddingStats() {
            try {
                const response = await fetch(API_BASE + 'get_embedding_stats.php');
                const data = await response.json();

                const statsDiv = document.getElementById('embeddingStats');
                if (data.success) {
                    const { total, with_embeddings, without_embeddings } = data.stats;
                    if (total === 0) {
                        statsDiv.textContent = window.t('knowledge.settings.embed_none');
                    } else if (without_embeddings === 0) {
                        statsDiv.innerHTML = `<span style="color: var(--success-text, #155724);">${window.t('knowledge.settings.embed_all', { total: total })}</span>`;
                    } else {
                        statsDiv.innerHTML = window.t('knowledge.settings.embed_partial', { with: with_embeddings, total: total, missing: without_embeddings });
                    }
                } else {
                    statsDiv.textContent = window.t('knowledge.settings.embed_error', { message: data.error });
                }
            } catch (error) {
                document.getElementById('embeddingStats').textContent = window.t('knowledge.settings.embed_error_short');
            }
        }

        async function generateEmbeddings() {
            const btn = document.getElementById('generateEmbeddingsBtn');
            const progressDiv = document.getElementById('embeddingProgress');
            const progressBar = document.getElementById('embeddingProgressBar');
            const progressText = document.getElementById('embeddingProgressText');
            const resultDiv = document.getElementById('embeddingResult');

            btn.disabled = true;
            btn.textContent = window.t('knowledge.settings.embed_generating');
            progressDiv.style.display = 'block';
            resultDiv.className = 'test-result';
            resultDiv.style.display = 'none';

            try {
                // Get list of articles needing embeddings
                const listResponse = await fetch(API_BASE + 'get_articles_for_embedding.php');
                const listData = await listResponse.json();

                if (!listData.success) {
                    throw new Error(listData.error || window.t('knowledge.settings.embed_get_failed'));
                }

                const articles = listData.articles;
                if (articles.length === 0) {
                    progressDiv.style.display = 'none';
                    resultDiv.className = 'test-result success';
                    resultDiv.textContent = window.t('knowledge.settings.embed_all_done');
                    btn.disabled = false;
                    btn.textContent = window.t('knowledge.settings.generate');
                    return;
                }

                let processed = 0;
                let errors = 0;

                for (const article of articles) {
                    progressText.textContent = window.t('knowledge.settings.embed_processing', { title: article.title, current: processed + 1, total: articles.length });
                    progressBar.style.width = ((processed / articles.length) * 100) + '%';

                    try {
                        const response = await fetch(API_BASE + 'generate_embedding.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ article_id: article.id })
                        });
                        const data = await response.json();

                        if (!data.success) {
                            console.error(`Error for article ${article.id}:`, data.error);
                            errors++;
                        }
                    } catch (err) {
                        console.error(`Error for article ${article.id}:`, err);
                        errors++;
                    }

                    processed++;
                }

                progressBar.style.width = '100%';
                progressDiv.style.display = 'none';

                if (errors === 0) {
                    resultDiv.className = 'test-result success';
                    resultDiv.textContent = window.t('knowledge.settings.embed_success', { count: processed });
                } else {
                    resultDiv.className = 'test-result error';
                    resultDiv.textContent = window.t('knowledge.settings.embed_errors', { errors: errors, total: processed });
                }

                loadEmbeddingStats();

            } catch (error) {
                progressDiv.style.display = 'none';
                resultDiv.className = 'test-result error';
                resultDiv.textContent = window.t('knowledge.settings.toast_error', { message: error.message });
            }

            btn.disabled = false;
            btn.textContent = window.t('knowledge.settings.generate');
        }

        // === Recycle Bin Settings ===

        async function loadRecycleBinSettings() {
            try {
                const response = await fetch(API_BASE + 'get_email_settings.php');
                const data = await response.json();
                if (data.success && data.settings) {
                    const days = data.settings.recycle_bin_days ?? 30;
                    document.getElementById('recycleBinDays').value = days;
                }
            } catch (error) {
                console.error('Error loading recycle bin settings:', error);
            }
        }

        document.getElementById('recycleBinSettingsForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const parsed = parseInt(document.getElementById('recycleBinDays').value, 10);
            const days = Math.max(0, Math.min(999, isNaN(parsed) ? 30 : parsed));
            document.getElementById('recycleBinDays').value = days;

            try {
                const response = await fetch(API_BASE + 'save_email_settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ settings: { recycle_bin_days: days } })
                });
                const data = await response.json();

                if (data.success) {
                    showToast(window.t('knowledge.settings.toast_settings_saved'), 'success');
                } else {
                    showToast(window.t('knowledge.settings.toast_error', { message: data.error }), 'error');
                }
            } catch (error) {
                console.error('Error saving recycle bin settings:', error);
                showToast(window.t('knowledge.settings.toast_save_failed'), 'error');
            }
        });
    </script>
    <script src="../../assets/js/mobile.js?v=53"></script>
</body>
</html>
