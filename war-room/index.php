<?php
/**
 * War room — chat that still works when Teams, Slack and the internet do not.
 *
 * ⚠️ NO EXTERNAL ASSETS ON THIS PAGE, EVER. Everything it loads must come from
 * this server. A page whose whole purpose is "the internet is down" cannot pull
 * a script from a CDN, a font from Google, or anything else that needs a name
 * to resolve beyond the LAN. Four other pages in the app do use cdnjs; this one
 * must not join them. The situation report is the single exception and it is an
 * outbound API call from the SERVER, made only when somebody asks for it — the
 * page itself still loads nothing.
 *
 * The channel list is rendered server-side for first paint and then refreshed by
 * the same poll that fetches messages. See includes/warroom.php for what a
 * channel actually is, which differs per kind.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
require_once '../includes/rbac.php';
require_once '../includes/warroom.php';
requireModuleAccess('war-room');
I18n::initFromSession();
Tz::init();

$current_page          = 'war-room';
$path_prefix           = '../';
$translationNamespaces = ['common', 'war-room'];

$conn      = connectToDatabase();
$analystId = (int) $_SESSION['analyst_id'];
$channels  = warRoomChannelList($conn, $analystId);
$directory = warRoomDirectory($conn, $analystId);

// The all-hands room is always first, and is where an analyst lands — unless the
// notifications panel sent them here to read a specific mention, in which case
// land them on that channel. Validated against the list they can actually see, so
// a hand-edited ?channel= cannot open anything.
$activeId = $channels ? (int) $channels[0]['id'] : 0;
if (isset($_GET['channel'])) {
    $wanted = (int) $_GET['channel'];
    foreach ($channels as $ch) {
        if ((int) $ch['id'] === $wanted) { $activeId = $wanted; break; }
    }
}

$myName    = $_SESSION['analyst_name'] ?? '';
$canManage = analystHasCapability($conn, $analystId, Cap::WAR_ROOM_MANAGE);

// Per-analyst preferences. Both are personal habits rather than administrator
// decisions: whether a popup is welcome, and how you like to type a name.
$prefs = [];
$dp = $conn->prepare(
    "SELECT preference_key, preference_value FROM user_preferences
      WHERE analyst_id = :a AND preference_key IN ('warroom_desktop_alerts','warroom_mention_style')"
);
$dp->execute([':a' => $analystId]);
foreach ($dp->fetchAll(PDO::FETCH_ASSOC) as $r) $prefs[$r['preference_key']] = (string) $r['preference_value'];

$desktopAlerts = ($prefs['warroom_desktop_alerts'] ?? '') === '1';

// 'short' is the default because a war room has a handful of analysts, not a
// five-thousand-person workspace: a first name is nearly always unique here, so
// carrying a surname you do not need is pure friction. Slack needs full names
// because at its scale it has twelve Sarahs; we do not.
$mentionStyle = $prefs['warroom_mention_style'] ?? 'short';
if (!in_array($mentionStyle, ['short', 'full', 'strip'], true)) $mentionStyle = 'short';

/** Group the flat list into the sidebar's sections. */
$grouped = ['team' => [], 'custom' => [], 'dm' => []];
$allHands = null;
foreach ($channels as $ch) {
    if ($ch['kind'] === WARROOM_KIND_ALL) { $allHands = $ch; continue; }
    $grouped[$ch['kind']][] = $ch;
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('war-room.title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=62">
    <link rel="stylesheet" href="../assets/css/war-room.css?v=6">
    <link rel="stylesheet" href="../assets/css/mobile.css?v=132">
    <style>
        /* Pin the shared accent to the module's amber so buttons and focus
           rings are on-brand, the same way every other module does it. */
        body { --accent: var(--war-room-accent, #ea580c); --accent-hover: var(--war-room-accent-hover, #c2410c); }
    </style>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="wr-container">
        <aside class="wr-sidebar">
            <div class="wr-sidebar-actions">
                <button type="button" class="wr-side-btn" id="wrNewChannel"><?php echo htmlspecialchars(t('war-room.channel.new')); ?></button>
                <button type="button" class="wr-side-btn" id="wrNewDm"><?php echo htmlspecialchars(t('war-room.channel.new_dm')); ?></button>
            </div>

            <div class="wr-channels" id="wrChannels">
                <?php if ($allHands !== null): ?>
                    <button type="button" class="wr-channel active" data-channel-id="<?php echo (int) $allHands['id']; ?>" data-kind="all">
                        <span class="wr-channel-name"><?php echo htmlspecialchars($allHands['name']); ?></span>
                    </button>
                <?php endif; ?>

                <?php foreach (['team' => 'teams', 'custom' => 'channels', 'dm' => 'direct'] as $kind => $labelKey): ?>
                    <?php if ($grouped[$kind]): ?>
                        <div class="wr-group"><?php echo htmlspecialchars(t('war-room.channel.' . $labelKey)); ?></div>
                        <?php foreach ($grouped[$kind] as $ch): ?>
                            <button type="button" class="wr-channel" data-channel-id="<?php echo (int) $ch['id']; ?>" data-kind="<?php echo htmlspecialchars($ch['kind']); ?>">
                                <span class="wr-channel-name"><?php echo htmlspecialchars($ch['name']); ?></span>
                                <?php if ($ch['is_private'] && $ch['kind'] === WARROOM_KIND_CUSTOM): ?><span class="wr-lock" title="<?php echo htmlspecialchars(t('war-room.channel.private')); ?>">•</span><?php endif; ?>
                            </button>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <!-- Who's here lives beside the channel list, not under the
                 conversation: it belongs with "where am I and who is around"
                 rather than competing with the message you are typing. -->
            <div class="wr-presence" id="wrPresence"></div>

            <!-- Per-analyst, not per-install: whether a popup is welcome or
                 infuriating is a personal answer, not an administrator's. -->
            <label class="wr-desktop-opt">
                <input type="checkbox" id="wrDesktopAlerts"<?php echo $desktopAlerts ? ' checked' : ''; ?>>
                <span><?php echo htmlspecialchars(t('war-room.mention.desktop')); ?></span>
            </label>

            <div class="wr-pref">
                <label class="wr-pref-label" for="wrMentionStyle"><?php echo htmlspecialchars(t('war-room.mention.style_label')); ?></label>
                <select id="wrMentionStyle">
                    <?php foreach (['short', 'full', 'strip'] as $opt): ?>
                        <option value="<?php echo $opt; ?>"<?php echo $mentionStyle === $opt ? ' selected' : ''; ?>>
                            <?php echo htmlspecialchars(t('war-room.mention.style_' . $opt)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="wr-pref-hint"><?php echo htmlspecialchars(t('war-room.mention.style_hint')); ?></div>
            </div>
        </aside>

        <main class="wr-main">
            <div class="wr-topbar">
                <div class="wr-title-wrap">
                    <h2 class="wr-title" id="wrTitle"><?php echo htmlspecialchars($allHands['name'] ?? ''); ?></h2>
                    <div class="wr-topic" id="wrTopic"></div>
                </div>
                <div class="wr-tools">
                    <button type="button" class="wr-tool" id="wrSearchBtn"><?php echo htmlspecialchars(t('war-room.search.heading')); ?></button>
                    <button type="button" class="wr-tool" id="wrSitrepBtn"><?php echo htmlspecialchars(t('war-room.sitrep.button')); ?></button>
                    <button type="button" class="wr-tool" id="wrManageBtn" hidden><?php echo htmlspecialchars(t('war-room.manage.heading')); ?></button>
                </div>
            </div>

            <p class="wr-intro"><?php echo htmlspecialchars(t('war-room.intro')); ?></p>

            <div class="wr-messages" id="wrMessages">
                <div class="wr-empty" id="wrEmpty"><?php echo htmlspecialchars(t('war-room.empty')); ?></div>
            </div>

            <div class="wr-archived-note" id="wrArchivedNote" hidden><?php echo htmlspecialchars(t('war-room.composer.archived')); ?></div>

            <form class="wr-composer" id="wrComposer" autocomplete="off">
                <label class="wr-attach" id="wrAttachLabel" title="<?php echo htmlspecialchars(t('war-room.composer.attach')); ?>">
                    <input type="file" id="wrFiles" multiple hidden>
                    <span aria-hidden="true">+</span>
                    <span class="wr-sr"><?php echo htmlspecialchars(t('war-room.composer.attach')); ?></span>
                </label>
                <textarea id="wrBody" rows="1"
                          placeholder="<?php echo htmlspecialchars(t('war-room.composer.placeholder')); ?>"
                          maxlength="<?php echo WARROOM_MAX_BODY; ?>"></textarea>
                <button type="submit" class="btn btn-primary" id="wrSend"><?php echo htmlspecialchars(t('war-room.composer.send')); ?></button>
            </form>
            <div class="wr-pending" id="wrPending" hidden></div>
        </main>

        <!-- Search and the situation report are panels rather than pages, so you
             never lose sight of the conversation while using either. -->
        <aside class="wr-panel" id="wrPanel" hidden>
            <div class="wr-panel-head">
                <h3 id="wrPanelTitle"></h3>
                <button type="button" class="wr-panel-close" id="wrPanelClose" aria-label="Close">&times;</button>
            </div>
            <div class="wr-panel-body" id="wrPanelBody"></div>
        </aside>
    </div>

    <!-- Dialogs. Plain markup filled in by war-room.js; no template library. -->
    <div class="wr-modal" id="wrModal" hidden>
        <div class="wr-modal-card" role="dialog" aria-modal="true">
            <div class="wr-modal-head">
                <h3 id="wrModalTitle"></h3>
                <button type="button" class="wr-panel-close" id="wrModalClose" aria-label="Close">&times;</button>
            </div>
            <div class="wr-modal-body" id="wrModalBody"></div>
        </div>
    </div>

    <script>
        window.API_BASE   = '<?php echo BASE_URL; ?>api/war-room/';
        window.WR_ACTIVE  = <?php echo (int) $activeId; ?>;
        window.WR_ME      = <?php echo (int) $analystId; ?>;
        window.WR_MAX_FILES = <?php echo WARROOM_MAX_ATTACHMENTS; ?>;
        window.WR_DIRECTORY = <?php echo json_encode($directory, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
        window.WR_MY_NAME   = <?php echo json_encode($myName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
        window.WR_CAN_MANAGE = <?php echo $canManage ? 'true' : 'false'; ?>;
        window.WR_PREF_URL  = '<?php echo BASE_URL; ?>api/system/set_user_preference.php';
        window.WR_MENTION_STYLE = <?php echo json_encode($mentionStyle); ?>;
    </script>
    <script src="../assets/js/war-room.js?v=8"></script>
    <script src="../assets/js/mobile.js?v=55"></script>
</body>
</html>
