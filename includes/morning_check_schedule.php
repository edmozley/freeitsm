<?php
/**
 * When a morning check falls due (GH #383).
 *
 * A check used to be due every day. Now it can be:
 *
 *   every day        ScheduleType NULL           - every check that existed before, unchanged
 *   every weekday    'weekdays'                  - Monday to Friday
 *   weekly           'weekly'   + ScheduleDays   - the ISO days ticked, "1,4" = Monday and Thursday
 *   monthly          'monthly'  + ScheduleMonthWhen
 *                       'day'           + ScheduleMonthDay 1-31 (31 = the month's last day in a short month)
 *                       'first_weekday' / 'last_weekday' / 'last_day'
 *
 * Chosen from dropdowns and ticks (Ed on #383: no typed cron or Quartz expression), and
 * stored as plain columns on morningChecks_Checks. Everything that asks "is this check due
 * on this day?" asks HERE - the round, Watchtower and the REST API - so they cannot
 * disagree. A check that is not due is still shown, greyed, and can still be recorded:
 * a weekly check done a day late is better recorded than not.
 *
 * "Weekday" means Monday to Friday. Bank holidays are not known here.
 *
 * @wiki Morning-Check-Schedules-Developer-Guide
 */

final class MorningCheckSchedule
{
    public const TYPES      = ['daily', 'weekdays', 'weekly', 'monthly'];
    public const MONTH_WHEN = ['day', 'first_weekday', 'last_weekday', 'last_day'];

    /** How far next/previous look. A monthly check always falls within 62 days. */
    private const SEARCH_DAYS = 62;

    /**
     * The four columns for a schedule sent as { type, days, month_when, month_day }.
     *
     * @param ?array $s  null or type 'daily' means every day
     * @return array{ScheduleType: ?string, ScheduleDays: ?string, ScheduleMonthWhen: ?string, ScheduleMonthDay: ?int}
     * @throws InvalidArgumentException  a schedule that cannot be kept, with a message for the person
     */
    public static function columns(?array $s): array
    {
        $type = (string)($s['type'] ?? 'daily');
        if ($type === '') $type = 'daily';
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("schedule.type must be one of: daily, weekdays, weekly, monthly.");
        }
        $cols = ['ScheduleType' => $type === 'daily' ? null : $type, 'ScheduleDays' => null, 'ScheduleMonthWhen' => null, 'ScheduleMonthDay' => null];

        if ($type === 'weekly') {
            $days = $s['days'] ?? [];
            if (is_string($days)) $days = explode(',', $days);
            $days = array_values(array_unique(array_filter(array_map('intval', (array)$days), fn($d) => $d >= 1 && $d <= 7)));
            sort($days);
            if (!$days) throw new InvalidArgumentException('A weekly check needs at least one day (schedule.days: 1 = Monday ... 7 = Sunday).');
            $cols['ScheduleDays'] = implode(',', $days);
        } elseif ($type === 'monthly') {
            $when = (string)($s['month_when'] ?? 'day');
            if (!in_array($when, self::MONTH_WHEN, true)) {
                throw new InvalidArgumentException('schedule.month_when must be one of: day, first_weekday, last_weekday, last_day.');
            }
            $cols['ScheduleMonthWhen'] = $when;
            if ($when === 'day') {
                $d = (int)($s['month_day'] ?? 0);
                if ($d < 1 || $d > 31) throw new InvalidArgumentException('schedule.month_day must be a day of the month, 1 to 31.');
                $cols['ScheduleMonthDay'] = $d;
            }
        }
        return $cols;
    }

    /** The schedule as the browser and the REST API see it. @param array $row  a check row */
    public static function toArray(array $row): array
    {
        $type = $row['ScheduleType'] ?? null;
        $type = in_array($type, self::TYPES, true) ? $type : 'daily';
        return [
            'type'       => $type,
            'days'       => $type === 'weekly' ? self::days($row) : [],
            'month_when' => $type === 'monthly' ? ($row['ScheduleMonthWhen'] ?: 'day') : null,
            'month_day'  => $type === 'monthly' && ($row['ScheduleMonthWhen'] ?: 'day') === 'day' ? (int)$row['ScheduleMonthDay'] : null,
        ];
    }

    /**
     * Is the check due on this day?
     *
     * An unknown or half-filled schedule is treated as every day - a schedule must
     * never be the reason a check silently stops appearing.
     *
     * @param array  $row  a check row with the Schedule* columns
     * @param string $ymd  the day, Y-m-d
     */
    public static function isDue(array $row, string $ymd): bool
    {
        $type = $row['ScheduleType'] ?? null;
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
        if (!$d) return true;
        $dow = (int)$d->format('N');
        switch ($type) {
            case 'weekdays':
                return $dow <= 5;
            case 'weekly':
                $days = self::days($row);
                return !$days || in_array($dow, $days, true);
            case 'monthly':
                $on = self::monthDate($row, $d);
                return $on === null || $on === $d->format('Y-m-d');
            default:
                return true;
        }
    }

    /** The next day after $ymd the check is due, or null (looks 62 days ahead). */
    public static function nextDue(array $row, string $ymd): ?string
    {
        return self::step($row, $ymd, '+1 day');
    }

    /** The last day before $ymd the check was due, or null (looks 62 days back). */
    public static function previousDue(array $row, string $ymd): ?string
    {
        return self::step($row, $ymd, '-1 day');
    }

    private static function step(array $row, string $ymd, string $by): ?string
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
        if (!$d) return null;
        for ($i = 0; $i < self::SEARCH_DAYS; $i++) {
            $d = $d->modify($by);
            if (self::isDue($row, $d->format('Y-m-d'))) return $d->format('Y-m-d');
        }
        return null;
    }

    /** @return int[] ISO days, Monday = 1 */
    private static function days(array $row): array
    {
        $out = [];
        foreach (explode(',', (string)($row['ScheduleDays'] ?? '')) as $p) {
            $n = (int)$p;
            if ($n >= 1 && $n <= 7) $out[] = $n;
        }
        return array_values(array_unique($out));
    }

    /** The one day in $d's month a monthly check is due, Y-m-d - or null if it is not set up. */
    private static function monthDate(array $row, DateTimeImmutable $d): ?string
    {
        $first = $d->modify('first day of this month');
        $last  = $d->modify('last day of this month');
        switch ($row['ScheduleMonthWhen'] ?? 'day') {
            case 'last_day':
                return $last->format('Y-m-d');
            case 'first_weekday':
                $x = $first;
                while ((int)$x->format('N') > 5) $x = $x->modify('+1 day');
                return $x->format('Y-m-d');
            case 'last_weekday':
                $x = $last;
                while ((int)$x->format('N') > 5) $x = $x->modify('-1 day');
                return $x->format('Y-m-d');
            case 'day':
            default:
                $n = (int)($row['ScheduleMonthDay'] ?? 0);
                if ($n < 1) return null;
                // The 31st in a 30-day month - or the 29th-31st in February - is the last day.
                $n = min($n, (int)$last->format('j'));
                return $first->modify('+' . ($n - 1) . ' days')->format('Y-m-d');
        }
    }
}
