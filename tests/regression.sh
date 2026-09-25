#!/bin/bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d)"
WORK="$(cd "$WORK" && pwd -P)"
SANDBOX="$WORK/sandbox"
SERVER_PID=''
cleanup() {
  if [ -n "$SERVER_PID" ]; then
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
  fi
  rm -rf "$WORK"
}
trap cleanup EXIT

fail() { echo "FAIL: $*" >&2; exit 1; }
export CB_DEV_ROOT="$SANDBOX"
"$ROOT/dev/seed.sh" --quiet
[ -f "$SANDBOX/.unraid-certbot-dev-sandbox" ] || fail 'seed marker missing'

if CB_DEV_ROOT="$ROOT" "$ROOT/dev/seed.sh" --reset --quiet 2>/dev/null; then
  fail 'repository reset accepted'
fi
for protected in / "$HOME"; do
  if CB_DEV_ROOT="$protected" "$ROOT/dev/seed.sh" --reset --quiet 2>/dev/null; then
    fail "protected directory reset accepted: $protected"
  fi
done
mkdir "$WORK/unmarked"
printf 'keep\n' > "$WORK/unmarked/data"
if CB_DEV_ROOT="$WORK/unmarked" "$ROOT/dev/seed.sh" --reset --quiet 2>/dev/null; then
  fail 'unmarked external directory reset accepted'
fi
[ -f "$WORK/unmarked/data" ] || fail 'reset removed external data'
if CB_DEV_DOMAINS='../escape' "$ROOT/dev/seed.sh" --quiet 2>/dev/null; then
  fail 'traversal domain accepted'
fi
if CB_DEV_ROOT="$WORK/bad-seed" CB_DEV_CERT_DAYS=invalid "$ROOT/dev/seed.sh" --quiet 2>/dev/null; then
  fail 'failed certificate generation returned success'
fi
if CB_DEV_ROOT="$WORK/missing-seed" CB_DEV_STUB_SKIP_OUTPUT=yes "$ROOT/dev.sh" --port 39999 >/dev/null 2>&1; then
  fail 'dev.sh started without certificate outputs'
fi
if "$ROOT/dev/bin/docker" run -v "$SANDBOX/mnt/user/appdata/letsencrypt:/etc/letsencrypt" \
  -v "$SANDBOX/boot/config/plugins/unraid-certbot/cloudflare.ini:/cloudflare.ini:ro" \
  certbot/dns-cloudflare certonly --cert-name ../escape -d example.com >/dev/null 2>&1; then
  fail 'traversal cert name accepted'
fi
[ ! -e "$WORK/escape" ] || fail 'traversal wrote outside sandbox'

VERSION_BEFORE="$(cksum "$ROOT/VERSION")"
if "$ROOT/tools/build.sh" typo >/dev/null 2>&1; then fail 'invalid version accepted'; fi
[ "$(cksum "$ROOT/VERSION")" = "$VERSION_BEFORE" ] || fail 'invalid version changed VERSION'

CFG="$SANDBOX/boot/config/plugins/unraid-certbot/unraid-certbot.cfg"
CRED="$SANDBOX/boot/config/plugins/unraid-certbot/cloudflare.ini"
mkdir -p "$SANDBOX/boot/config/plugins/dynamix"
printf 'locale="zh_CN"\n' > "$SANDBOX/boot/config/plugins/dynamix/dynamix.cfg"
DIRECT_TRANSLATION="$(CB_DEV_ROOT="$SANDBOX" php -d disable_functions=_ -r '
  define("CB_NO_CLI_OUTPUT", true);
  require $argv[1];
  echo cb_t("Settings not saved");
' "$SANDBOX/usr/local/emhttp/plugins/unraid-certbot/include/status.php")"
[ "$DIRECT_TRANSLATION" = '设置未保存' ] || fail "direct endpoint cannot translate without Unraid page helper: $DIRECT_TRANSLATION"
printf 'LOCK_DIR="/boot"\nDOCKER="/does/not/exist"\n' >> "$CFG"
STATUS="$(CB_DOCKER="$SANDBOX/bin/docker" "$ROOT/dev.sh" status --no-seed)"
[[ "$STATUS" == *"docker 命令 : $SANDBOX/bin/docker"* ]] || fail 'unknown cfg key changed Docker'
[[ "$STATUS" == *'证书目录'* ]] || fail 'status unavailable'
ln -s "$WORK" "$SANDBOX/mnt/user/appdata/linked"
for bad_dir in /boot /mnt/user/appdata/linked; do
  printf 'CERT_DIR="%s"\n' "$bad_dir" >> "$CFG"
  if CB_DOCKER="$SANDBOX/bin/docker" "$ROOT/dev.sh" status --no-seed >/dev/null 2>&1; then
    fail "unsafe cert directory accepted: $bad_dir"
  fi
