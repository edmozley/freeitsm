<?php
/**
 * Training reminders — who is due one, and sending it.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS EXISTS
 *
 * An analyst lives in FreeITSM and sees My Courses whether anyone reminds them
 * or not. A self-service portal user has no reason to sign in at all — so a
 * course pushed to 400 staff with no email attached to it is a course 400 people
 * never find out about. Reminders are what makes the portal push a real feature
 * rather than a page nobody visits.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * 🔴 OFF UNTIL SOMEBODY TURNS IT ON
 *
 * The default is OFF, and deliberately so. Every other default in this file is
 * chosen to be safe; this one is chosen to be *inert*. Upgrading FreeITSM must
 * never, on its own, email several hundred people about training they were
 * assigned months ago — which is exactly what a helpful "default on" would do on
 * the first cron run after an upgrade. LMS → Settings → Reminders switches it on,
 * and shows how many would go out before you do.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * TWO WAYS IT RUNS, FOR THE REASON THE EXTRACTION QUEUE GIVES
 *
 *   cron           cron/lms_reminders.php, once a day, the real answer
 *   opportunistic  a run when a manager opens the LMS, at most once an hour
 *
 * A cron-only design does nothing at all on an installation that has not set one
 * up — which is most evaluations, plenty of shared hosts, and anybody whose
 * scheduled tasks are switched off. Opportunistic running means the feature
 * works out of the box; the cron means it is reliable. Same pattern, and the
 * same rationale, as includes/search/extract_queue.php.
 *
 * ⚠️ The opportunistic path is honest about its limits and the settings screen
 * says so: if nobody opens the LMS for a week, nothing is sent for a week. It is
 * a fallback, not a scheduler.
 */

require_once __DIR__ . '/lms_access.php';
require_once __DIR__ . '/self_service_email.php';   // ssSendSystemEmail()

/** Settings keys, all in `system_settings`. */
const LMS_REM_ENABLED       = 'lms_reminders_enabled';          // '0' | '1'   default '0'
const LMS_REM_DAYS_BEFORE   = 'lms_reminder_days_before';       // e.g. '7,1'  default '7,1'
const LMS_REM_CHASE         = 'lms_reminder_chase_overdue';     // '0' | '1'   default '1'
const LMS_REM_CHASE_EVERY   = 'lms_reminder_chase_every_days';  // int         default '7'
const LMS_REM_OPPORTUNISTIC = 'lms_reminders_opportunistic';    // '0' | '1'   default '1'
const LMS_REM_LAST_RUN      = 'lms_reminders_last_run';         // internal; UTC 'Y-m-d H:i:s'

/** How long between opportunistic runs. A page load must not be a mail merge. */
const LMS_REM_OPPORTUNISTIC_MIN_SECONDS = 3600;

/**
 * ⚠️ A CEILING ON ONE RUN. A course pushed to every portal user on a big install
 * is thousands of emails, and sending them inside one request (or one cron tick)
 * would time out half way through — leaving nobody able to say which half. The
 * run stops at this many, records what it sent, and the next run continues:
 * the fire-once table means resuming is free and nothing is sent twice.
 */
const LMS_REM_MAX_PER_RUN = 200;

/** Read the reminder settings, with their defaults applied. */
function lmsReminderSettings(PDO $conn): array
{
    $defaults = [
        LMS_REM_ENABLED       => '0',
        LMS_REM_DAYS_BEFORE   => '7,1',
        LMS_REM_CHASE         => '1',
        LMS_REM_CHASE_EVERY   => '7',
        LMS_REM_OPPORTUNISTIC => '1',
        LMS_REM_LAST_RUN      => '',
    ];

    try {
        $in = implode(',', array_fill(0, count($defaults), '?'));
        $st = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($in)");
        $st->execute(array_keys($defaults));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['setting_value'] !== null && $r['setting_value'] !== '') {
                $defaults[$r['setting_key']] = (string)$r['setting_value'];
            }
        }
    } catch (Throwable $e) {
        // Settings table unreachable — the caller gets the defaults, which means OFF.
    }

    return $defaults;
}

/** Write one setting. */
function lmsReminderSaveSetting(PDO $conn, string $key, string $value): void
{
    $st = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_datetime)
                          VALUES (?, ?, UTC_TIMESTAMP())
                          ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                                  updated_datetime = UTC_TIMESTAMP()");
    $st->execute([$key, $value]);
}

/**
 * "7, 1" → [7, 1], sorted high to low, de-duplicated, nonsense discarded.
 *
 * ⚠️ 0 IS ALLOWED AND MEANS "on the day it is due". Negative numbers are not:
 * a reminder "-3 days before" is the overdue chase, which is a separate setting
 * with its own repeat, and accepting it here would send two different emails
 * about the same day.
 */
