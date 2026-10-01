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
# Format checks (values are never printed): TextEdit can save smart quotes, CRLF or rich text.
python3 - "$ENV_FILE" <<'PY' || exit 1
import re, sys
raw = open(sys.argv[1], "rb").read()
problems = []
if raw.startswith(b"{\\rtf"): problems.append("file is rich text (RTF): in TextEdit use Format > Make Plain Text, then save")
if b"\r" in raw: problems.append("file has Windows/Mac line endings (CR)")
text = raw.decode("utf-8", "replace")
if any(c in text for c in "\u201c\u201d\u2018\u2019"): problems.append("file has curly quotes; use straight quotes or none")
for name in ("CLOUDWAYS_EMAIL", "CLOUDWAYS_API_KEY"):
    m = re.search(r"^\s*(export\s+)?" + name + r"(\s*)=(\s*)(.*)$", text, re.M)
    if not m: problems.append(f"{name}= line not found"); continue
    if m.group(2) or m.group(3): problems.append(f"{name}: remove spaces around '='")
    v = m.group(4).strip().strip('"').strip("'")
    if not v: problems.append(f"{name} is empty")
    elif v != v.strip(): problems.append(f"{name} has spaces at the start or end")
    if name == "CLOUDWAYS_EMAIL" and v and "@" not in v: problems.append("CLOUDWAYS_EMAIL doesn't look like an email address")
if problems:
    print("Fix the credentials file:"); [print(" -", p) for p in problems]; sys.exit(1)
PY
# shellcheck disable=SC1090
source "$ENV_FILE"
: "${CLOUDWAYS_EMAIL:?missing in $ENV_FILE}" "${CLOUDWAYS_API_KEY:?missing in $ENV_FILE}"

json() { python3 -c "import json,sys; d=json.load(sys.stdin); print($1)"; }

LOGIN="$(curl -sS -X POST "$API/oauth/access_token" \
  --data-urlencode "email=$CLOUDWAYS_EMAIL" --data-urlencode "api_key=$CLOUDWAYS_API_KEY" -w '\n%{http_code}')"
unset CLOUDWAYS_API_KEY
CODE="${LOGIN##*$'\n'}"; BODY="${LOGIN%$'\n'*}"
TOKEN="$(echo "$BODY" | json "d.get('access_token','')" 2>/dev/null || true)"
if [[ -z "$TOKEN" ]]; then
  MSG="$(echo "$BODY" | python3 -c "import json,sys
try:
    d=json.load(sys.stdin); print(d.get('error_description') or d.get('message') or d.get('error') or '')
except Exception: print(sys.stdin.read()[:200])" 2>/dev/null)"
  echo "Cloudways login failed (HTTP $CODE): ${MSG:-no message}"
  echo "Check CLOUDWAYS_EMAIL is your Cloudways login email and CLOUDWAYS_API_KEY is the key from Cloudways > Account > API Integration."
  exit 1
fi
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