done
printf 'CERT_DIR="/mnt/user/appdata/letsencrypt"\n' >> "$CFG"
printf 'ACME_EMAIL="foo#bar@example.com" ; comment\n' >> "$CFG"
STATUS="$(CB_DOCKER="$SANDBOX/bin/docker" "$ROOT/dev.sh" status --no-seed)"
[[ "$STATUS" == *'foo#bar@example.com'* ]] || fail 'quoted hash was truncated'
printf 'ACME_EMAIL=""\n' >> "$CFG"
STATUS="$(CB_DOCKER="$SANDBOX/bin/docker" "$ROOT/dev.sh" status --no-seed)"
[[ "$STATUS" == *'邮箱        : <未设置>'* ]] || fail 'empty quoted value was not parsed'
printf 'ACME_EMAIL="foo\\bar@example.com"\n' >> "$CFG"
STATUS="$(CB_DOCKER="$SANDBOX/bin/docker" "$ROOT/dev.sh" status --no-seed)"
[[ "$STATUS" == *'foo\bar@example.com'* ]] || fail 'escaped backslash was not preserved'
printf 'ACME_EMAIL="foo#bar@example.com"\n' >> "$CFG"

export CB_DEV_NGINX_FAIL_ONCE="$WORK/nginx-failed"
if CB_DOCKER="$SANDBOX/bin/docker" "$ROOT/dev.sh" renew --no-seed --force > "$WORK/renew.log" 2>&1; then
  fail 'nginx failure returned success'
fi
[ -f "$SANDBOX/boot/config/plugins/unraid-certbot/nginx.pending" ] || fail 'pending nginx marker missing'
tail -n 1 "$SANDBOX/boot/config/plugins/unraid-certbot/history.tsv" | grep -q $'\tfailed\t' \
  || fail 'nginx failure missing from history'
CB_DOCKER="$SANDBOX/bin/docker" "$ROOT/dev.sh" renew --no-seed > "$WORK/retry.log" 2>&1 \
  || fail 'pending nginx restart did not recover'
[ ! -e "$SANDBOX/boot/config/plugins/unraid-certbot/nginx.pending" ] || fail 'pending marker remained'
tail -n 1 "$SANDBOX/boot/config/plugins/unraid-certbot/history.tsv" | grep -q '待处理的 nginx 重启已完成' \
  || fail 'nginx recovery missing from history'

BUNDLE="$SANDBOX/boot/config/ssl/certs/tower_unraid_bundle.pem"
BUNDLE_BEFORE="$(cksum "$BUNDLE")"
if PATH="$ROOT/tests/fixtures:$PATH" CB_TEST_FAIL_BACKUP=yes CB_DOCKER="$SANDBOX/bin/docker" \
  "$ROOT/dev.sh" renew --no-seed --force > "$WORK/backup-fail.log" 2>&1; then
  fail 'backup failure returned success'
fi
[ "$(cksum "$BUNDLE")" = "$BUNDLE_BEFORE" ] || fail 'backup failure changed bundle'
if PATH="$ROOT/tests/fixtures:$PATH" CB_TEST_FAIL_BUNDLE_MV=yes CB_DOCKER="$SANDBOX/bin/docker" \
  "$ROOT/dev.sh" renew --no-seed --force > "$WORK/mv-fail.log" 2>&1; then
  fail 'bundle rename failure returned success'
fi
[ "$(cksum "$BUNDLE")" = "$BUNDLE_BEFORE" ] || fail 'failed rename changed bundle'
[ ! -e "$SANDBOX/boot/config/plugins/unraid-certbot/nginx.pending" ] || fail 'failed rename left pending marker'
if find "$SANDBOX/boot/config/ssl/certs" -name '.unraid-certbot-*.??????' | grep -q .; then
  fail 'temporary bundle or backup file remained'
