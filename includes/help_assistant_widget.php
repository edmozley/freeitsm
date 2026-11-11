<?php
/**
 * The help assistant's header button and window (wiki Help-Assistant-Design).
 *
 * Called from renderHeaderRight() (includes/waffle-menu.php), so it is on every
 * module's header. Draws nothing unless the help assistant is switched on for the
 * install and for this analyst (helpAssistantAllowed()) - and nothing at all on an
 * install that has not run Database Verify yet. Any failure draws nothing: this is
 * the header on every page, and a help button is never worth an error.
 *
 * The window itself is built by assets/js/help-assistant.js on first open; this
 * hands it the endpoints, the module the page belongs to and its strings in the
 * analyst's language. Its classes are prefixed hlpa- because it shares pages with
 * everything else - System -> Help assistant uses ha-.
 */

require_once __DIR__ . '/help_assistant.php';

/**
 * The button and what the window needs, or nothing.
 *
 * @param ?string $module  the module the page belongs to ($current_module) - a search tie-breaker
 */
function helpAssistantWidgetRender(?string $module): void
{
    try {
        if (empty($_SESSION['analyst_id'])) return;
        if (!function_exists('connectToDatabase')) require_once __DIR__ . '/functions.php';
        $conn = connectToDatabase();
        if (!helpAssistantAllowed($conn, (int)$_SESSION['analyst_id'])) return;
    } catch (Throwable $e) {
        return;
    }
    $base = defined('BASE_URL') ? BASE_URL : '/';
    $keys = ['title', 'button', 'greeting', 'mode_search', 'mode_meaning', 'mode_ask', 'sources', 'thinking', 'named_notice', 'placeholder', 'send', 'reset', 'close', 'open', 'also', 'searching', 'error'];
    $strings = [];
    foreach ($keys as $k) $strings[$k] = t('help_assistant.' . $k);
    $config = [
        'ask'     => $base . 'api/help_assistant/ask.php',
        'history' => $base . 'api/help_assistant/history.php',
        'reset'   => $base . 'api/help_assistant/reset.php',
        'module'  => $module,
        // 'search' or 'search_meaning' - which note the window shows under its header.
        'level'   => helpAssistantLevel($conn)['level'],
        // An admin chose to record this analyst's name with unanswered questions -
        // the window says so (wiki Help-Assistant-Design: never silently).
        'named'   => helpAssistantNamed($conn, (int)$_SESSION['analyst_id']),
        'strings' => $strings,
    ];
    ?>
    <button type="button" class="hlpa-launch" id="hlpaLaunch" onclick="window.helpAssistantToggle && window.helpAssistantToggle()"
            title="<?php echo htmlspecialchars($strings['button']); ?>" aria-label="<?php echo htmlspecialchars($strings['button']); ?>">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path><path d="M9.6 9.2a2.5 2.5 0 0 1 4.8.8c0 1.7-2.4 2.3-2.4 2.3"></path><line x1="12" y1="15.5" x2="12.01" y2="15.5"></line></svg>
    </button>
    <link rel="stylesheet" href="<?php echo $base; ?>assets/css/help-assistant.css?v=3">
    <script>window.HELP_ASSISTANT = <?php echo json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="<?php echo $base; ?>assets/js/help-assistant.js?v=5" defer></script>
    <?php
}
