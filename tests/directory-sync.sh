#!/bin/bash
# Directory sync (slice 2) against the real Samba AD fixture.
#
#   docker start freeitsm-samba-ad
#   bash docker/ldap-test/seed-ad-company.sh && bash docker/ldap-test/seed-ad-people.sh
#   bash tests/directory-sync.sh
#
# Skips cleanly (exit 0) when the fixture is not running, so it can sit in a test
# suite without failing on machines that have never started the containers.
PHP=/c/wamp64/bin/php/php8.4.0/php.exe
APP=/c/wamp64/www/freeitsm-app
PW=$(sed -n "s/.*define('DB_PASSWORD',[[:space:]]*'\([^']*\)').*/\1/p" /c/wamp64/db_config.php)
MY() { /c/wamp64/bin/mysql/mysql9.1.0/bin/mysql.exe -u root ${PW:+-p"$PW"} FREEITSM -sN -e "$1" 2>/dev/null; }

PROVIDER=$(MY "SELECT id FROM auth_providers WHERE protocol='ldap' AND sync_enabled=1 ORDER BY id LIMIT 1")
if [ -z "$PROVIDER" ]; then echo "SKIP: no LDAP provider with sync enabled."; exit 0; fi
if ! docker ps --format '{{.Names}}' 2>/dev/null | grep -q freeitsm-samba-ad; then
  echo "SKIP: freeitsm-samba-ad is not running."; exit 0
fi

pass=0; fail=0
chk() { if [ "$2" = "$3" ]; then printf "  ok    %-54s %s\n" "$1" "$3"; pass=$((pass+1));
        else printf "  FAIL  %-54s got [%s] want [%s]\n" "$1" "$3" "$2"; fail=$((fail+1)); fi; }
run() { (cd "$APP" && $PHP cron/directory_sync.php --provider=$PROVIDER "$@" --quiet >/dev/null 2>&1); }

echo; echo "Directory sync — provider $PROVIDER"; printf '%.0s=' {1..80}; echo

# --- a live run first, so there is a baseline for everything below -----------
run
BASE=$(MY "SELECT sync_last_count FROM auth_providers WHERE id=$PROVIDER")
chk "a live run records a baseline" "1" "$([ "$BASE" -gt 0 ] && echo 1 || echo 0)"

# --- idempotent ---------------------------------------------------------------
run
LAST=$(MY "SELECT CONCAT(created_count,'/',updated_count) FROM directory_sync_runs WHERE provider_id=$PROVIDER AND mode='live' ORDER BY id DESC LIMIT 1")
chk "running twice changes nothing the second time" "0/0" "$LAST"

# --- PREVIEW WRITES NOTHING ---------------------------------------------------
# The single most important property: what you are shown is produced by the same
# code that would do the work, and doing it must not be a side effect of looking.
BEFORE=$(MY "SELECT CONCAT(COUNT(*),'/',IFNULL(SUM(is_managed),0),'/',IFNULL(SUM(is_active),0)) FROM users")
run --preview
AFTER=$(MY "SELECT CONCAT(COUNT(*),'/',IFNULL(SUM(is_managed),0),'/',IFNULL(SUM(is_active),0)) FROM users")
chk "a preview changes no user at all" "$BEFORE" "$AFTER"
chk "  ...but is still logged as a run" "preview" \
    "$(MY "SELECT mode FROM directory_sync_runs WHERE provider_id=$PROVIDER ORDER BY id DESC LIMIT 1")"

# --- THE SANITY BRAKE ---------------------------------------------------------
# Simulates the classic disaster: somebody narrows the base DN. Without the
# brake this deactivates most of a company and reports success.
ORIG=$(MY "SELECT sync_base_dn FROM auth_providers WHERE id=$PROVIDER")
ACTIVE_BEFORE=$(MY "SELECT COUNT(*) FROM users WHERE is_managed=1 AND is_active=1")
MY "UPDATE auth_providers SET sync_base_dn='OU=IT,OU=Staff,OU=Northwind,DC=ad,DC=freeitsm,DC=test' WHERE id=$PROVIDER" >/dev/null
run
chk "a sudden drop STOPS the run" "stopped" \
    "$(MY "SELECT status FROM directory_sync_runs WHERE provider_id=$PROVIDER ORDER BY id DESC LIMIT 1")"
