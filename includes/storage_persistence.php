<?php
/**
 * Storage persistence — does what FreeITSM writes actually survive an update?
 *
 * ⚠️ THE PROBLEM THIS EXISTS FOR (issue #109, and the tail of #102).
 *
 * Under Docker, `docker compose up -d --build` does not update the running
 * container. It builds a new image and REPLACES the container, discarding
 * everything written inside it. Only volumes — storage that lives outside the
 * container — are carried across.
 *
 * So a directory that is not on a volume is emptied every time the operator
 * updates. And the way that surfaces is unusually cruel: the database IS on a
 * volume, so it survives intact. What is left is a complete and correct set of
 * records pointing at files that no longer exist, and the honest message for
 * that state — "recorded but missing from storage" — reads to the operator as
 * data corruption rather than as a missing mount.
 *
 * 🔑 THE ONLY MOMENT ANYTHING CAN BE DONE ABOUT IT IS BEFORE THE REBUILD. Once
 * the container is replaced the old one is removed and its writable layer goes
 * with it; the files are not recoverable from Docker by any route. That is why
 * this check exists at all: an after-the-fact explanation is worth very little,
 * and a warning in documentation only reaches people who go looking. This one
 * reaches them where they already are.
 *
 * ⚠️ THE RULE IS "IS IT ON THE ROOT FILESYSTEM", NOT "IS IT A MOUNT POINT".
 *
 * The obvious test — does this directory's device differ from its PARENT's,
 * i.e. is it a mount point — raises a false alarm on a perfectly safe and
 * reasonably common setup. Somebody who bind-mounts the whole application
 * directory has every one of these folders on the host, entirely safe, and not
 * one of them is a mount point in its own right. Telling that operator their
 * data is about to be destroyed would be worse than saying nothing, because a
 * check that cries wolf is switched off and then never believed again.
 *
 * Comparing against the device of "/" instead answers the question actually
 * being asked: is this on the container's own writable layer, which is thrown
 * away, or on something else, which is not. A directory inside a mounted parent
 * is correctly reported as safe. Verified against four cases — named volume,
 * bind mount, directory inside a mounted parent, and nothing mounted at all.
 *
 * ⚠️ NEVER GUESS "AT RISK". Every uncertainty resolves to 'unknown', which the
 * callers report as an unknown rather than folding into the warning count. A
 * container is the only place this can be answered, and even there stat() can
 * fail; an alarm raised on a failed stat() is an alarm about nothing.
 *
 * 🔑 THE DIRECTORY LIST BELOW IS THE SAME LIST AS THE ONE IN .gitignore — the
 * "Upload directories" and "Module-specific runtime content" blocks. It is
 * maintained by hand in both places, which is precisely the shape of failure
 * that caused #109: the deployment recipe belongs to no module, so nobody
 * updating a module thinks to revisit it. ⚠️ ADD A DIRECTORY HERE WHENEVER YOU
 * ADD ONE THERE.
 */

if (!defined('STORAGE_PERSISTENCE_LOADED')) {
    define('STORAGE_PERSISTENCE_LOADED', true);
}

/**
 * The environment variable our own image sets to identify itself.
 *
 * Set in the Dockerfile, so it is present in every container built from it and
 * needs no filesystem access to read — which is the point (see below).
 */
const STORAGE_PERSISTENCE_ENV_MARKER = 'FREEITSM_CONTAINER';

