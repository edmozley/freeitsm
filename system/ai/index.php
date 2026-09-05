<?php
/**
 * System — AI thinking.
 *
 * One switch per AI feature: may this feature use a model's EXTENDED THINKING?
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * 🔑 WHY THIS EXISTS, AND WHY IT DEFAULTS TO OFF
 *
 * A reasoning model spends its output budget thinking before it writes a single
 * character. Measured here, summarising a two-message ticket on
 * qwen/qwen3.7-plus through OpenRouter:
 *
 *     thinking on   54.7s   2,927 reasoning tokens   1,020 characters of answer
 *     thinking off   6.9s           0 tokens         1,376 characters of answer
 *
 * Eight times faster, and the FAST answer was the better one — it caught details
 * the slow one missed. That is not a fluke of one prompt: every AI feature in
 * FreeITSM summarises, extracts or drafts from text that is already in front of
 * it. None of them is a puzzle, which is what extended thinking is for.
 *
 * So thinking is off unless somebody asks for it, per feature — and a feature
 * whose job turns out to reward deliberation can have it back with one click.
 *
 * ⚠️ WHAT IT DOES NOT DO. The switch is sent to OpenRouter and nowhere else,
 * because `reasoning` is OpenRouter's own parameter and OpenAI's API rejects an
 * unknown field outright. A feature configured against Anthropic or OpenAI is
 * listed here with its provider named and the switch marked as having no effect,
 * rather than being hidden — because "why is this one not here?" is a worse
 * question than "why is this one greyed out?".
 *
 * THE LIST IS THE REGISTRY. Rows come from aiSettingsRegistry(), so a tenth AI
 * feature appears here the moment it is registered, with nothing to remember.
 * The same is true of the setting keys: includes/settings_keys.php generates
 * <ns>_reasoning from the same registry, so a new feature is writable here
 * without a second edit.
 *
 * State is rendered SERVER-SIDE into the checkboxes rather than fetched after
 * load. A page that loads its state over the wire shows a plausible-looking
 * default when the fetch fails, and Save then writes that guess back as fact —
 * the same trap documented on the Security and Date format pages.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
require_once '../../includes/encryption.php';
require_once '../../includes/ai_settings.php';
I18n::initFromSession();
Tz::init();

$current_page = 'ai';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];

/* Every registered feature, with what it is configured against — the provider
   is what decides whether this switch does anything, so it is shown rather than
   left for somebody to go and look up on another page. */