fi

CB_DEV_RUN_DELAY=2 CB_DOCKER="$SANDBOX/bin/docker" "$ROOT/dev.sh" renew --no-seed > "$WORK/first.log" 2>&1 &
FIRST_PID=$!
for _ in 1 2 3 4 5 6 7 8 9 10; do
  grep -q '请求证书' "$WORK/first.log" 2>/dev/null && break
  sleep 0.1
done
grep -q '请求证书' "$WORK/first.log" || fail 'first renewal did not reach lock'
if CB_DOCKER="$SANDBOX/bin/docker" "$ROOT/dev.sh" renew --no-seed > "$WORK/second.log" 2>&1; then
  fail 'concurrent renewal acquired lock'
else
  [ "$?" -eq 3 ] || fail 'concurrent renewal returned wrong status'
fi
wait "$FIRST_PID" || fail 'first renewal failed'

for ((n = 1; n <= 85; n++)); do
  printf '2026-09-25 12:00:%02d\tmanual\tsuccess\texample.com\tpage-item-%02d\n' \
    "$n" "$n" >> "$SANDBOX/boot/config/plugins/unraid-certbot/history.tsv"
done

PORT=$((20000 + RANDOM % 20000))
printf 'armed\n' > "$WORK/cron-fail"
printf 'armed\n' > "$WORK/cron-skipped"
CB_DEV_LOCALE=en_US CB_DEV_CRON_FAIL_ONCE="$WORK/cron-fail" CB_DEV_CRON_SKIP_ONCE="$WORK/cron-skipped" CB_DOCKER="$SANDBOX/bin/docker" php -d disable_functions=_ -S "127.0.0.1:$PORT" \
  -t "$SANDBOX/usr/local/emhttp" "$ROOT/dev/server.php" > "$WORK/server.log" 2>&1 &
SERVER_PID=$!
URL="http://127.0.0.1:$PORT/plugins/unraid-certbot/include/update.php"
for _ in 1 2 3 4 5 6 7 8 9 10; do
  curl -fsS "http://127.0.0.1:$PORT/" -o /dev/null 2>/dev/null && break
  sleep 0.2
done
PAGE1="$(curl -fsS "http://127.0.0.1:$PORT/Settings/UnraidCertbot?tab=history&history_page=1")"
PAGE2="$(curl -fsS "http://127.0.0.1:$PORT/Settings/UnraidCertbot?tab=history&history_page=2")"
PAGEMIDDLE="$(curl -fsS "http://127.0.0.1:$PORT/Settings/UnraidCertbot?tab=history&history_page=5")"
PAGELAST="$(curl -fsS "http://127.0.0.1:$PORT/Settings/UnraidCertbot?tab=history&history_page=999")"
if [[ "$PAGE1" != *'<td>page-item-85</td>'* || "$PAGE1" == *'<td>page-item-75</td>'* ]]; then
  printf '%s\n' "$PAGE1" | grep -oE '<td>page-item-[0-9]+</td>' | head -n 25 >&2 || true
  printf '%s\n' "$PAGE1" | head -c 600 >&2
  tail -n 12 "$WORK/server.log" >&2
  fail 'first history page has wrong records'
fi
[[ "$PAGE2" == *'<td>page-item-75</td>'* && "$PAGE2" != *'<td>page-item-85</td>'* ]] || fail 'second history page has wrong records'
LAST_PAGE=$(( ($(wc -l < "$SANDBOX/boot/config/plugins/unraid-certbot/history.tsv") + 9) / 10 ))
[[ "$PAGELAST" == *"aria-label=\"Page $LAST_PAGE\" class=\"active\" aria-current=\"page\""* && "$PAGELAST" != *'<td>page-item-85</td>'* ]] \
  || fail 'out-of-range history page was not clamped'
[[ "$PAGE1" == *'aria-label="Next page"'* ]] || fail 'history pagination controls missing'
[[ "$PAGEMIDDLE" == *'class="cb-history-ellipsis"'* && "$PAGEMIDDLE" == *'aria-label="Page 5" class="active" aria-current="page"'* ]] \
  || fail 'middle history page pagination is wrong'
if command -v node >/dev/null 2>&1; then
  node "$ROOT/tests/navigation.js" "http://127.0.0.1:$PORT" || fail 'tab navigation kept stale pagination'
fi