/**
 * Is this PHP running inside a container?
 *
 * Two signals, asked in this order. Either can say YES; only both failing says no.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  1. Our own image says so
 * ─────────────────────────────────────────────────────────────────────────────
 * The Dockerfile sets FREEITSM_CONTAINER=1, so the image identifies itself
 * without touching the disk at all. 🔑 THIS IS THE SIGNAL THAT CANNOT BE
 * SUPPRESSED, and it exists because the one below can be.
 *
 * ⚠️ Read with local_only = true, so the value comes from the real process
 * environment and not from anything the SAPI folded into $_SERVER. A request
 * header cannot reach it (a header would arrive as HTTP_FREEITSM_CONTAINER),
 * but reading the process environment directly means not having to reason about
 * that at all.
 *
 * ⭐ It is also an escape hatch worth having. Podman rebuilds with the same
 * consequences as Docker and leaves no /.dockerenv, so until now this file had
 * nothing to say to those operators. Setting the variable opts them in.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  2. /.dockerenv, which the Docker daemon writes into every container
 * ─────────────────────────────────────────────────────────────────────────────
 * 🔴 THE @ IS LOAD-BEARING (GH #127). That file lives at the ROOT of the
 * filesystem, and a host with `open_basedir` set — shared hosting and control
 * panels routinely set it — refuses the read and raises a WARNING. FreeITSM
 * ships with display_errors on, and this runs before the System page emits its
 * first byte, so the warning was rendered above the whole page. The reporter saw
 * a wall of red text on an admin screen every time he opened it.
 *
 * ⚠️ The try/catch around the caller does NOT help: a PHP warning is not a
 * Throwable, so the guard written to "never break the page over a diagnostic"
 * cannot see this one.
 *
 * ⚠️ AND WHY THE PATH ITSELF MUST NOT MOVE. The obvious repair — point it
 * somewhere open_basedir allows, such as under DOCUMENT_ROOT — silences the
 * warning by asking a question with a permanently false answer. Docker writes
 * /.dockerenv at the filesystem root and nowhere else; nothing puts one in the
 * web root. Detection would fail for every Docker user and the storage warning
 * this whole file exists to raise would go quiet for exactly the people it was
 * built for. It tests clean on any machine that is not running Docker, because
 * there the correct answer is false too.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Being wrong towards silence is otherwise deliberate: an unrecognised runtime
 * produces nothing, never a false warning. Nothing in this file should ever have
 * anything to say to somebody running on WAMP.
 */
function storagePersistenceInContainer(): bool
{
    $marker = getenv(STORAGE_PERSISTENCE_ENV_MARKER, true);
    if ($marker !== false && filter_var($marker, FILTER_VALIDATE_BOOLEAN)) {
        return true;
    }

    // @ — see above. Suppressed, never removed, and never re-pointed.
    return @file_exists('/.dockerenv');
}

/**
 * Would `open_basedir` stop us reading /.dockerenv?
 *
 * Diagnostics only — nothing decides anything on this. It exists so D013 can say
 * "could not look" instead of reporting a blocked read as "not a container",
 * which are very different statements to put in front of an operator.
 */
function storagePersistenceRootReadable(): bool
{
    $basedir = (string) ini_get('open_basedir');
    if ($basedir === '') return true;   // unrestricted

    foreach (explode(PATH_SEPARATOR, $basedir) as $allowed) {
        if (rtrim(trim($allowed), '/\\') === '') return true;   // '/' is allowed
    }
    return false;
}

/**
 * Every directory the running application writes user files into.
 *
 * 'path'  absolute, resolved from the application root
 * 'rel'   how an operator would recognise it, and what goes in a volume line
 * 'label' plain English — what an operator loses if this one is not persisted
 */