$features = [];
try {
    $conn = connectToDatabase();
    foreach (aiSettingsRegistry() as $ns => $entry) {
        $cfg = aiSettingsLoad($conn, $ns);
        $features[] = [
            'ns'        => $ns,
            'label'     => $entry['label'],
            'provider'  => $cfg['provider'],
            'model'     => $cfg['model'],
            'has_key'   => $cfg['api_key'] !== '',
            'reasoning' => (bool)$cfg['reasoning'],
            // Only OpenRouter understands the parameter. Said per row, honestly.
            'applies'   => $cfg['provider'] === 'openrouter',
        ];
    }
} catch (Exception $e) {
    // An unreachable database leaves the list empty; the page says so rather
    // than rendering switches that would write guesses back.
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('system.ai.title')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=65">
    <style>
        body {
            /* System's DARK accent is a LIGHT colour, so --on-accent must be pinned
               alongside --accent or the primary button gets white-on-pale text.
               Same note as the other System pages. */
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
        }
        .ai-container { height: calc(100vh - 48px); overflow-y: auto; padding: 30px 20px; }
        .page-title    { font-size: 22px; font-weight: 600; color: var(--text, #333); margin: 0 0 6px 0; }
        .page-subtitle { font-size: 13px; color: var(--text-dim, #888); margin: 0 0 24px 0; max-width: 760px; line-height: 1.6; }
        .settings-card {
            background: var(--surface, #fff);
            border-radius: 8px;
            padding: 24px;
            box-shadow: 0 1px 4px var(--shadow, rgba(0,0,0,0.08));
            margin-bottom: 24px;
            max-width: 860px;
        }
        .settings-card h3 { font-size: 15px; font-weight: 600; color: var(--text, #333); margin: 0 0 4px 0; }
        .settings-card .card-desc { font-size: 13px; color: var(--text-dim, #888); margin: 0 0 18px 0; line-height: 1.6; }

        /* The measurement, stated rather than summarised. It is the whole reason
           the default is what it is, and an administrator deciding whether to
           switch one back on deserves the actual numbers. */
        .ai-evidence {
            border-left: 3px solid var(--accent, #546e7a);
            background: var(--surface-2, #f7f8fa);
            padding: 12px 16px;
            border-radius: 0 6px 6px 0;
            font-size: 12.5px;
            line-height: 1.6;
            color: var(--text-dim, #777);
            margin: 0 0 20px 0;
        }
        .ai-evidence table { border-collapse: collapse; margin-top: 8px; }
        .ai-evidence td { padding: 2px 14px 2px 0; }
        .ai-evidence .num { font-variant-numeric: tabular-nums; color: var(--text, #333); font-weight: 600; }
        .ai-evidence tr.hdr td { font-size: 11px; text-transform: uppercase; letter-spacing: .4px; color: var(--text-dim, #999); padding-bottom: 4px; }
        /* A short tag on the row, rather than the same sentence nine times. */
        .ai-tag {
            display: inline-block;
            margin-left: 6px;
            padding: 1px 7px;
            border: 1px solid var(--border, #ddd);
            border-radius: 9px;
            font-size: 11px;
            color: var(--text-dim, #999);
        }

        .ai-row {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 14px 0;
            border-bottom: 1px solid var(--border, #eee);
        }
        .ai-row:last-child { border-bottom: none; }
        .ai-row-main { flex: 1 1 auto; min-width: 0; }
        .ai-row-name { font-size: 14px; font-weight: 600; color: var(--text, #333); }
        .ai-row-meta { font-size: 12px; color: var(--text-dim, #888); margin-top: 3px; }
        .ai-row-meta code { font-family: 'Consolas', 'Monaco', monospace; font-size: 11.5px; }

        /* The switch. Same shape as the toggles elsewhere in settings. */
        .ai-switch { flex: 0 0 auto; display: flex; align-items: center; gap: 10px; }
        .ai-switch input { width: 18px; height: 18px; cursor: pointer; }
        .ai-switch label { font-size: 13px; color: var(--text, #333); cursor: pointer; }
        .ai-row.is-na .ai-row-name,
        .ai-row.is-na .ai-row-meta { opacity: .6; }

        .ai-empty { color: var(--text-dim, #888); font-size: 13px; }
        .ai-actions { display: flex; gap: 10px; margin-top: 4px; }

        @media (max-width: 768px) {
            .ai-row { flex-wrap: wrap; }
            .ai-switch { flex-basis: 100%; }
        }
    </style>
    <!-- Mobile layer LAST, after this page's own <style> (Techniques §9). -->
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=133">
</head>
<body data-mobile-module="system" data-mobile-page="ai">
    <?php include '../includes/header.php'; ?>

    <div class="ai-container">
        <h1 class="page-title"><?php echo htmlspecialchars(t('system.ai.title')); ?></h1>
        <p class="page-subtitle"><?php echo htmlspecialchars(t('system.ai.subtitle')); ?></p>

        <form id="aiReasoningForm">
            <div class="settings-card">
                <h3><?php echo htmlspecialchars(t('system.ai.heading')); ?></h3>
                <p class="card-desc"><?php echo htmlspecialchars(t('system.ai.desc')); ?></p>
                <!-- Stated ONCE, not once per row: seven of nine rows carried this
                     sentence and it read as noise rather than as information. The rows
                     keep a two-word tag so an individual line is still self-explaining. -->
                <p class="card-desc"><?php echo htmlspecialchars(t('system.ai.desc_provider')); ?></p>

                <div class="ai-evidence">
                    <?php echo htmlspecialchars(t('system.ai.evidence_intro')); ?>
                    <table>
                        <tr class="hdr">
                            <td></td>
                            <td><?php echo htmlspecialchars(t('system.ai.col_time')); ?></td>
                            <td><?php echo htmlspecialchars(t('system.ai.col_thinking')); ?></td>
                            <td><?php echo htmlspecialchars(t('system.ai.col_answer')); ?></td>
                        </tr>
                        <tr>
                            <td><?php echo htmlspecialchars(t('system.ai.evidence_on')); ?></td>
                            <td class="num">54.7s</td>
                            <td class="num">2,927</td>
                            <td class="num">1,020</td>
                        </tr>
                        <tr>
                            <td><?php echo htmlspecialchars(t('system.ai.evidence_off')); ?></td>
                            <td class="num">6.9s</td>
                            <td class="num">0</td>
                            <td class="num">1,376</td>
                        </tr>
                    </table>
                    <div style="margin-top:8px"><?php echo htmlspecialchars(t('system.ai.evidence_note')); ?></div>
                </div>

                <?php if (!$features): ?>
                    <p class="ai-empty"><?php echo htmlspecialchars(t('system.ai.unavailable')); ?></p>
                <?php else: ?>
                    <?php foreach ($features as $f): ?>
                    <div class="ai-row<?php echo $f['applies'] ? '' : ' is-na'; ?>">
                        <div class="ai-row-main">
                            <div class="ai-row-name"><?php echo htmlspecialchars($f['label']); ?></div>
                            <div class="ai-row-meta">
                                <?php echo htmlspecialchars(ucfirst($f['provider'])); ?> ·
                                <code><?php echo htmlspecialchars($f['model']); ?></code>
                                <?php if (!$f['has_key']): ?>
                                    · <?php echo htmlspecialchars(t('system.ai.no_key')); ?>
                                <?php endif; ?>
                                <?php if (!$f['applies']): ?>
                                    <span class="ai-tag"><?php echo htmlspecialchars(t('system.ai.na_tag')); ?></span>
                                <?php endif; ?>
                            </div>

                        </div>
                        <div class="ai-switch">
                            <input type="checkbox"
                                   id="rsn_<?php echo htmlspecialchars($f['ns']); ?>"
                                   data-ns="<?php echo htmlspecialchars($f['ns']); ?>"
                                   <?php echo $f['reasoning'] ? 'checked' : ''; ?>>
                            <label for="rsn_<?php echo htmlspecialchars($f['ns']); ?>"><?php echo htmlspecialchars(t('system.ai.use_thinking')); ?></label>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <div class="ai-actions" style="margin-top:22px">
                        <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
                    </div>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <script src="../../assets/js/i18n.js?v=1"></script>
    <script src="../../assets/js/toast.js?v=1"></script>
    <script>
    const API_BASE = '<?php echo $path_prefix; ?>api/settings/';

    document.getElementById('aiReasoningForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        const btn = this.querySelector('button[type="submit"]');
        if (!btn) return;

        /* Every switch is posted, including the ones marked as having no effect.
           A provider can be changed on another page tomorrow, and a preference
           that was never written down would silently become "off" the moment it
           started to matter. */
        const settings = {};
        document.querySelectorAll('.ai-switch input[data-ns]').forEach(el => {
            settings[el.dataset.ns + '_reasoning'] = el.checked ? '1' : '0';
        });

        btn.disabled = true;
        try {
            const resp = await fetch(API_BASE + 'save_system_settings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ settings })
            });
            const data = await resp.json();
            if (data.success) {
                showToast(window.t('system.ai.saved'), 'success');
            } else {
                showToast(data.error || window.t('system.ai.save_failed'), 'error');
            }
        } catch (err) {
            showToast(window.t('system.ai.save_failed'), 'error');
        }
        btn.disabled = false;
    });
    </script>
    <script src="../../assets/js/mobile.js?v=55"></script>
</body>
</html>
