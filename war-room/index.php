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

// A permanent link (5.9.0): ?message=<id> opens that message's channel scrolled to
// it. Checked the same way as ?channel= - a message in a channel the analyst cannot
// read, or one that no longer exists, gets the same "not available" either way.
$focusId = isset($_GET['message']) ? max(0, (int) $_GET['message']) : 0;
$focusMissing = false;
if ($focusId > 0) {
    $focusChannel = warRoomChannelOfMessage($conn, $analystId, $focusId);
    if ($focusChannel > 0) $activeId = $focusChannel;
    else { $focusMissing = true; $focusId = 0; }
}

$myName    = $_SESSION['analyst_name'] ?? '';
// Database Verification not run since an upgrade (5.9.0): administrators are told,
// and the chat runs without the newer features rather than failing.
$schemaBehind = warRoomSchemaBehind($conn);
$canManage = analystHasCapability($conn, $analystId, Cap::WAR_ROOM_MANAGE);

// Per-analyst preferences. Both are personal habits rather than administrator
// decisions: whether a popup is welcome, and how you like to type a name.
$prefs = [];
$dp = $conn->prepare(
    "SELECT preference_key, preference_value FROM user_preferences
      WHERE analyst_id = :a AND preference_key IN ('warroom_desktop_alerts','warroom_mention_style','warroom_translate')"
);
$dp->execute([':a' => $analystId]);
foreach ($dp->fetchAll(PDO::FETCH_ASSOC) as $r) $prefs[$r['preference_key']] = (string) $r['preference_value'];

// Translation (5.9.0): offered only when an administrator has switched it on and an
// AI provider is set up; then each person chooses, on unless they turn it off.
$translateAvailable = warRoomTranslateEnabled($conn);
$translateOn = $translateAvailable && ($prefs['warroom_translate'] ?? '1') === '1';

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
    <title><?php echo htmlspecialchars(pageTitle('war-room')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=29">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=97">
    <link rel="stylesheet" href="../assets/css/war-room.css?v=15">
    <link rel="stylesheet" href="../assets/css/float-window.css?v=2"><!-- the shared file viewer (5.9.0) -->
    <link rel="stylesheet" href="../assets/css/files-picker.css?v=1">
    <link rel="stylesheet" href="../assets/css/mobile.css?v=201">
    <style>
        /* Pin the shared accent to the module's amber so buttons and focus
           rings are on-brand, the same way every other module does it. */
        body { --accent: var(--war-room-accent, #ea580c); --accent-hover: var(--war-room-accent-hover, #c2410c); }
    </style>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=4"></script>
</head>
<body data-mobile-module="war-room" data-mobile-page="war-room">
    <?php include 'includes/header.php'; ?>
    <?php /* The action row (#185): New channel and New message moved here from
             the top of the channel list. A direct message keeps its own words;
             a channel is the record, so it is New. war-room.js wires both by id. */
          require_once __DIR__ . '/../includes/action_row.php';
          renderActionRow(t('war-room.title'), [
              ['label' => t('war-room.channel.new_dm'), 'id' => 'wrNewDm'],
              actionRowNew('', t('war-room.channel.new'), false, 'wrNewChannel'),
          ]); ?>

    <div class="wr-container">
        <aside class="wr-sidebar">
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

            <!-- Your own choices - desktop notification, your language, how a picked name
                 is written - live in Preferences -> War room (5.9.0): this panel had no
                 room to explain them. Still per analyst, never an administrator's. -->
            <a class="wr-prefs-link" href="<?php echo BASE_URL; ?>system/preferences/#war-room">&#9881; <?php echo htmlspecialchars(t('war-room.prefs.link')); ?></a>
        </aside>

        <main class="wr-main">
            <div class="wr-topbar">
                <div class="wr-title-wrap">
                    <h2 class="wr-title" id="wrTitle"><?php echo htmlspecialchars($allHands['name'] ?? ''); ?></h2>
                    <div class="wr-topic" id="wrTopic"></div>
                </div>
                <div class="wr-tools">
                    <button type="button" class="wr-tool" id="wrSearchBtn"><?php echo htmlspecialchars(t('war-room.search.heading')); ?></button>
                    <button type="button" class="wr-tool" id="wrSavedBtn"><?php echo htmlspecialchars(t('war-room.saved.button')); ?></button>
                    <button type="button" class="wr-tool" id="wrSitrepBtn"><?php echo htmlspecialchars(t('war-room.sitrep.button')); ?></button>
                    <button type="button" class="wr-tool" id="wrManageBtn" hidden><?php echo htmlspecialchars(t('war-room.manage.heading')); ?></button>
                </div>
            </div>

            <p class="wr-intro"><?php echo htmlspecialchars(t('war-room.intro')); ?></p>
            <?php if ($schemaBehind && (sessionIsAdmin() || $canManage)): ?>
            <div class="wr-schema-note" role="status">
                <?php echo htmlspecialchars(t('war-room.schema_behind')); ?>
                <a href="<?php echo BASE_URL; ?>system/db-verify/"><?php echo htmlspecialchars(t('war-room.schema_behind_link')); ?></a>
            </div>
            <?php endif; ?>

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
        window.WR_FOCUS     = <?php echo (int) $focusId; ?>;
        window.WR_FOCUS_MISSING = <?php echo $focusMissing ? 'true' : 'false'; ?>;
        window.WR_LINK_BASE = <?php echo json_encode(BASE_URL . 'war-room/'); ?>;
        window.WR_TRANSLATE = <?php echo json_encode(['available' => $translateAvailable, 'on' => $translateOn, 'lang' => I18n::getLocale()]); ?>;
        window.WR_SEEN_BY   = <?php echo warRoomSeenByEnabled($conn) ? 'true' : 'false'; ?>;
        window.WR_FILES     = <?php echo analystCanAccessModule($conn, $analystId, 'files') ? 'true' : 'false'; ?>;
    </script>
    <!-- Attachments open in the viewer Files and Tickets use (5.9.0). Its own
         libraries (pdf.js and the rest) ship with FreeITSM and load on first use. -->
    <script src="../assets/js/float-window.js?v=3"></script>
    <script src="../assets/js/files-icons.js?v=4"></script>
    <script src="../assets/js/files-viewer.js?v=10"></script>
    <!-- + -> Files (5.9.0): the Files picker, as Tickets uses it. -->
    <script src="../assets/js/files-picker.js?v=3"></script>
    <script src="../assets/js/war-room.js?v=16"></script>
    <script src="../assets/js/mobile.js?v=87"></script>
</body>
</html>