function storagePersistenceDirectories(): array
{
    $root = dirname(__DIR__);

    $dirs = [
        ['rel' => 'tickets/attachments',              'label' => 'Attachments that arrived on inbound email'],
        ['rel' => 'change-management/attachments',    'label' => 'Files attached to a change record'],
        ['rel' => 'uploads',                          'label' => 'Documents attached to tickets, notes, assets and articles, and asset import files'],
        ['rel' => 'recordings',                       'label' => 'Screen recordings made from the self-service portal'],
        ['rel' => 'lms/content',                      'label' => 'Uploaded course content and SCORM packages'],
        ['rel' => 'contracts/rfp-builder/uploads',    'label' => 'Files uploaded to an RFP'],
        ['rel' => 'system/uploads/branding',          'label' => 'Your logo and other branding images'],
        ['rel' => 'war-room/attachments',             'label' => 'Files shared in a war room'],
    ];

    $out = [];
    foreach ($dirs as $d) {
        $d['path'] = $root . '/' . $d['rel'];
        $out[] = $d;
    }

    // The encryption key sits outside the application root and is configurable,
    // so it is resolved rather than hard-coded. It is listed last because it is
    // the least likely to be wrong and by far the most serious if it is: mailbox
    // passwords and integration credentials are encrypted with it, and losing it
    // does not corrupt them, it makes them permanently unreadable.
    if (defined('ENCRYPTION_KEY_PATH') && ENCRYPTION_KEY_PATH !== '') {
        $keyDir = dirname((string) ENCRYPTION_KEY_PATH);
        if ($keyDir !== '' && $keyDir !== '.') {
            $out[] = [
                'rel'      => $keyDir,
                'path'     => $keyDir,
                'label'    => 'The encryption key — mailbox passwords and integration credentials cannot be read without it',
                'critical' => true,
            ];
        }
    }

    return $out;
}

/**
 * Assess one directory. Returns 'persisted' | 'at_risk' | 'missing' | 'unknown'.
 */
function storagePersistenceStatus(string $dir, ?int $rootDev = null): string
{
    if (!is_dir($dir)) {
        // Not an alarm. Several of these are created on first use by
        // uploadPrepareDir(), so an install that has never had an RFP upload
        // legitimately has no RFP folder.
        return 'missing';
    }

    if ($rootDev === null) {
        $rootStat = @stat('/');
        if ($rootStat === false || !isset($rootStat['dev'])) return 'unknown';
        $rootDev = (int) $rootStat['dev'];
    }

    $s = @stat($dir);
    if ($s === false || !isset($s['dev'])) return 'unknown';

    return ((int) $s['dev'] !== $rootDev) ? 'persisted' : 'at_risk';
}

/**
 * The whole picture, for whichever screen is asking.
 *
 * 'applicable' is false on anything that is not a container, and every caller
 * must check it before showing the operator anything at all. On a native
 * install the question is meaningless — `git pull` does not delete these
 * folders — and a warning there would be pure noise.
 */
function storagePersistenceReport(): array
{
    $report = [
        'applicable'   => storagePersistenceInContainer(),
        'root_device'  => null,
        'directories'  => [],
        'at_risk'      => 0,
        'persisted'    => 0,
        'unknown'      => 0,
        'missing'      => 0,
        'critical_at_risk' => false,
    ];

    if (!$report['applicable']) {
        return $report;
    }

    $rootStat = @stat('/');
    $rootDev  = ($rootStat !== false && isset($rootStat['dev'])) ? (int) $rootStat['dev'] : null;
    $report['root_device'] = $rootDev;

    foreach (storagePersistenceDirectories() as $d) {
        $status = storagePersistenceStatus($d['path'], $rootDev);
        $d['status'] = $status;
        $report['directories'][] = $d;

        if ($status === 'at_risk') {
            $report['at_risk']++;
            if (!empty($d['critical'])) $report['critical_at_risk'] = true;
        } elseif ($status === 'persisted') {
            $report['persisted']++;
        } elseif ($status === 'unknown') {
            $report['unknown']++;
        } else {
            $report['missing']++;
        }
    }

    return $report;
}

/**
 * Does this directory hold anything an operator would mind losing?
 *
 * 🔑 THIS IS WHAT DECIDES WHICH ADVICE TO GIVE, and it must not be inferred from
 * anything else. An install with nothing stored yet can simply add the volumes;
 * an install with files in these folders has to copy them out FIRST, because a
 * new volume is filled from the image rather than from the container it replaces.
 * Give the first instruction to somebody in the second situation and they lose
 * their files while following the documentation.
 *
 * ⚠️ The obvious proxy — "has an analyst been created yet" — is WRONG HERE. The
 * shipped schema seeds an `admin` account, so every installation looks provisioned
 * from the moment the database exists, including one that is thirty seconds old.
 *
 * The execution guards are shipped in these folders, so they are not evidence of
 * use and are excluded.
 */
