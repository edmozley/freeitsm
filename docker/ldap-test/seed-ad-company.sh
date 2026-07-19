#!/usr/bin/env bash
# Build a realistic small-company structure in the Samba AD test DC.
#
# "Northwind Trading Ltd" — ~40 staff, one IT team running FreeITSM.
# Deliberately includes the awkward cases a real directory has and a flat
# test fixture never does:
#   - people nested in per-department OUs (not all in CN=Users)
#   - an account with NO email address        -> JIT must refuse it cleanly
#   - a DISABLED leaver                       -> must never be able to sign in
#   - names with an apostrophe and an umlaut  -> filter escaping / UTF-8
#   - nested groups (All-Staff contains the dept groups, not the people)
#
# Usage: bash seed-ad-company.sh    (container freeitsm-samba-ad must be running)
set -e
D=freeitsm-samba-ad
BASE="DC=ad,DC=freeitsm,DC=test"
PW='Passw0rd!2026'

st() { docker exec "$D" samba-tool "$@"; }

echo "--- organisational units ---"
st ou create "OU=Northwind,$BASE"                     2>/dev/null || true
st ou create "OU=Staff,OU=Northwind,$BASE"            2>/dev/null || true
st ou create "OU=IT,OU=Staff,OU=Northwind,$BASE"      2>/dev/null || true
st ou create "OU=Sales,OU=Staff,OU=Northwind,$BASE"   2>/dev/null || true
st ou create "OU=Finance,OU=Staff,OU=Northwind,$BASE" 2>/dev/null || true
st ou create "OU=Groups,OU=Northwind,$BASE"           2>/dev/null || true
st ou create "OU=Service Accounts,OU=Northwind,$BASE" 2>/dev/null || true
st ou create "OU=Leavers,OU=Northwind,$BASE"          2>/dev/null || true

mkuser() { # username password ou given surname mail title dept
  local u=$1 p=$2 ou=$3 gn=$4 sn=$5 mail=$6 title=$7 dept=$8
  if [ -n "$mail" ]; then
    st user create "$u" "$p" --userou="$ou" --given-name="$gn" --surname="$sn" \
       --mail-address="$mail" --job-title="$title" --department="$dept" >/dev/null
  else
    # No mail attribute at all — the interesting edge case.
    st user create "$u" "$p" --userou="$ou" --given-name="$gn" --surname="$sn" \
       --job-title="$title" --department="$dept" >/dev/null
  fi
  echo "  + $u"
}

echo "--- IT team (these are the FreeITSM analysts) ---"
mkuser a.chen     'Nw!Chen2026'    "OU=IT,OU=Staff,OU=Northwind" \
       "Amy"     "Chen"     "a.chen@northwind.test"     "IT Manager"           "IT"
mkuser r.patel    'Nw!Patel2026'   "OU=IT,OU=Staff,OU=Northwind" \
       "Raj"     "Patel"    "r.patel@northwind.test"    "Service Desk Analyst" "IT"
mkuser s.oconnor  "Nw!Conn2026"    "OU=IT,OU=Staff,OU=Northwind" \
       "Siobhan" "O'Connor" "s.oconnor@northwind.test"  "2nd Line Engineer"    "IT"
mkuser j.muller   'Nw!Mull2026'    "OU=IT,OU=Staff,OU=Northwind" \
       "Jürgen"  "Müller"   "j.muller@northwind.test"   "Infrastructure Engineer" "IT"

echo "--- Sales ---"
mkuser t.brooks   'Nw!Broo2026'    "OU=Sales,OU=Staff,OU=Northwind" \
       "Tom"     "Brooks"   "t.brooks@northwind.test"   "Account Manager"      "Sales"
mkuser l.garcia   'Nw!Garc2026'    "OU=Sales,OU=Staff,OU=Northwind" \
       "Lucía"   "García"   "l.garcia@northwind.test"   "Sales Executive"      "Sales"

echo "--- Finance ---"
mkuser p.ndlovu   'Nw!Ndlo2026'    "OU=Finance,OU=Staff,OU=Northwind" \
       "Precious" "Ndlovu"  "p.ndlovu@northwind.test"   "Finance Officer"      "Finance"

echo "--- edge cases ---"
# Warehouse staffer who was never given a mailbox.
mkuser w.noemail  'Nw!NoMa2026'    "OU=Staff,OU=Northwind" \
       "Wendy"   "Warehouse" ""                          "Warehouse Operative"  "Operations"
# Someone who has left: account still in the directory but disabled.
mkuser x.leaver   'Nw!Leav2026'    "OU=Leavers,OU=Northwind" \
       "Xavier"  "Leaver"   "x.leaver@northwind.test"   "Former Employee"      "Sales"
st user disable x.leaver
echo "  ! x.leaver disabled"

echo "--- service account ---"
st user create svc-ldap 'Nw!Svc2026' --userou="OU=Service Accounts,OU=Northwind" \
   --description="FreeITSM read-only directory lookup account" >/dev/null || true
st user setexpiry svc-ldap --noexpiry >/dev/null 2>&1 || true
echo "  + svc-ldap"

echo "--- groups ---"
st group add "NW-IT-Support"  --groupou="OU=Groups,OU=Northwind" 2>/dev/null || true
st group add "NW-IT-Admins"   --groupou="OU=Groups,OU=Northwind" 2>/dev/null || true
st group add "NW-Sales"       --groupou="OU=Groups,OU=Northwind" 2>/dev/null || true
st group add "NW-Finance"     --groupou="OU=Groups,OU=Northwind" 2>/dev/null || true
st group add "NW-All-Staff"   --groupou="OU=Groups,OU=Northwind" 2>/dev/null || true

st group addmembers "NW-IT-Support" r.patel,s.oconnor,j.muller
st group addmembers "NW-IT-Admins"  a.chen
# w.noemail is in the SELF-SERVICE user group deliberately. She is the scenario
# GitHub #47 is actually about: warehouse staff who were never given a mailbox,
# who need the PORTAL rather than analyst access. Before she was added here she
# belonged to no group at all, which only ever exercised the "gate off" path —
# so the whole no-mailbox portal journey was untestable and its gaps invisible.
st group addmembers "NW-Sales"      t.brooks,l.garcia,w.noemail
st group addmembers "NW-Finance"    p.ndlovu

# NESTED: All-Staff contains the department GROUPS, not the people. Nobody has
# NW-All-Staff in their memberOf — it only shows via the AD chain matching rule.
st group addmembers "NW-All-Staff" NW-IT-Support,NW-IT-Admins,NW-Sales,NW-Finance --object-types=group

echo
echo "Done. Base DN for Northwind: OU=Northwind,$BASE"
echo "Service account: svc-ldap@AD.FREEITSM.TEST / Nw!Svc2026"
