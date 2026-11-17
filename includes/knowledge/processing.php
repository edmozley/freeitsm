<?php
/** Opt-in import processing policy; ordinary saves keep the historic default. */
require_once __DIR__ . '/../service_context.php';

enum KnowledgeProcessing: string
{
    case Normal = 'normal';
    case Suppress = 'suppress';

    public static function suppresses(array $input): bool
    {
        $value = $input['processing'] ?? self::Normal->value;
        $mode = is_string($value) ? self::tryFrom($value) : null;
        if ($mode === null) throw new ServiceError('validation', 'invalid_processing', 'processing must be normal or suppress.');
        return $mode === self::Suppress;
    }
}