chk "  ...and nobody was deactivated by it" "$ACTIVE_BEFORE" \
    "$(MY "SELECT COUNT(*) FROM users WHERE is_managed=1 AND is_active=1")"
chk "  ...and the baseline was NOT overwritten" "$BASE" \
    "$(MY "SELECT sync_last_count FROM auth_providers WHERE id=$PROVIDER")"
MY "UPDATE auth_providers SET sync_base_dn='$ORIG' WHERE id=$PROVIDER" >/dev/null

# CONTROL: with the brake switched off, the same run goes ahead. Without this,
# "stopped" above might be for some unrelated reason.
MY "UPDATE auth_providers SET sync_brake_percent=0, sync_base_dn='OU=IT,OU=Staff,OU=Northwind,DC=ad,DC=freeitsm,DC=test' WHERE id=$PROVIDER" >/dev/null
run
chk "CONTROL: brake off, the same run proceeds" "ok" \
    "$(MY "SELECT status FROM directory_sync_runs WHERE provider_id=$PROVIDER ORDER BY id DESC LIMIT 1")"
MY "UPDATE auth_providers SET sync_brake_percent=20, sync_base_dn='$ORIG' WHERE id=$PROVIDER" >/dev/null
# Put everybody back and re-establish the baseline the brake compares against.
MY "UPDATE users SET is_active=1, deactivated_datetime=NULL, sync_missed_count=0
      WHERE is_managed=1 AND auth_provider_id=$PROVIDER AND directory_username <> 'q.deleon'" >/dev/null
run

# --- what actually came in ----------------------------------------------------
chk "mailbox-less people are imported"  "1" "$(MY "SELECT COUNT(*)>0 FROM users WHERE is_managed=1 AND email IS NULL")"
chk "an account disabled in AD is inactive here" "0" "$(MY "SELECT is_active FROM users WHERE directory_username='q.deleon'")"
chk "two people may share a display name" "2" "$(MY "SELECT COUNT(*) FROM users WHERE display_name='John Smith' AND is_managed=1")"
chk "an OU outside the scope is NOT imported" "0" "$(MY "SELECT COUNT(*) FROM users WHERE directory_username IN ('z.contractor','w.vendor')")"
chk "reporting lines are built"          "1" "$(MY "SELECT COUNT(*)>5 FROM users WHERE is_managed=1 AND manager_id IS NOT NULL")"
chk "a cross-department manager is allowed" "1" \
    "$(MY "SELECT COUNT(*) FROM users u JOIN users m ON m.id=u.manager_id WHERE u.directory_username='a.chen' AND m.directory_username='c.doherty'")"
chk "directory ids are stored (survives renames)" "1" \
    "$(MY "SELECT COUNT(*)>0 FROM user_sso_identities WHERE provider_id=$PROVIDER")"
chk "non-ASCII names survive intact"     "1" \
    "$(MY "SELECT COUNT(*) FROM users WHERE directory_username='j.muller' AND display_name='Jürgen Müller'")"

# --- the OU browser's selection, end to end -----------------------------------
# The unit tests in directory-sync-scopes.php prove the DN arithmetic. These
# prove the engine acts on it: a count alone would be satisfied by excluding the
# WRONG two people, so both checks name who must and must not be there.
ROOT='ou=northwind,dc=ad,dc=freeitsm,dc=test'
SAVED_INC=$(MY "SELECT COALESCE(sync_ou_includes,'') FROM auth_providers WHERE id=$PROVIDER")
SAVED_EXC=$(MY "SELECT COALESCE(sync_ou_excludes,'') FROM auth_providers WHERE id=$PROVIDER")

MY "UPDATE auth_providers SET sync_ou_includes='$ROOT', sync_ou_excludes=NULL, sync_brake_percent=0 WHERE id=$PROVIDER" >/dev/null
run --preview
WHOLE=$(MY "SELECT id FROM directory_sync_runs WHERE provider_id=$PROVIDER ORDER BY id DESC LIMIT 1")
chk "CONTROL: ticking the whole tree finds the contractors" "2" \
    "$(MY "SELECT COUNT(*) FROM directory_sync_entries WHERE run_id=$WHOLE AND directory_username IN ('z.contractor','w.vendor')")"

