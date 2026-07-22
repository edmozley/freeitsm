<?php
/**
 * Reusable AI provider/model/key settings panel.
 *
 * Drop one of these on any settings page:
 *     <?php renderAiSettingsPanel('knowledge_ai'); ?>
 *
 * Requirements on the host page:
 *   - BASE_URL defined (config.php)
 *   - i18n: the 'common' namespace exported to window.translations (window.t),
 *     and assets/js/i18n.js loaded
 *   - assets/js/ai-settings.js loaded (drives every [data-ai-panel] on the page)
 *   - showToast() available (assets/js/toast.js — auto-loaded via the waffle menu)
 *
 * The <ns> must be registered in includes/ai_settings.php. All persistence/test
 * goes through the shared api/system/ai/* endpoints, so this partial carries no
 * per-module logic.
 *
 * @param string $ns   Registered AI settings namespace (e.g. 'knowledge_ai')
 * @param array  $opts ['models' => ['anthropic'=>[ids], 'openai'=>[ids]]]
 *                      curated model suggestions for the direct providers.
 */
function renderAiSettingsPanel(string $ns, array $opts = []): void
{
    static $styleEmitted = false;

    $apiBase = (defined('BASE_URL') ? BASE_URL : '/') . 'api/system/ai/';

    // Curated model suggestions for the direct providers (datalist hints — the
    // field still accepts any string). OpenRouter models are fetched live.
    $models = $opts['models'] ?? [];
    $models['anthropic'] = $models['anthropic'] ?? [
        'claude-haiku-4-5-20251001',
        'claude-sonnet-4-6',
        'claude-opus-4-7',
    ];
    $models['openai'] = $models['openai'] ?? [
        'gpt-4o',
        'gpt-4o-mini',
    ];

    $t = function (string $k) { return htmlspecialchars(t('common.ai.' . $k)); };

    if (!$styleEmitted):
        $styleEmitted = true;
    ?>
    <style>
    .ai-settings-panel { max-width: 640px; }
    .ai-settings-panel .form-group { margin-bottom: 16px; }
    .ai-settings-panel .ai-note {
        font-size: 12px; color: var(--warning-text, #8a6d1f); background: var(--warning-bg, #fff8e1); border: 1px solid var(--warning-border, #ffe49a);
        border-radius: 6px; padding: 8px 12px; margin-bottom: 16px; display: none;
    }
    .ai-settings-panel .ai-note.show { display: block; }
    .ai-settings-panel .ai-model-hint { display: block; font-size: 12px; color: var(--text-dim, #888); margin-top: 4px; }
    /* Self-contained model dropdown — replaces a native <datalist> so a long
       list can't trigger the browser's scroll-into-view on the overflow:hidden
       body (which would push the page header off the top). */
    .ai-settings-panel .ai-model-combo { position: relative; max-width: 420px; }
    .ai-settings-panel .ai-model-combo input { width: 100%; }
    .ai-settings-panel .ai-model-menu {
        position: absolute; top: calc(100% + 2px); left: 0; right: 0; z-index: 60;
        background: var(--surface, #fff); border: 1px solid var(--border, #ddd); border-radius: 6px;
        max-height: 240px; overflow-y: auto; box-shadow: 0 4px 14px var(--shadow, rgba(0,0,0,0.14));
    }
    .ai-settings-panel .ai-model-menu[hidden] { display: none; }
    .ai-settings-panel .ai-model-opt {
        padding: 8px 12px; cursor: pointer; font-size: 13px;
        display: flex; justify-content: space-between; gap: 10px; align-items: baseline;
    }
    .ai-settings-panel .ai-model-opt:hover, .ai-settings-panel .ai-model-opt.active { background: var(--accent-soft, #eef4ff); }
    .ai-settings-panel .ai-model-opt .ai-model-id { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .ai-settings-panel .ai-model-opt .ai-model-px { color: var(--text-dim, #888); white-space: nowrap; font-size: 12px; }
    .ai-settings-panel .ai-model-empty { padding: 8px 12px; color: var(--text-faint, #999); font-size: 12px; }
    .ai-settings-panel .ai-result { margin-top: 12px; font-size: 13px; min-height: 18px; }
    .ai-settings-panel .ai-result.ok   { color: var(--success-text, #107c10); }
    .ai-settings-panel .ai-result.err  { color: var(--danger-accent, #d13438); }
    .ai-settings-panel .ai-result.busy { color: var(--text-muted, #666); }
    </style>
    <?php endif; ?>

    <div class="ai-settings-panel"
         data-ai-panel
         data-ns="<?php echo htmlspecialchars($ns); ?>"
         data-api-base="<?php echo htmlspecialchars($apiBase); ?>"
         data-models='<?php echo htmlspecialchars(json_encode($models), ENT_QUOTES); ?>'>

        <div class="form-group">
            <label><?php echo $t('provider'); ?></label>
            <select data-ai-provider style="max-width: 280px;">
                <option value="anthropic"><?php echo $t('provider_anthropic'); ?></option>
                <option value="openai"><?php echo $t('provider_openai'); ?></option>
                <option value="openrouter"><?php echo $t('provider_openrouter'); ?></option>
            </select>
        </div>

        <div class="ai-note" data-ai-openrouter-note><?php echo $t('openrouter_note'); ?></div>

        <div class="form-group">
            <label><?php echo $t('model'); ?></label>
            <div class="ai-model-combo">
                <input type="text" data-ai-model placeholder="<?php echo $t('model_placeholder'); ?>" autocomplete="off">
                <div class="ai-model-menu" data-ai-model-menu hidden></div>
            </div>
            <small class="ai-model-hint" data-ai-model-hint></small>
        </div>

        <div class="form-group">
            <label><?php echo $t('api_key'); ?></label>
            <input type="password" data-ai-key placeholder="sk-..." autocomplete="off" style="max-width: 420px;">
            <small class="ai-model-hint" data-ai-key-hint><?php echo $t('api_key_help'); ?></small>
        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-primary" data-ai-save><?php echo $t('save'); ?></button>
            <button type="button" class="btn btn-test" data-ai-test><?php echo $t('test'); ?></button>
        </div>
        <div class="ai-result" data-ai-result></div>
    </div>
    <?php
}
