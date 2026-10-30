<?php
/**
 * API: run a directory sync, or preview one.
 *
 * POST { provider_id: N, mode: 'preview' | 'live' }
 *
 * A preview runs the identical code path and writes nothing to `users`, so what
 * it reports is what a live run would actually do — not a separate estimate that
 * can drift from the real thing.
 *
 * Administrators only. A sync creates and deactivates people wholesale; that is
 * not a thing an ordinary analyst should be able to set off.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';
require_once '../../includes/directory_sync.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();
    if (!analystIsAdmin($conn, (int)$_SESSION['analyst_id'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Administrator access required']);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $pid  = (int)($data['provider_id'] ?? 0);
    $mode = ($data['mode'] ?? 'preview') === 'live' ? 'live' : 'preview';

    // 🔑 Both kinds of people-source run through here, because "run the import
    // for provider N" is one action and the caller should not have to know
    // which transport it is. The protocol chooses the engine; everything above
    // this point — the admin guard, the mode, the response shape — is shared.
    $s = $conn->prepare("SELECT * FROM auth_providers WHERE id = ? AND protocol IN ('ldap', 'carddav', 'oidc')");
    $s->execute([$pid]);
    $provider = $s->fetch(PDO::FETCH_ASSOC);
    if (!$provider) {
        echo json_encode(['success' => false, 'error' => 'No such people source']);
        exit;
    }

    // An OIDC provider is a people source only when it is Microsoft Entra ID,
    // read through Graph (includes/entra_sync.php). Secrets are decrypted there.
    if ($provider['protocol'] === 'oidc') {
        require_once '../../includes/entra_sync.php';
        if (!entraIsEntraIssuer((string)$provider['issuer_url'])) {
            echo json_encode(['success' => false, 'error' => 'Only a Microsoft Entra ID provider can import people.']);
            exit;
        }
        if ((int)$provider['sync_enabled'] !== 1) {
            echo json_encode(['success' => false, 'error' => 'Directory sync is switched off for this provider. Turn it on and save first.']);
            exit;
        }
        $run = directorySyncRecorded($conn, $provider, $mode, fn() => entraSyncRun($conn, $provider, $mode, (int)$_SESSION['analyst_id']));
        echo json_encode(['success' => true, 'run' => $run]);
        exit;
    }

    if ($provider['protocol'] === 'carddav') {
        require_once '../../includes/carddav_sync.php';

        // For an address book, `enabled` IS the import switch — there is no
        // login page for it to appear on, so the two are not separable the way
        // they are for a directory. save_sso_provider.php keeps sync_enabled in
        // step with enabled for this protocol, and this is the check that makes
        // the promise real rather than implied.
        if ((int)$provider['enabled'] !== 1) {
            echo json_encode([
                'success' => false,
                'error'   => 'This address book is switched off. Turn it on and save first.',
            ]);
            exit;
        }
        // The password is encrypted at rest; cardDavConfigFromProvider()
        // unwraps it at the point of use, so nothing is decrypted here.
        $run = directorySyncRecorded($conn, $provider, $mode, fn() => cardDavSyncRun($conn, $provider, $mode, (int)$_SESSION['analyst_id']));
        echo json_encode(['success' => true, 'run' => $run]);
        exit;
    }

    if ((int)$provider['sync_enabled'] !== 1) {
        echo json_encode([
            'success' => false,
            'error'   => 'Directory sync is switched off for this provider. Turn it on and save first.',
        ]);
        exit;
    }

    // ⚠️ Only the bind password is encrypted, and decryptValue is what unwraps
    // it. Miss this and the bind fails as "Invalid credentials", which reads
    // like a wrong password rather than an un-decrypted one.
    $provider['ldap_bind_password'] = decryptValue($provider['ldap_bind_password'] ?? '');

    $run = directorySyncRecorded($conn, $provider, $mode, fn() => directorySyncRun($conn, $provider, $mode, (int)$_SESSION['analyst_id']));

    echo json_encode(['success' => true, 'run' => $run]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Runs one provider's sync as a MANUAL run of the directory-sync job (#182): recorded, and
 * it can clear that provider's failure, but it never makes the schedule look alive. A preview
 * writes nothing and is recorded as skipped - a dry run proves nothing.
 *
 * @param string $mode  'live' or 'preview'
 * @param callable $work  runs the sync and returns its run row
 */
function directorySyncRecorded(PDO $conn, array $provider, string $mode, callable $work): array
{
    require_once __DIR__ . '/../../includes/job_runs.php';
    $who = (int)$_SESSION['analyst_id'];
    if ($mode === 'preview') {
        JobRun::skipped($conn, 'directory_sync', 'manual', 'Dry run (Preview): nothing written.', $who);
        return $work();
    }
    $runId = JobRun::start($conn, 'directory_sync', 'manual', $who);
    try {
        $run = $work();
    } catch (Throwable $e) {
        JobRun::fail($conn, $runId, $e);
        throw $e;
    }
    JobRun::source($conn, $runId, 'directory_sync', 'provider:' . (int)$provider['id'],
        (string)($provider['display_name'] ?: ('provider ' . $provider['id'])),
        !in_array($run['status'] ?? '', ['failed', 'stopped'], true), $run['message'] ?? null);
    JobRun::finish($conn, $runId);
    return $run;
}