function lmsReminderParseDays(string $raw): array
{
    $out = [];
    foreach (preg_split('/[,\s]+/', trim($raw)) as $bit) {
        if ($bit === '' || !ctype_digit($bit)) continue;
        $n = (int)$bit;
        if ($n < 0 || $n > 365) continue;
        $out[$n] = true;
    }
    $days = array_keys($out);
    rsort($days);
    return $days;
}

/**
 * Everybody who should get a reminder right now.
 *
 * Walks every (learner, course) an assignment reaches, drops the ones that are
 * finished, have no deadline, or have already had this exact reminder, and
 * returns what is left with the address to send to.
 *
 * 🔑 THE EARLIEST DEADLINE WINS when somebody is reached twice (a people group
 * AND "everyone"), exactly as the learner's own page and the Progress tab
 * decide it. Three screens and this run must not disagree about when something
 * is due, or the email says one thing and the portal says another.
 *
 * @return array<int,array<string,mixed>>
 */
function lmsRemindersDue(PDO $conn, array $settings): array
{
    $daysBefore = lmsReminderParseDays((string)$settings[LMS_REM_DAYS_BEFORE]);
    $chase      = $settings[LMS_REM_CHASE] === '1';
    $chaseEvery = max(1, (int)$settings[LMS_REM_CHASE_EVERY]);

    if (!$daysBefore && !$chase) return [];

    $reach = lmsAssignedLearnersSql($conn);

    // Status comes from lms_progress; a learner with no row has not started.
    $sql = "SELECT r.learner_type, r.learner_id, r.course_id, r.deadline,
                   c.title AS course_title,
                   COALESCE(p.status, 'not_started') AS status
              FROM $reach r
              JOIN lms_courses c ON c.id = r.course_id AND c.is_active = 1
              LEFT JOIN lms_progress p
                     ON p.learner_type = r.learner_type
                    AND p.learner_id  = r.learner_id
                    AND p.course_id   = r.course_id
             WHERE r.deadline IS NOT NULL";
    $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    // Collapse to one row per (learner, course), earliest deadline winning.
    $best = [];
    foreach ($rows as $r) {
        $key = $r['learner_type'] . ':' . $r['learner_id'] . ':' . $r['course_id'];
        if (!isset($best[$key]) || $r['deadline'] < $best[$key]['deadline']) {
            $best[$key] = $r;
        }
    }

    $today = new DateTime(date('Y-m-d'));   // installation zone, as lmsIsOverdue() reads it
    $due   = [];

    foreach ($best as $r) {
        // Finished is finished. Nobody is chased for training they have passed.
        if (in_array($r['status'], ['completed', 'passed'], true)) continue;

        $dueDay = substr((string)$r['deadline'], 0, 10);
        $dueDt  = DateTime::createFromFormat('Y-m-d', $dueDay);
        if (!$dueDt) continue;
        $dueDt->setTime(0, 0, 0);

        // Whole days from today to the deadline. Positive = still to come.
        $daysLeft = (int)$today->diff($dueDt)->format('%r%a');

        $kind = null;
        $fingerprint = null;

        if ($daysLeft >= 0 && in_array($daysLeft, $daysBefore, true)) {
            $kind        = 'before';
            $fingerprint = $dueDay . ':' . $daysLeft;
        } elseif ($daysLeft < 0 && $chase) {
            // The nth chase since the deadline. Integer division means one
            // reminder per interval rather than one per day.
            $overdueBy = -$daysLeft;
            if ($overdueBy % $chaseEvery !== 0) continue;
            $kind        = 'overdue';
            $fingerprint = $dueDay . ':' . intdiv($overdueBy, $chaseEvery);
        }

        if ($kind === null) continue;

        $r['reminder_kind'] = $kind;
        $r['fingerprint']   = $fingerprint;
        $r['days_left']     = $daysLeft;
        $r['due_day']       = $dueDay;
        $due[] = $r;
    }

    if (!$due) return [];

    // ---- Addresses, in two queries rather than one per person --------------
    $analystIds = [];
    $userIds    = [];
    foreach ($due as $d) {
        if ($d['learner_type'] === 'analyst') $analystIds[(int)$d['learner_id']] = true;
        else                                  $userIds[(int)$d['learner_id']]    = true;
    }

    $people = [];
    if ($analystIds) {
        $in = implode(',', array_fill(0, count($analystIds), '?'));
        $st = $conn->prepare("SELECT id, full_name AS name, email FROM analysts WHERE id IN ($in)");
        $st->execute(array_keys($analystIds));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) $people['analyst:' . $a['id']] = $a;
    }
    if ($userIds) {
        $in = implode(',', array_fill(0, count($userIds), '?'));
        $st = $conn->prepare("SELECT id, COALESCE(NULLIF(display_name, ''), email, username) AS name, email
                                FROM users WHERE id IN ($in)");
        $st->execute(array_keys($userIds));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) $people['user:' . $u['id']] = $u;
    }

    // ---- Already sent? -----------------------------------------------------
    // Read up front rather than relying only on the INSERT IGNORE, so a DRY RUN
    // can report a truthful count without writing anything.
    $sent = [];
    try {
        $st = $conn->query("SELECT learner_type, learner_id, course_id, reminder_kind, fingerprint
                              FROM lms_reminders_sent");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $sent[$s['learner_type'] . ':' . $s['learner_id'] . ':' . $s['course_id']
                  . ':' . $s['reminder_kind'] . ':' . $s['fingerprint']] = true;
        }
    } catch (Throwable $e) {
        // No table yet — nothing has been sent, which is the right answer.
    }

    $out = [];
    foreach ($due as $d) {
        $pk = $d['learner_type'] . ':' . $d['learner_id'];
        $person = $people[$pk] ?? null;

        $d['name']  = $person['name']  ?? null;
        $d['email'] = $person['email'] ?? null;

        $sentKey = $pk . ':' . $d['course_id'] . ':' . $d['reminder_kind'] . ':' . $d['fingerprint'];
        $d['already_sent'] = isset($sent[$sentKey]);

        // ⚠️ NO ADDRESS IS NOT AN ERROR. Warehouse and shop-floor staff are
        // deliberately given no mailbox (GH #47), and a portal account created
        // from an inbound chat has none either. They are counted and reported as
        // unreachable rather than logged as failures — a failure count that is
        // really "these people have no email" tells an administrator nothing.
        $d['reachable'] = !empty($d['email']) && filter_var($d['email'], FILTER_VALIDATE_EMAIL);

        $out[] = $d;
    }

    return $out;
}

