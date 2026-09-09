<?php
/**
 * LMS API: the training reminder settings.
 *
 * GET                    read them, plus a DRY-RUN count of what would go out now
 * POST {action:'save'}   write them
 * POST {action:'test'}   send one reminder-shaped email to the caller
 *
 * 🔑 THE DRY RUN IS THE POINT OF THE GET. Nobody should have to switch on a mail
 * merge to find out how big it is. The count is produced by the same code that
 * does the sending, so the number on the screen is the number that will go —
 * a separate "estimate" query would be a second implementation and would
 * eventually disagree with the first.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/lms_reminders.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireCapabilityJson(Cap::LMS_MANAGE);

$analystId = (int)$_SESSION['analyst_id'];

try {
    $conn = connectToDatabase();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $s = lmsReminderSettings($conn);

        // The dry run ignores the on/off switch on purpose — "what would this do
        // if I turned it on" is the question somebody has BEFORE turning it on.
        $preview = lmsRemindersRun($conn, true);

        echo json_encode([
            'success'  => true,
            'settings' => [
                'enabled'        => $s[LMS_REM_ENABLED] === '1',
                'days_before'    => $s[LMS_REM_DAYS_BEFORE],
                'chase'          => $s[LMS_REM_CHASE] === '1',
                'chase_every'    => (int)$s[LMS_REM_CHASE_EVERY],
                'opportunistic'  => $s[LMS_REM_OPPORTUNISTIC] === '1',
                'last_run'       => $s[LMS_REM_LAST_RUN],
            ],
            'preview'  => $preview,
            // Whether a mailbox capable of sending exists at all. Without one the
            // switch is decoration, and saying so here is kinder than letting
            // somebody turn it on and wonder why nothing arrives.
            'can_send' => ssGetSendingMailbox($conn) !== null,
        ]);
        exit;
    }

    $input  = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = (string)($input['action'] ?? '');

    if ($action === 'save') {
        // ⚠️ Validated, not trusted. "every 0 days" is an infinite loop of email
        // and "-3 days before" is a different feature; both are refused here
        // rather than tidied up silently, so the box shows what was meant.
        $days = lmsReminderParseDays((string)($input['days_before'] ?? ''));
        $every = (int)($input['chase_every'] ?? 7);
        if ($every < 1 || $every > 365) {
            echo json_encode(['success' => false, 'error' => 'Chase every: choose between 1 and 365 days']);
            exit;
        }
        if (!empty($input['enabled']) && !$days && empty($input['chase'])) {
            echo json_encode(['success' => false, 'error' => 'Reminders are on, but nothing would ever be sent — set some days before the deadline, or switch on chasing.']);
            exit;
        }

        lmsReminderSaveSetting($conn, LMS_REM_ENABLED,       !empty($input['enabled']) ? '1' : '0');
        lmsReminderSaveSetting($conn, LMS_REM_DAYS_BEFORE,   implode(',', $days));
        lmsReminderSaveSetting($conn, LMS_REM_CHASE,         !empty($input['chase']) ? '1' : '0');
        lmsReminderSaveSetting($conn, LMS_REM_CHASE_EVERY,   (string)$every);
        lmsReminderSaveSetting($conn, LMS_REM_OPPORTUNISTIC, !empty($input['opportunistic']) ? '1' : '0');

        echo json_encode(['success' => true, 'days_before' => implode(', ', $days)]);
        exit;
    }

    if ($action === 'test') {
        // Sent to the person pressing the button, and to nobody else. A test that
        // could name a recipient is a way to send mail to an arbitrary address
        // from the service desk's own mailbox.
        $st = $conn->prepare("SELECT full_name, email FROM analysts WHERE id = ?");
        $st->execute([$analystId]);
        $me = $st->fetch(PDO::FETCH_ASSOC);

        if (!$me || empty($me['email'])) {
            echo json_encode(['success' => false, 'error' => 'Your account has no email address, so there is nowhere to send a test.']);
            exit;
        }

        $msg = lmsReminderMessage([
            'name'          => $me['full_name'],
            'course_title'  => 'Example course',
            'due_day'       => date('Y-m-d', strtotime('+7 days')),
            'days_left'     => 7,
            'reminder_kind' => 'before',
            'learner_type'  => 'analyst',
        ]);

        $ok = ssSendSystemEmail($conn, (string)$me['email'], '[Test] ' . $msg['subject'], $msg['body'], 'training');
        echo json_encode($ok
            ? ['success' => true, 'sent_to' => $me['email']]
            : ['success' => false, 'error' => 'The test could not be sent. Check the mailbox settings and the outbound email log.']);
        exit;
    }

    if ($action === 'run') {
        // Run it now, for somebody who does not want to wait for the schedule.
        // Guarded by the same capability as everything else on this screen.
        $result = lmsRemindersRun($conn, false);
        echo json_encode(['success' => true, 'result' => $result]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
} catch (Throwable $e) {
    error_log('lms reminder_settings: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Something went wrong']);
}
