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
        font-size: 12px; color: #8a6d1f; background: #fff8e1; border: 1px solid #ffe49a;
        border-radius: 6px; padding: 8px 12px; margin-bottom: 16px; display: none;
    }
    .ai-settings-panel .ai-note.show { display: block; }
    .ai-settings-panel .ai-model-hint { display: block; font-size: 12px; color: #888; margin-top: 4px; }
    .ai-settings-panel .ai-result { margin-top: 12px; font-size: 13px; min-height: 18px; }
    .ai-settings-panel .ai-result.ok   { color: #107c10; }
    .ai-settings-panel .ai-result.err  { color: #d13438; }
    .ai-settings-panel .ai-result.busy { color: #666; }
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
            <input type="text" data-ai-model list="aiModelList_<?php echo htmlspecialchars($ns); ?>"
                   placeholder="<?php echo $t('model_placeholder'); ?>" autocomplete="off" style="max-width: 420px;">
            <datalist id="aiModelList_<?php echo htmlspecialchars($ns); ?>" data-ai-model-list></datalist>
            <small class="ai-model-hint" data-ai-model-hint></small>
        </div>

        <div class="form-group">
            <label><?php echo $t('api_key'); ?></label>
            <input type="password" data-ai-key placeholder="sk-..." autocomplete="off" style="max-width: 420px;">
            <small class="ai-model-hint" data-ai-key-hint><?php echo $t('api_key_help'); ?></small>
        </div>

        <div class="form-group">
            <label style="font-weight: normal; cursor: pointer;">
                <input type="checkbox" data-ai-verify-ssl>
                <?php echo $t('verify_ssl'); ?>
            </label>
            <small class="ai-model-hint"><?php echo $t('verify_ssl_help'); ?></small>
        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-primary" data-ai-save><?php echo $t('save'); ?></button>
            <button type="button" class="btn btn-test" data-ai-test><?php echo $t('test'); ?></button>
        </div>
        <div class="ai-result" data-ai-result></div>
    </div>
    <?php
}
