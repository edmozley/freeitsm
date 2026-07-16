#!/usr/bin/env bash
# Re-seed the Samba AD DC test directory.
# Usage: bash seed-ad.sh      (container freeitsm-samba-ad must be running)
set -e
D=freeitsm-samba-ad

docker exec $D samba-tool user create alice 'Passw0rd!alice' \
  --given-name=Alice --surname=Analyst --mail-address=alice@ad.freeitsm.test
docker exec $D samba-tool user create bob 'Passw0rd!bob' \
  --given-name=Bob --surname=Tech --mail-address=bob@ad.freeitsm.test
docker exec $D samba-tool user create carol 'Passw0rd!carol' \
  --given-name=Carol --surname=Customer --mail-address=carol@ad.freeitsm.test
docker exec $D samba-tool user create svc-freeitsm 'Passw0rd!svc' \
  --description="FreeITSM read-only service account"

docker exec $D samba-tool group add "ITSM Analysts"
docker exec $D samba-tool group add "ITSM Admins"
docker exec $D samba-tool group add "IT Department"

docker exec $D samba-tool group addmembers "ITSM Analysts" alice,bob
docker exec $D samba-tool group addmembers "ITSM Admins" alice

# Nested: "ITSM Analysts" is a member of "IT Department", so alice is only
# TRANSITIVELY in "IT Department". Stock memberOf will not show it --
# that gap is deliberate, it is what the nested-group code must handle.
docker exec $D samba-tool group addmembers "IT Department" "ITSM Analysts" --object-types=group

echo "Seeded. Base DN: DC=ad,DC=freeitsm,DC=test"
