<?php
/**
 * Transforms for a value dropped into text - "{First name|initial|upper}" (GH #317).
 *
 * A starter's steps need values WORKED OUT from the answers, not just copied: a PC
 * name ORG-JSMITH, a username jsmith, an email john.smith@example.org. Before this,
 * the analyst worked them out by hand, and two people worked them out two ways.
 *
 * A short list, named in plain words, applied left to right. Deliberately not a
 * formula language. Shared so Forms' actions and workflow variables can use the
 * same transforms later; Starters & leavers is the first user.
 *
 *   initial  the first letter                       John Paul -> J
 *   first    the first word                         John Paul -> John
 *   last     the last word                          Mary Ann Smith -> Smith
 *   upper    capitals                               John Paul -> JOHN PAUL
 *   lower    small letters                          John Paul -> john paul
 *   nospace  spaces taken out                       John Paul -> JohnPaul
 *   slug     lower case, spaces and punctuation to "." (accents folded)   John Paul -> john.paul
 *   ascii    accents and other letters folded to plain A-Z   José Núñez -> Jose Nunez
 */

const TEXT_TRANSFORMS = ['initial', 'first', 'last', 'upper', 'lower', 'nospace', 'slug', 'ascii'];

/**
 * Applies the named transforms to $value, left to right.
 *
 * @param list<string> $names  transform names, as typed after each "|" (case and spaces ignored)
 * @return ?string  the result, or null when a name is not a transform - the caller then
 *                  leaves the placeholder exactly as typed, so a typo shows rather than vanishing
 */
function textTransform(string $value, array $names): ?string
{
    foreach ($names as $name) {
        $name = strtolower(trim($name));
        switch ($name) {
            case 'initial': $value = mb_substr(ltrim($value), 0, 1); break;
            case 'first':   $value = (string)(preg_split('/\s+/u', trim($value))[0] ?? ''); break;
            case 'last':    $w = preg_split('/\s+/u', trim($value)); $value = (string)end($w); break;
            case 'upper':   $value = mb_strtoupper($value); break;
            case 'lower':   $value = mb_strtolower($value); break;
            case 'nospace': $value = preg_replace('/\s+/u', '', $value); break;
            case 'ascii':   $value = textToAscii($value); break;
            case 'slug':
                $value = mb_strtolower(textToAscii($value));
                $value = trim(preg_replace('/[^a-z0-9]+/', '.', $value), '.');
                break;
            default: return null;
        }
    }
    return $value;
}

/** Folds accented and other Latin letters to plain A-Z: José -> Jose, Æsa -> AEsa, Łukasz -> Lukasz. */
function textToAscii(string $value): string
{
    if (class_exists('Transliterator')) {
        $t = Transliterator::create('Any-Latin; Latin-ASCII');
        if ($t) {
            $out = $t->transliterate($value);
            if (is_string($out)) return $out;
        }
    }
    if (class_exists('Normalizer')) {
        $d = Normalizer::normalize($value, Normalizer::FORM_D);
        if (is_string($d)) return preg_replace('/\p{Mn}+/u', '', $d);
    }
    return $value;
}

/**
 * Fills {Name} and {Name|transform|...} placeholders in $text from $map.
 * Names are matched case-insensitively ($map keys must be lower case). A name not in
 * the map, or an unknown transform, leaves that placeholder as typed.
 *
 * @param array<string,string> $map  lower-case name => value
 */
function textFillPlaceholders(string $text, array $map): string
{
    if (!str_contains($text, '{')) return $text;
    return preg_replace_callback('/\{([^{}]{1,200})\}/u', function ($m) use ($map) {
        $parts = explode('|', $m[1]);
        $k = mb_strtolower(trim(array_shift($parts)));
        if (!array_key_exists($k, $map)) return $m[0];
        $out = textTransform((string)$map[$k], $parts);
        return $out === null ? $m[0] : $out;
    }, $text);
}
