#!/usr/bin/env bash
# Take a Cloudways on-demand backup of the gpacalculator.net server and wait for it to finish.
#
#   bash scripts/cloudways_backup.sh
#
# Reads CLOUDWAYS_EMAIL and CLOUDWAYS_API_KEY from ~/.config/gpacalculator/cloudways.env (outside the repo,
# mode 600). The key is only sent to api.cloudways.com; it is never printed, logged or written anywhere.
# Run before any settings, plugin or menu change on the live site (docs/LIVE_CHANGELOG.md, rule 3).
set -euo pipefail
set +x

ENV_FILE="$HOME/.config/gpacalculator/cloudways.env"
SERVER_IP="67.205.161.226"
API="https://api.cloudways.com/api/v1"

[[ -f "$ENV_FILE" ]] || { echo "Missing $ENV_FILE"; exit 1; }
perm="$(stat -f '%Lp' "$ENV_FILE" 2>/dev/null || stat -c '%a' "$ENV_FILE")"
[[ "$perm" == "600" ]] || { echo "$ENV_FILE must be mode 600 (is $perm): chmod 600 \"$ENV_FILE\""; exit 1; }
case "$(cd "$(dirname "$ENV_FILE")" && pwd -P)" in
  "$(cd "$(dirname "$0")/.." && pwd -P)"*) echo "Credentials file must be outside the repo"; exit 1 ;;
esac
# shellcheck disable=SC1090
source "$ENV_FILE"
: "${CLOUDWAYS_EMAIL:?missing in $ENV_FILE}" "${CLOUDWAYS_API_KEY:?missing in $ENV_FILE}"

json() { python3 -c "import json,sys; d=json.load(sys.stdin); print($1)"; }

TOKEN="$(curl -sS -X POST "$API/oauth/access_token" \
  --data-urlencode "email=$CLOUDWAYS_EMAIL" --data-urlencode "api_key=$CLOUDWAYS_API_KEY" | json "d.get('access_token','')")"
unset CLOUDWAYS_API_KEY
[[ -n "$TOKEN" ]] || { echo "Cloudways login failed (check the email and API key in $ENV_FILE)"; exit 1; }
AUTH=(-H "Authorization: Bearer $TOKEN")

SERVER_ID="$(curl -sS "${AUTH[@]}" "$API/server" | json "next((s['id'] for s in d.get('servers',[]) if s.get('public_ip')=='$SERVER_IP'),'')")"
[[ -n "$SERVER_ID" ]] || { echo "No Cloudways server with IP $SERVER_IP on this account"; exit 1; }
echo "Server $SERVER_ID ($SERVER_IP): starting on-demand backup…"

RESP="$(curl -sS -X POST "${AUTH[@]}" "$API/server/manage/backup" --data-urlencode "server_id=$SERVER_ID")"
OP="$(echo "$RESP" | json "d.get('operation_id','')")"
[[ -n "$OP" ]] || { echo "Backup request failed: $(echo "$RESP" | json "d.get('message') or d.get('error') or d")"; exit 1; }

for i in $(seq 1 120); do
  sleep 10
  STATE="$(curl -sS "${AUTH[@]}" "$API/operation/$OP" | json "(lambda o: f\"{o.get('is_completed')}|{o.get('status','')}|{o.get('message','')}\")(d.get('operation',{}))")"
  IFS='|' read -r DONE STATUS MSG <<< "$STATE"
  if [[ "$DONE" == "1" || "$DONE" == "True" ]]; then
    echo "Backup finished $(date -u '+%Y-%m-%d %H:%M UTC'): ${STATUS:-done} ${MSG}"
    exit 0
  fi
  printf '.'
done
echo; echo "Backup still running after 20 minutes (operation $OP); check Cloudways > Servers > Backups."
exit 1