/** Subject and HTML body for one reminder. */
function lmsReminderMessage(array $row): array
{
    $name   = trim((string)($row['name'] ?? ''));
    $course = (string)$row['course_title'];
    $due    = fmt_naive_date_for_email($row['due_day']);

    if ($row['reminder_kind'] === 'overdue') {
        $subject = 'Overdue training: ' . $course;
        $lead    = 'Your deadline for <strong>' . htmlspecialchars($course, ENT_QUOTES) . '</strong> passed on '
                 . htmlspecialchars($due, ENT_QUOTES) . '.';
    } elseif ((int)$row['days_left'] === 0) {
        $subject = 'Training due today: ' . $course;
        $lead    = '<strong>' . htmlspecialchars($course, ENT_QUOTES) . '</strong> is due today.';
    } else {
        $days    = (int)$row['days_left'];
        $subject = 'Training due in ' . $days . ' ' . ($days === 1 ? 'day' : 'days') . ': ' . $course;
        $lead    = '<strong>' . htmlspecialchars($course, ENT_QUOTES) . '</strong> is due on '
                 . htmlspecialchars($due, ENT_QUOTES) . '.';
    }

    // Where to send them. A portal user cannot open the analyst app, and an
    // analyst has no portal account — so the link has to follow the person.
    $link = lmsReminderLinkFor((string)$row['learner_type']);

    $greeting = $name !== '' ? 'Hello ' . htmlspecialchars($name, ENT_QUOTES) . ',' : 'Hello,';

    $body = '<div style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #333; line-height: 1.6;">'
          . '<p>' . $greeting . '</p>'
          . '<p>' . $lead . '</p>'
          . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES) . '" '
          . 'style="display:inline-block;padding:10px 18px;background:#2563eb;color:#fff;'
          . 'text-decoration:none;border-radius:6px;font-weight:bold;">Open your training</a></p>'
          . '<p style="color:#666;font-size:12px;">If the button does not work, copy this into your browser:<br>'
          . htmlspecialchars($link, ENT_QUOTES) . '</p>'
          . '</div>';

    return ['subject' => $subject, 'body' => $body];
}

/**
 * The public address of the right training page for this kind of learner.
 *
 * Built from the configured public URL rather than BASE_URL for the same reason
 * the portal's verification and password-reset links are: these emails are sent
 * by a cron, where there is no browser request to work a hostname out from, and
 * a link that lands on the wrong host reads as a phishing attempt.
 */