function storagePersistenceHasFiles(string $dir): bool
{
    if (!is_dir($dir)) return false;

    $ignore = ['.', '..', '.htaccess', 'web.config', '.gitkeep', 'index.html'];
    $items  = @scandir($dir);
    if ($items === false) return false;

    foreach ($items as $item) {
        if (in_array($item, $ignore, true)) continue;
        $p = $dir . '/' . $item;
        // A sub-directory counts — email attachments live in per-message folders,
        // so the useful question is "is anything under here", not "are there loose
        // files at the top". A top-level count once reported "0 files" beside six
        // working attachments.
        if (is_dir($p)) {
            if (storagePersistenceHasFiles($p)) return true;
            continue;
        }
        return true;
    }
    return false;
}

/**
 * Is anything actually stored in the directories that are at risk?
 */
function storagePersistenceAnythingToLose(array $report): bool
{
    foreach ($report['directories'] as $d) {
        if ($d['status'] !== 'at_risk') continue;
        if (storagePersistenceHasFiles($d['path'])) return true;
    }
    return false;
}

/**
 * The volume lines an operator would need to add, ready to paste.
 *
 * Only the directories actually at risk are offered. A block that lists
 * everything, including what is already correct, invites the operator to paste
 * over a working configuration.
 */
function storagePersistenceSuggestedVolumes(array $report): array
{
    $lines = [];
    foreach ($report['directories'] as $d) {
        if ($d['status'] !== 'at_risk') continue;
        $name = storagePersistenceVolumeName($d['rel']);
        if ($name === '') continue;
        $lines[] = '      - ' . $name . ':' . $d['path'];
    }
    return $lines;
}

/** A volume name derived from the directory, stable and readable. */
function storagePersistenceVolumeName(string $rel): string
{
    $name = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($rel, '/')));
    return trim($name, '-');
}

/**
 * A COMPLETE docker-compose.override.yml, ready to save as a new file.
 *
 * 🔑 WHY AN OVERRIDE FILE AND NOT "EDIT docker-compose.yml".
 *
 * docker-compose.yml is tracked in git, and the operator updates by pulling. If
 * they have edited it and we later change it too, `git pull` REFUSES — "your
 * local changes would be overwritten by merge" — and the entire upgrade stops,
 * not just that file. The advice everybody then finds is `git checkout --
 * docker-compose.yml`, which makes the pull work again by silently throwing away
 * the volumes they added. The next rebuild then destroys their files exactly as
 * before, and nothing anywhere says why. Tested; that is precisely what happens.
 *
 * Compose reads docker-compose.override.yml automatically, with no extra flags,
 * and MERGES it — verified with `docker compose config`, base and override
 * volumes all present. The file is gitignored, so it is never in a pull's way
 * and an upgrade can never revert it.
 *
 * It is also a better instruction to give: "create this file with this content"
 * needs no judgement about where to paste or how far to indent, and it can be
 * checked by eye against what we printed.
 */
function storagePersistenceOverrideFile(array $report): string
{
    $mounts = [];
    $names   = [];
    foreach ($report['directories'] as $d) {
        if ($d['status'] !== 'at_risk') continue;
        $name = storagePersistenceVolumeName($d['rel']);
        if ($name === '') continue;
        $mounts[] = '      - ' . $name . ':' . $d['path'];
        $names[]  = '  ' . $name . ':';
    }
    if (!$mounts) return '';

    return "services:\n  app:\n    volumes:\n"
         . implode("\n", $mounts) . "\n\n"
         . "volumes:\n"
         . implode("\n", $names) . "\n";
}
