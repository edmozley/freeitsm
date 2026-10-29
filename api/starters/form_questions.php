<?php
/**
 * GET ?form_id= - a form's questions, from its newest version, for the template
 * editor: the "use this template when" rule, each step's "only if", and the
 * {Question} words a step can fill in. Labels and choices only - rules are kept
 * by label (StartersService::rawAnswers()).
 */
require_once __DIR__ . '/../../includes/starters/api_bootstrap.php';
require_once __DIR__ . '/../../includes/services/forms.php';
require_once __DIR__ . '/../../includes/services/task_forms.php';

startersApiRun(function () use ($conn) {
    $formId = (int)($_GET['form_id'] ?? 0);
    if ($formId <= 0) startersApiFail('Choose a form.');
    $formId = TaskForms::newestVersion($conn, $formId);
    $st = $conn->prepare("SELECT label, field_type, options FROM form_fields
                           WHERE form_id = ? AND COALESCE(is_deleted, 0) = 0 AND " . FormsService::presentationalSqlExclusion('field_type') . "
                           ORDER BY sort_order, id");
    $st->execute([$formId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $opts = json_decode((string)($f['options'] ?? ''), true);
        $out[] = ['label' => (string)$f['label'], 'type' => (string)$f['field_type'],
                  'options' => is_array($opts) ? array_values(array_filter(array_map(fn($o) => is_array($o) ? (string)($o['label'] ?? $o['value'] ?? '') : (string)$o, $opts), 'strlen')) : []];
    }
    startersApiOk(['questions' => $out]);
});