BODY='DOMAINS=example.com&ACME_EMAIL=admin@example.com&UNRAID_HOSTNAME=tower&PROPAGATION=60&CERT_DIR=/mnt/user/appdata/letsencrypt&SCHEDULE=daily&SCHEDULE_TIME=01:14&RESTART_NGINX=yes&STAGING=no'
ORIGIN="Origin: http://127.0.0.1:$PORT"
json_ok() {
  local expected="$1" response="$2"
  printf '%s' "$response" | php -r '
    $result = json_decode(stream_get_contents(STDIN), true);
    exit(is_array($result) && isset($result["ok"]) && $result["ok"] === ($argv[1] === "true") ? 0 : 1);
  ' "$expected"
}
CONFIG_PAGE="$(curl -fsS "http://127.0.0.1:$PORT/Settings/unraid-certbot?tab=config")"
[[ "$CONFIG_PAGE" == *'id="cb-config-form"'* && "$CONFIG_PAGE" != *'target="progressFrame"'* \
   && "$CONFIG_PAGE" != *'name="#apply"'* ]] || fail 'settings form still uses progressFrame'
[[ "$CONFIG_PAGE" == *'<div id="cb-update-result"'* ]] || fail 'save result can be rewritten by Unraid help handling'
BEFORE="$(cksum "$CFG" "$CRED")"
RESPONSE="$(curl -fsS -D "$WORK/update.headers" -H "$ORIGIN" -d "$BODY&LOCK_DIR=/boot&DOCKER=/bad&CF_API_TOKEN_NEW=abcdefghijabcdefghij" "$URL")"
grep -iq '^Content-Type: application/json' "$WORK/update.headers" || fail 'save response is not JSON'
json_ok false "$RESPONSE" || fail 'unknown POST key accepted'
[ "$(cksum "$CFG" "$CRED")" = "$BEFORE" ] || fail 'invalid POST changed config or token'
RESPONSE="$(curl -fsS -H "$ORIGIN" -d "$BODY&CF_API_TOKEN_CLEAR=yes&ACME_EMAIL=invalid" "$URL")"
json_ok false "$RESPONSE" || fail 'invalid clear-token POST accepted'
[ "$(cksum "$CFG" "$CRED")" = "$BEFORE" ] || fail 'invalid clear-token POST changed config or token'
RESPONSE="$(curl -fsS -H "$ORIGIN" -d "$BODY" --data-urlencode '#file=other/other.cfg' "$URL")"
json_ok false "$RESPONSE" || fail 'forged #file accepted'
[ "$(cksum "$CFG" "$CRED")" = "$BEFORE" ] || fail 'forged #file changed config or token'
RESPONSE="$(curl -fsS -H "$ORIGIN" -d "$BODY" --data-urlencode '#include=/plugins/other/update.php' "$URL")"
json_ok false "$RESPONSE" || fail 'forged #include accepted'
RESPONSE="$(curl -fsS -H "$ORIGIN" -d "$BODY&CERT_DIR=%2F" "$URL")"
json_ok false "$RESPONSE" || fail 'root cert directory accepted'
RESPONSE="$(curl -fsS -H "$ORIGIN" -d "$BODY" \
  --data-urlencode $'ACME_EMAIL=</script><script>alert(1)</script>\\\r\n汉字' "$URL")"
[[ "$RESPONSE" != *'</script><script>alert(1)'* ]] || fail 'script escaped into response'
[[ "$RESPONSE" == *'u6c49'* ]] || fail 'non-ASCII log payload was lost'
[ "$(curl -sS -o /dev/null -w '%{http_code}' -H 'Origin: https://evil.example' -d "$BODY" "$URL")" = 403 ] \
  || fail 'cross-origin POST accepted'