function lmsReminderLinkFor(string $learnerType): string
{
    $base = '';
    try {
        $conn = connectToDatabase();
        $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'public_base_url'");
        $st->execute();
        $base = trim((string)$st->fetchColumn());
    } catch (Throwable $e) {
        $base = '';
    }
    if ($base === '' && defined('BASE_URL')) $base = (string)BASE_URL;
    $base = rtrim($base, '/');

    return $learnerType === 'user'
        ? $base . '/self-service/training.php'
        : $base . '/lms/my-courses.php';
}

/** 'YYYY-MM-DD' → '20 November 2026'. Plain, unambiguous, and not zone-shifted. */
function fmt_naive_date_for_email(string $ymd): string
{
    $dt = DateTime::createFromFormat('Y-m-d', $ymd);
    return $dt ? $dt->format('j F Y') : $ymd;
}

/**
 * Do a reminder run.
 *
 * @param bool $dryRun true to work out what WOULD be sent and send nothing —
 *                     which is what the settings screen shows before you switch
 *                     the feature on. Nobody should have to turn on a mail merge
 *                     to find out how big it is.
 * @return array{would:int,sent:int,skipped:int,unreachable:int,failed:int,capped:bool}
 */
function lmsRemindersRun(PDO $conn, bool $dryRun = false): array
{
    $settings = lmsReminderSettings($conn);
    $result   = ['would' => 0, 'sent' => 0, 'skipped' => 0, 'unreachable' => 0, 'failed' => 0, 'capped' => false];

    // A dry run answers "what would happen if this were on", so it deliberately
    // ignores the on/off switch. A real run does not.
    if (!$dryRun && $settings[LMS_REM_ENABLED] !== '1') return $result;

    $due = lmsRemindersDue($conn, $settings);

    foreach ($due as $row) {
        if ($row['already_sent']) { $result['skipped']++; continue; }
        if (!$row['reachable'])   { $result['unreachable']++; continue; }

        $result['would']++;
        if ($dryRun) continue;

        if ($result['sent'] >= LMS_REM_MAX_PER_RUN) { $result['capped'] = true; break; }

        // 🔑 CLAIM IT BEFORE SENDING IT. The INSERT IGNORE is what makes two
        // overlapping runs — a cron tick and somebody opening the LMS in the
        // same second — send one email rather than two. Claiming afterwards
        // leaves exactly that window open.
        $claim = $conn->prepare(
            "INSERT IGNORE INTO lms_reminders_sent
                (learner_type, learner_id, course_id, reminder_kind, fingerprint, sent_datetime)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        );
        $claim->execute([$row['learner_type'], $row['learner_id'], $row['course_id'],
                         $row['reminder_kind'], $row['fingerprint']]);

        if ($claim->rowCount() === 0) { $result['skipped']++; continue; }   // somebody else got there first

        $msg = lmsReminderMessage($row);
        $ok  = false;
        try {
            $ok = ssSendSystemEmail($conn, (string)$row['email'], $msg['subject'], $msg['body'], 'training');
        } catch (Throwable $e) {
            $ok = false;
        }

        if ($ok) {
            $result['sent']++;
        } else {
            $result['failed']++;
            // ⚠️ Give the claim back. A reminder that failed to send has not been
            // sent, and leaving the row would mean this person is never reminded
            // about this deadline again — the failure would be permanent and
            // invisible. The send itself is recorded either way in the email send
            // log, which is where an administrator looks for the reason.
            $conn->prepare("DELETE FROM lms_reminders_sent
                             WHERE learner_type = ? AND learner_id = ? AND course_id = ?
                               AND reminder_kind = ? AND fingerprint = ?")
                 ->execute([$row['learner_type'], $row['learner_id'], $row['course_id'],
                            $row['reminder_kind'], $row['fingerprint']]);
        }
    }

    if (!$dryRun) {
        lmsReminderSaveSetting($conn, LMS_REM_LAST_RUN, gmdate('Y-m-d H:i:s'));
    }

    return $result;
}

/**
 * The opportunistic run: called when a manager opens the LMS console.
 *
 * ⚠️ Throttled to once an hour and silent about everything. This runs inside a
 * request somebody is waiting on, so it must never be the reason a page is slow
 * and must never be the reason a page errors.
 */
function lmsRemindersRunOpportunistic(PDO $conn): void
{
    try {
        $settings = lmsReminderSettings($conn);
        if ($settings[LMS_REM_ENABLED] !== '1')       return;
        if ($settings[LMS_REM_OPPORTUNISTIC] !== '1') return;

        $last = (string)$settings[LMS_REM_LAST_RUN];
        if ($last !== '' && (time() - strtotime($last . ' UTC')) < LMS_REM_OPPORTUNISTIC_MIN_SECONDS) {
            return;
        }

        lmsRemindersRun($conn, false);
    } catch (Throwable $e) {
        error_log('lms reminders (opportunistic): ' . $e->getMessage());
    }
}
