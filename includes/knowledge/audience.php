<?php
/**
 * Who is allowed to read a Knowledge article.
 *
 * `knowledge_articles.audience` answers "who may read this", which is a
 * different question from `tenant_id` ("whose article is it"). You need both:
 * scoping an article to a company still hands it to that company's anonymous
 * website visitors unless something says it is internal.
 *
 * Why one ordered value rather than a checkbox per channel: the readers are not
 * arbitrary peers, they are a ladder of how much we trust the reader —
 *
 *   analyst           authenticated staff
 *   self-service user authenticated customer; we know who they are
 *   web chat visitor  ANONYMOUS. They typed a name and email; neither is verified
 *
 * Three booleans would allow eight combinations, most of which are nonsense
 * ("visible to web chat but not to analysts"), and every new channel would mean
 * another column plus a retroactive decision about every existing article. A
 * ladder cannot express a contradiction, and a new channel just declares which
 * rung it reads at.
 *
 * The reader states its OWN level using the same vocabulary and gets everything
 * at that rung or more open:
 *
 *   Audience::visibleTo(Audience::INTERNAL)  => internal, customer, public   (analyst)
 *   Audience::visibleTo(Audience::CUSTOMER)  => customer, public             (self-service)
 *   Audience::visibleTo(Audience::PUBLIC)    => public                       (web chat)
 *
 * Constants rather than bare strings for the same reason as the Cap:: helpers:
 * a typo fatals at the call site, instead of silently matching nothing — and on
 * a visibility check, "silently matching nothing" is the difference between a
 * bug and a disclosure.
 */
class Audience
{
    /** Analysts only. The default for every article, including on upgrade. */
    const INTERNAL = 'internal';
    /** Analysts + signed-in self-service users. */
    const CUSTOMER = 'customer';
    /** Analysts + self-service + anonymous web chat visitors. */
    const PUBLIC   = 'public';

    /** Most-restrictive first — this order IS the ladder. */
    private static $ladder = [self::INTERNAL, self::CUSTOMER, self::PUBLIC];

    /** All valid values, most restrictive first. */
    public static function all(): array
    {
        return self::$ladder;
    }

    public static function isValid($value): bool
    {
        return is_string($value) && in_array($value, self::$ladder, true);
    }

    /**
     * Normalise anything user-supplied to a valid level.
     * Anything unrecognised becomes INTERNAL — the safe end of the ladder.
     */
    public static function normalise($value): string
    {
        return self::isValid($value) ? $value : self::INTERNAL;
    }

    /**
     * The audiences a reader at $viewerLevel may see: their own rung and every
     * more open one. An unrecognised level yields PUBLIC only (fail closed).
     */
    public static function visibleTo(string $viewerLevel): array
    {
        $i = array_search($viewerLevel, self::$ladder, true);
        if ($i === false) return [self::PUBLIC];
        return array_slice(self::$ladder, $i);
    }

    /**
     * SQL predicate + params restricting a query to what $viewerLevel may read.
     * Returns ['', []] for an analyst — they see every rung, so no filter is
     * needed and the SQL stays byte-identical to before this existed.
     *
     * $alias is the table alias ('' for an unaliased query).
     */
    public static function sqlFilter(string $viewerLevel, string $alias = 'a'): array
    {
        $allowed = self::visibleTo($viewerLevel);
        if (count($allowed) === count(self::$ladder)) return ['', []];

        $col = $alias === '' ? 'audience' : $alias . '.audience';
        $marks = implode(',', array_fill(0, count($allowed), '?'));
        return [' AND ' . $col . ' IN (' . $marks . ')', $allowed];
    }
}