MY "UPDATE auth_providers SET sync_ou_excludes='ou=contractors,$ROOT' WHERE id=$PROVIDER" >/dev/null
run --preview
CARVED=$(MY "SELECT id FROM directory_sync_runs WHERE provider_id=$PROVIDER ORDER BY id DESC LIMIT 1")
chk "a carved-out branch drops exactly those people" "0" \
    "$(MY "SELECT COUNT(*) FROM directory_sync_entries WHERE run_id=$CARVED AND directory_username IN ('z.contractor','w.vendor')")"
chk "...and nobody else: the carve-out costs exactly 2" "2" \
    "$(MY "SELECT (SELECT seen_count FROM directory_sync_runs WHERE id=$WHOLE)
               - (SELECT seen_count FROM directory_sync_runs WHERE id=$CARVED)")"

# Ticking a parent AND a child must not count the overlap twice — the brake
# compares counts, so an inflated one is not merely cosmetic.
MY "UPDATE auth_providers SET sync_ou_includes='$ROOT\nou=staff,$ROOT', sync_ou_excludes=NULL WHERE id=$PROVIDER" >/dev/null
run --preview
chk "overlapping ticks do not double-count" \
    "$(MY "SELECT seen_count FROM directory_sync_runs WHERE id=$WHOLE")" \
    "$(MY "SELECT seen_count FROM directory_sync_runs WHERE provider_id=$PROVIDER ORDER BY id DESC LIMIT 1")"

# A ticked branch is stored as a DN, and a DN changes when an OU is renamed.
# Both directions failed silently before this; neither may again.
MY "UPDATE auth_providers SET sync_ou_includes='ou=staff,$ROOT\nou=nowhere,$ROOT', sync_ou_excludes=NULL WHERE id=$PROVIDER" >/dev/null
run --preview
chk "a vanished branch REFUSES the run (never a partial import)" "failed" \
    "$(MY "SELECT status FROM directory_sync_runs WHERE provider_id=$PROVIDER ORDER BY id DESC LIMIT 1")"
chk "...naming the branch, not just 'no such object'" "1" \
    "$(MY "SELECT message LIKE '%ou=nowhere%Browse directory%' FROM directory_sync_runs WHERE provider_id=$PROVIDER ORDER BY id DESC LIMIT 1")"

# A vanished carve-out is additive, not destructive: run, but say so loudly.
MY "UPDATE auth_providers SET sync_ou_includes='$ROOT', sync_ou_excludes='ou=gone,$ROOT' WHERE id=$PROVIDER" >/dev/null
run --preview
chk "a vanished carve-out still runs" "ok" \
    "$(MY "SELECT status FROM directory_sync_runs WHERE provider_id=$PROVIDER ORDER BY id DESC LIMIT 1")"
chk "...but warns that excluded people are now coming in" "1" \
    "$(MY "SELECT message LIKE '%are being imported%' FROM directory_sync_runs WHERE provider_id=$PROVIDER ORDER BY id DESC LIMIT 1")"

MY "UPDATE auth_providers SET sync_ou_includes='$ROOT', sync_ou_excludes='ou=contractors,$ROOT' WHERE id=$PROVIDER" >/dev/null
run --preview
chk "CONTROL: real branches produce no warning at all" "" \
    "$(MY "SELECT COALESCE(message,'') FROM directory_sync_runs WHERE provider_id=$PROVIDER ORDER BY id DESC LIMIT 1")"

MY "UPDATE auth_providers SET sync_ou_includes=NULLIF('$SAVED_INC',''), sync_ou_excludes=NULLIF('$SAVED_EXC',''), sync_brake_percent=20 WHERE id=$PROVIDER" >/dev/null

# --- the log ------------------------------------------------------------------
chk "the run log records per-person detail" "1" \
    "$(MY "SELECT COUNT(*)>0 FROM directory_sync_entries e JOIN directory_sync_runs r ON r.id=e.run_id WHERE r.provider_id=$PROVIDER")"

printf '%.0s=' {1..80}; echo
echo "  $pass passed, $fail failed"
[ "$fail" -eq 0 ] || exit 1
