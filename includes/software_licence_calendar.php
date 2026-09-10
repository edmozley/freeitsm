<?php
/**
 * Software licence renewals → Calendar sync (#1551).
 *
 * A deliberate copy of the shape in includes/asset_warranty_calendar.php rather
 * than a new mechanism: `calendar_events.source` already exists precisely so a
 * generator can wipe and reinsert its own events without touching anything a
 * person typed, and assets have been doing this for a while. Software was simply
 * never wired into it.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS AT ALL
 * ---------------------------------------------------------------------------
 * `software_licences` has carried `renewal_date` and `notice_period_days` since
 * it shipped, and both already drove a "due soon" colour — but only for somebody
 * who happened to open the Licences page that week. A contract ending the same
 * day appeared on Watchtower AND in the calendar; a £12k software renewal
 * appeared nowhere. This closes half of that (the Watchtower card closes the
 * other half).
 *
 * ---------------------------------------------------------------------------
 * TWO EVENTS PER LICENCE, NOT ONE
 * ---------------------------------------------------------------------------
 * The renewal date is when the money goes out. The NOTICE date — renewal minus
 * notice_period_days — is the last day you can still walk away, and it is the
 * one that actually costs you if you miss it. A calendar showing only the
 * renewal tells you about the deadline on the day it is already too late.
 *
 * Unlike contracts, which store their own `notice_date` column, this one is
 * derived. A licence with no notice period gets no notice event: there is no
 * deadline to miss, and inventing a default 30 days would put a fictional
 * commitment in somebody's calendar.
 */

require_once __DIR__ . '/asset_warranty_calendar.php';   // awcGetSetting/awcColumnExists

if (!function_exists('syncSoftwareLicenceCalendar')) {
    /**
     * @return array{success:bool, synced?:int, error?:string}
     */
    function syncSoftwareLicenceCalendar(PDO $conn): array
    {
        try {
            if (!awcColumnExists($conn, 'calendar_events', 'source')
                || !awcColumnExists($conn, 'software_licences', 'renewal_date')) {
                return ['success' => false, 'error' => 'Schema not ready'];
            }

            $surface    = awcGetSetting($conn, 'software_renewal_surface', 'dashboard');
            $onCalendar = in_array($surface, ['calendar', 'both'], true);

            // Always clear our own events first — and ONLY our own. The source
            // value is what makes a wholesale delete safe.
            $conn->exec("DELETE FROM calendar_events WHERE source = 'software_renewal'");

            if (!$onCalendar) {
                return ['success' => true, 'synced' => 0];
            }

            $categoryId = slcEnsureRenewalCategory($conn);

            // Only Active licences. A cancelled one still has a renewal date on
            // the record — that is its history, not a commitment — and putting it
            // in the calendar would ask somebody to act on a decision already made.
            $rows = $conn->query(
                "SELECT l.id, l.renewal_date, l.notice_period_days, l.quantity,
                        a.display_name
                   FROM software_licences l
                   JOIN software_inventory_apps a ON a.id = l.app_id
                  WHERE l.renewal_date IS NOT NULL AND l.status = 'Active'"
            )->fetchAll(PDO::FETCH_ASSOC);

            if (!$rows) {
                return ['success' => true, 'synced' => 0];
            }

            $ins = $conn->prepare(
                "INSERT INTO calendar_events
                    (title, description, category_id, start_datetime, end_datetime, all_day, created_by, source)
                 VALUES (?, ?, ?, ?, ?, 1, 0, 'software_renewal')"
            );

            $n = 0;
            foreach ($rows as $r) {
                $name  = ($r['display_name'] !== null && $r['display_name'] !== '')
                    ? $r['display_name']
                    : ('Licence #' . $r['id']);
                $seats = ($r['quantity'] !== null && (int)$r['quantity'] > 0)
                    ? ' (' . (int)$r['quantity'] . ' seats)'
                    : '';

                // All-day, so the DATE part is what matters — same convention as
                // the warranty generator and calendar_events.all_day.
                $renewal = substr((string)$r['renewal_date'], 0, 10) . ' 00:00:00';
                $ins->execute([
                    'Software renewal: ' . $name,
                    'Auto-generated from the software licence' . $seats
                        . '. Edit the renewal date on the licence to change this.',
                    $categoryId,
                    $renewal,
                    $renewal,
                ]);
                $n++;

                $notice = $r['notice_period_days'] !== null ? (int)$r['notice_period_days'] : 0;
                if ($notice > 0) {
                    $noticeDate = date('Y-m-d', strtotime(substr((string)$r['renewal_date'], 0, 10) . " -{$notice} days")) . ' 00:00:00';
                    $ins->execute([
                        'Notice deadline: ' . $name,
                        'Last day to give notice before this licence renews on '
                            . substr((string)$r['renewal_date'], 0, 10)
                            . ' (' . $notice . ' days notice required).',
                        $categoryId,
                        $noticeDate,
                        $noticeDate,
                    ]);
                    $n++;
                }
            }

            return ['success' => true, 'synced' => $n];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** Find or create the "Renewals" calendar category; returns its id (or null). */
    function slcEnsureRenewalCategory(PDO $conn): ?int
    {
        $sel = $conn->prepare("SELECT id FROM calendar_categories WHERE name = ? LIMIT 1");
        $sel->execute(['Renewals']);
        $id = $sel->fetchColumn();
        if ($id) {
            return (int)$id;
        }
        try {
            $ins = $conn->prepare("INSERT INTO calendar_categories (name, color, is_active) VALUES (?, ?, 1)");
            $ins->execute(['Renewals', '#5c6bc0']);   // the Software module colour
            return (int)$conn->lastInsertId();
        } catch (Exception $e) {
            return null;   // events just go uncategorised rather than failing
        }
    }
}