RESPONSE="$(curl -fsS -H "$ORIGIN" -d "$BODY" "$URL")"
json_ok true "$RESPONSE" || fail 'valid POST failed'
RESPONSE="$(curl -fsS -H "$ORIGIN" -d "$BODY&UI_LANGUAGE=zh_CN&csrf_token=unraid-form-token" "$URL")"
json_ok true "$RESPONSE" || fail 'Unraid form POST with csrf_token failed'
grep -q '^UI_LANGUAGE="zh_CN"$' "$CFG" || fail 'language setting was not saved'
if grep -q '^csrf_token=' "$CFG"; then fail 'Unraid form token was saved in config'; fi
if grep -Eq '^(LOCK_DIR|DOCKER)=' "$CFG"; then fail 'unknown cfg key survived save'; fi
CRON="$SANDBOX/boot/config/plugins/unraid-certbot/renew.cron"
BEFORE="$(cksum "$CFG" "$CRED" "$CRON")"
rm "$WORK/cron-fail"
RESPONSE="$(curl -fsS -H "$ORIGIN" -d "$BODY&SCHEDULE=weekly&CF_API_TOKEN_NEW=abcdefghijabcdefghij" "$URL")"
json_ok false "$RESPONSE" || fail 'cron failure returned success'
[ "$(cksum "$CFG" "$CRED" "$CRON")" = "$BEFORE" ] || fail 'cron failure did not restore all files'
rm "$WORK/cron-fail"
RESPONSE="$(curl -fsS -H "$ORIGIN" -d "$BODY&SCHEDULE=weekly&CF_API_TOKEN_CLEAR=yes" "$URL")"
json_ok false "$RESPONSE" || fail 'clear-token cron failure returned success'
[ "$(cksum "$CFG" "$CRED" "$CRON")" = "$BEFORE" ] || fail 'clear-token cron failure did not restore all files'
rm "$WORK/cron-skipped"
RESPONSE="$(curl -fsS -H "$ORIGIN" -d "$BODY&SCHEDULE=weekly" "$URL")"
json_ok false "$RESPONSE" || fail 'silent cron write failure returned success'
[ "$(cksum "$CFG" "$CRED" "$CRON")" = "$BEFORE" ] || fail 'silent cron write failure did not restore files'

RESPONSE="$(curl -fsS -H "$ORIGIN" -d "$BODY&UI_LANGUAGE=zh_CN" "$URL")"
json_ok true "$RESPONSE" || fail 'Chinese override save failed'
ZH_OVERRIDE="$(curl -fsS "http://127.0.0.1:$PORT/Settings/unraid-certbot?tab=status")"
[[ "$ZH_OVERRIDE" == *'>证书状态</button>'* && "$ZH_OVERRIDE" == *'<b>证书正常</b>'* ]] \
  || fail 'Chinese override ignored on English host'
BEFORE="$(cksum "$CFG")"
RESPONSE="$(curl -fsS -H "$ORIGIN" -d "$BODY&UI_LANGUAGE=invalid" "$URL")"
json_ok false "$RESPONSE" || fail 'invalid interface language accepted'
[ "$(cksum "$CFG")" = "$BEFORE" ] || fail 'invalid interface language changed configuration'
RESPONSE="$(curl -fsS -H "$ORIGIN" -d "$BODY&UI_LANGUAGE=auto" "$URL")"
json_ok true "$RESPONSE" || fail 'follow-Unraid save failed'
AUTO_EN="$(curl -fsS "http://127.0.0.1:$PORT/Settings/unraid-certbot?tab=status")"
[[ "$AUTO_EN" == *'>Certificate Status</button>'* ]] || fail 'follow-Unraid did not restore English'

kill "$SERVER_PID" 2>/dev/null || true
wait "$SERVER_PID" 2>/dev/null || true
SERVER_PID=''
CB_DEV_LOCALE=zh_CN CB_DOCKER="$SANDBOX/bin/docker" php -d disable_functions=_ -S "127.0.0.1:$PORT" \
  -t "$SANDBOX/usr/local/emhttp" "$ROOT/dev/server.php" > "$WORK/server-zh.log" 2>&1 &
SERVER_PID=$!
for _ in 1 2 3 4 5 6 7 8 9 10; do
  curl -fsS "http://127.0.0.1:$PORT/" -o /dev/null 2>/dev/null && break
  sleep 0.2
done
ZH_STATUS="$(curl -fsS "http://127.0.0.1:$PORT/Settings/unraid-certbot?tab=status")"
[[ "$ZH_STATUS" == *">证书状态</button>"* && "$ZH_STATUS" == *"<b>证书正常</b>"* \
   && "$ZH_STATUS" != *">Certificate Status</button>"* ]] || fail 'Chinese status preview not translated'
ZH_CONFIG="$(curl -fsS "http://127.0.0.1:$PORT/Settings/unraid-certbot?tab=config")"
[[ "$ZH_CONFIG" == *"Cloudflare API 令牌"* && "$ZH_CONFIG" == *"支持通配符"* ]] \
  || fail 'Chinese settings preview not translated'
ZH_HISTORY="$(curl -fsS "http://127.0.0.1:$PORT/Settings/unraid-certbot?tab=history")"
[[ "$ZH_HISTORY" == *'aria-label="下一页"'* ]] || fail 'Chinese history pagination not translated'
ZH_LOG="$(curl -fsS "http://127.0.0.1:$PORT/Settings/unraid-certbot?tab=log")"
[[ "$ZH_LOG" == *"日志超过 1 MiB 时会自动截断"* ]] || fail 'Chinese log preview not translated'
ZH_ACTION="$(curl -sS "http://127.0.0.1:$PORT/plugins/unraid-certbot/include/exec.php?action=unsupported")"
[[ "$ZH_ACTION" == *"不支持的操作"* ]] || fail 'Chinese action dialog not translated'
ACTION_URL="http://127.0.0.1:$PORT/plugins/unraid-certbot/include/exec.php"
ACTION_BOOT="$(curl -fsS "$ACTION_URL?action=status")"
[[ "$ACTION_BOOT" == *'method="post"'* && "$ACTION_BOOT" == *'name="csrf_token"'* \
    && "$ACTION_BOOT" != *'运行模式'* ]] || fail 'GET action executed or omitted CSRF bootstrap'
[ "$(curl -sS -o /dev/null -w '%{http_code}' -X PUT "$ACTION_URL")" = 405 ] \
  || fail 'action endpoint accepted PUT'
[ "$(curl -sS -o /dev/null -w '%{http_code}' -H 'Origin: https://evil.example' \
  -d 'action=status&csrf_token=probe' "$ACTION_URL")" = 403 ] || fail 'cross-origin action accepted'
ACTION_STATUS="$(curl -fsS -H "$ORIGIN" -d 'action=status&csrf_token=probe' "$ACTION_URL")"
[[ "$ACTION_STATUS" == *'addLog('* && "$ACTION_STATUS" != *'method="post"'* ]] \
  || fail 'POST status action failed'
RESPONSE="$(curl -fsS -H "$ORIGIN" -d "$BODY&UI_LANGUAGE=en_US" "$URL")"
json_ok true "$RESPONSE" && [[ "$RESPONSE" == *'Settings saved'* ]] || fail 'English override save failed'
EN_OVERRIDE="$(curl -fsS "http://127.0.0.1:$PORT/Settings/unraid-certbot?tab=status")"
[[ "$EN_OVERRIDE" == *'>Certificate Status</button>'* && "$EN_OVERRIDE" == *'<b>Certificate valid</b>'* ]] \
  || fail 'English override ignored on Chinese host'
EN_CONFIG="$(curl -fsS "http://127.0.0.1:$PORT/Settings/unraid-certbot?tab=config")"
[[ "$EN_CONFIG" == *'<option value="en_US" selected>English</option>'* ]] \
  || fail 'English override selection not shown'
EN_STATUS="$(CB_DEV_ROOT="$SANDBOX" CB_DOCKER="$SANDBOX/bin/docker" \
  "$SANDBOX/usr/local/emhttp/plugins/unraid-certbot/scripts/renew.sh" --status)"
[[ "$EN_STATUS" == *'Certificate directory check'* && "$EN_STATUS" != *'证书目录体检'* ]] \
  || fail 'English override not applied to renewal diagnostics'
EN_ACTION="$(curl -fsS -H "$ORIGIN" -d 'action=status&csrf_token=probe' "$ACTION_URL")"
[[ "$EN_ACTION" == *'Certificate directory check'* && "$EN_ACTION" != *'证书目录体检'* ]] \
  || fail 'English action output mixed languages'
RESPONSE="$(curl -fsS -H "$ORIGIN" -d "$BODY&UI_LANGUAGE=auto" "$URL")"
json_ok true "$RESPONSE" || fail 'follow-Unraid restore failed'
AUTO_ZH="$(curl -fsS "http://127.0.0.1:$PORT/Settings/unraid-certbot?tab=status")"
[[ "$AUTO_ZH" == *'>证书状态</button>'* ]] || fail 'follow-Unraid did not restore Chinese'

echo 'regression tests passed'
