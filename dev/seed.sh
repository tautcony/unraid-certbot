#!/bin/bash
#
# 生成本地调试沙箱 dev/run/。
#
#   ./dev/seed.sh           基于现有情况完善文件
#   ./dev/seed.sh --reset   沙箱重建
#   ./dev/seed.sh --quiet   不输出进度
#
# 样例数据可用 CB_DEV_HOST / CB_DEV_EMAIL / CB_DEV_DOMAINS / CB_DEV_CERT_DAYS 覆盖。
#
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEV_ROOT="${CB_DEV_ROOT:-$ROOT/dev/run}"
MARKER='.unraid-certbot-dev-sandbox'
DEV_ROOT="${DEV_ROOT%/}"
case "$DEV_ROOT" in
  ''|/|"$HOME"|"$ROOT"|"$ROOT/dev"|/boot|/mnt|/usr|/etc|/var|/tmp)
    echo "错误：不安全的沙箱路径 $DEV_ROOT" >&2; exit 1 ;;
esac
case "$DEV_ROOT" in
  "$ROOT"/*) [ "$DEV_ROOT" = "$ROOT/dev/run" ] || { echo "错误：仓库内仅允许 dev/run 沙箱" >&2; exit 1; } ;;
esac
[[ "$DEV_ROOT" = /* && "$DEV_ROOT" != *'/../'* && "$DEV_ROOT" != *'/./'* && "$DEV_ROOT" != */.. && "$DEV_ROOT" != */. ]] || {
  echo "错误：沙箱路径必须是规范化的绝对路径" >&2; exit 1;
}
if [ -e "$DEV_ROOT" ] || [ -L "$DEV_ROOT" ]; then
  [ ! -L "$DEV_ROOT" ] && [ "$(cd "$DEV_ROOT" && pwd -P)" = "$DEV_ROOT" ] || {
    echo "错误：沙箱路径经过符号链接" >&2; exit 1;
  }
  if [ "$(ls -A "$DEV_ROOT" | head -n 1)" != '' ] \
    && [ "$(cat "$DEV_ROOT/$MARKER" 2>/dev/null)" != 'unraid-certbot dev sandbox' ] \
    && [ "$DEV_ROOT" != "$ROOT/dev/run" ]; then
    echo "错误：目标目录非空且没有沙箱 marker" >&2; exit 1
  fi
fi
parent="$DEV_ROOT"
while [ "$parent" != / ]; do
  [ ! -L "$parent" ] || { echo "错误：沙箱路径经过符号链接" >&2; exit 1; }
  parent="$(dirname "$parent")"
done

DOCROOT="$DEV_ROOT/usr/local/emhttp"
PLUGIN_SRC="$ROOT/source/unraid-certbot/usr/local/emhttp/plugins/unraid-certbot"
CFG_DIR="$DEV_ROOT/boot/config/plugins/unraid-certbot"
LETSENCRYPT_DIR="$DEV_ROOT/mnt/user/appdata/letsencrypt"
SSL_DIR="$DEV_ROOT/boot/config/ssl/certs"

HOST="${CB_DEV_HOST:-tower}"
EMAIL="${CB_DEV_EMAIL:-admin@example.com}"
DOMAINS="${CB_DEV_DOMAINS:-example.com,www.example.com}"
CERT_DAYS="${CB_DEV_CERT_DAYS:-90}"
PRIMARY="${DOMAINS%%,*}"
IFS=',' read -r -a DOMAIN_LIST <<< "$DOMAINS"
for domain in "${DOMAIN_LIST[@]}"; do
  check_domain="${domain#\*.}"
  if ! [[ "$check_domain" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ ]] \
    || [[ "$check_domain" == *..* ]] || [[ "$check_domain" != *.* ]]; then
    echo "错误：非法样例域名 $domain" >&2; exit 1
  fi
done
BUNDLE="$SSL_DIR/${HOST}_unraid_bundle.pem"

RESET="no"
QUIET="no"
for arg in "$@"; do
  case "$arg" in
    --reset) RESET="yes" ;;
    --quiet) QUIET="yes" ;;
    -h|--help) awk 'NR>2 && /^#/ { sub(/^# ?/, ""); print; next } NR>2 { exit }' "$0"; exit 0 ;;
    *) echo "未知参数: $arg" >&2; exit 1 ;;
  esac
done

say() { [ "$QUIET" = "yes" ] || printf '%s\n' "$*"; }

[ -d "$PLUGIN_SRC" ] || { echo "错误：找不到插件源码目录 $PLUGIN_SRC" >&2; exit 1; }

if [ "$RESET" = "yes" ]; then
  if [ -d "$DEV_ROOT" ] && [ "$(cat "$DEV_ROOT/$MARKER" 2>/dev/null)" != 'unraid-certbot dev sandbox' ]; then
    echo "错误：拒绝清空没有沙箱 marker 的目录 $DEV_ROOT" >&2; exit 1
  fi
  say "==> 清空沙箱 $DEV_ROOT"
  if [ -d "$DEV_ROOT" ]; then rm -rf "$DEV_ROOT" || exit 1; fi
fi

say "==> 初始化沙箱 $DEV_ROOT"

mkdir -p \
  "$DOCROOT/plugins/dynamix/include" \
  "$CFG_DIR" \
  "$LETSENCRYPT_DIR" \
  "$SSL_DIR" \
  "$DEV_ROOT/var/local/emhttp" \
  "$DEV_ROOT/var/run" \
  "$DEV_ROOT/bin" \
  "$DEV_ROOT/etc/rc.d"
printf '%s\n' 'unraid-certbot dev sandbox' > "$DEV_ROOT/$MARKER"

# -n: 已存在的软链直接替换，不当成目录下钻
ln -sfn "$PLUGIN_SRC"                                           "$DOCROOT/plugins/unraid-certbot"
ln -sfn "$ROOT/dev/emhttp/logging.htm"                          "$DOCROOT/logging.htm"
ln -sfn "$ROOT/dev/emhttp/plugins/dynamix/include/Wrappers.php" "$DOCROOT/plugins/dynamix/include/Wrappers.php"
ln -sfn "$ROOT/dev/bin/docker"                                  "$DEV_ROOT/bin/docker"
ln -sfn "$ROOT/dev/bin/flock"                                   "$DEV_ROOT/bin/flock"
ln -sfn "$ROOT/dev/bin/rc.nginx"                                "$DEV_ROOT/etc/rc.d/rc.nginx"

chmod 0755 "$ROOT/dev/bin/docker" "$ROOT/dev/bin/flock" "$ROOT/dev/bin/rc.nginx" 2>/dev/null

printf 'NAME="%s"\n' "$HOST" > "$DEV_ROOT/var/local/emhttp/var.ini"

if [ ! -f "$CFG_DIR/unraid-certbot.cfg" ]; then
  cat > "$CFG_DIR/unraid-certbot.cfg" <<EOF
# 本地调试样例配置
ACME_EMAIL="${EMAIL}"
UNRAID_HOSTNAME="${HOST}"
DOMAINS="${DOMAINS}"
PROPAGATION="10"
CERT_DIR="/mnt/user/appdata/letsencrypt"
SCHEDULE="daily"
RESTART_NGINX="yes"
STAGING="no"
EOF
  say "    写入样例配置 $CFG_DIR/unraid-certbot.cfg"
fi

if [ ! -s "$CFG_DIR/cloudflare.ini" ]; then
  cat > "$CFG_DIR/cloudflare.ini" <<'EOF'
# mock token
dns_cloudflare_api_token = dev0000000000000000000000000000000000
EOF
  chmod 600 "$CFG_DIR/cloudflare.ini"
  say "    写入 Cloudflare 凭据（stub）$CFG_DIR/cloudflare.ini（权限 600）"
fi

# 样例证书复用 dev/bin/docker 生成，产物布局与 certbot 一致
if [ ! -s "$BUNDLE" ] || [ ! -s "$LETSENCRYPT_DIR/live/$PRIMARY/fullchain.pem" ]; then
  say "==> 生成样例证书（$CERT_DAYS 天有效期）"
  stub_log="$(mktemp)"
  CB_DEV_CERT_DAYS="$CERT_DAYS" "$ROOT/dev/bin/docker" run --rm \
    -v "${LETSENCRYPT_DIR}:/etc/letsencrypt" \
    -v "${CFG_DIR}/cloudflare.ini:/cloudflare.ini:ro" \
    certbot/dns-cloudflare certonly \
    --dns-cloudflare \
    --dns-cloudflare-credentials /cloudflare.ini \
    --dns-cloudflare-propagation-seconds 10 \
    --non-interactive --agree-tos --email "$EMAIL" \
    --config-dir /etc/letsencrypt --work-dir /etc/letsencrypt/work \
    --logs-dir /etc/letsencrypt/logs \
    --cert-name "$PRIMARY" \
    $(for d in "${DOMAIN_LIST[@]}"; do printf -- '-d %s ' "$d"; done) > "$stub_log" 2>&1
  stub_rc=$?
  [ "$QUIET" = "yes" ] || cat "$stub_log"
  [ "$stub_rc" -eq 0 ] || cat "$stub_log" >&2
  rm -f "$stub_log"
  [ "$stub_rc" -eq 0 ] || exit "$stub_rc"

  live="$LETSENCRYPT_DIR/live/$PRIMARY"
  if [ -s "$live/fullchain.pem" ] && [ -s "$live/privkey.pem" ]; then
    cat "$live/fullchain.pem" "$live/privkey.pem" > "$BUNDLE" || exit 1
    chmod 600 "$BUNDLE" || exit 1
    say "    写入 $BUNDLE"
  else
    echo "错误：样例证书缺少必要产物" >&2
    exit 1
  fi
fi

ts() { date -r "$1" '+%Y-%m-%d %H:%M:%S' 2>/dev/null || date -d "@$1" '+%Y-%m-%d %H:%M:%S'; }
NOW="$(date +%s)"

if [ ! -f "$CFG_DIR/history.tsv" ]; then
  {
    printf '%s\t%s\t%s\t%s\t%s\n' "$(ts $((NOW - 86400 * 6)))" cron   "成功" "$DOMAINS" "证书未变化，无需更新"
    printf '%s\t%s\t%s\t%s\t%s\n' "$(ts $((NOW - 86400 * 3)))" manual "失败" "$DOMAINS" "certbot 执行失败 (退出码 1)，详见日志"
    printf '%s\t%s\t%s\t%s\t%s\n' "$(ts $((NOW - 86400 * 3 + 600)))" manual "成功" "$DOMAINS" "证书已更新，到期时间 $(ts $((NOW + 89 * 86400)))"
  } > "$CFG_DIR/history.tsv"
  say "    写入样例续期历史 $CFG_DIR/history.tsv"
fi

if [ ! -f "$CFG_DIR/certbot.log" ]; then
  {
    printf '[%s] ======== 开始续期 (触发: manual, staging=no) ========\n' "$(ts $((NOW - 86400 * 3 + 600)))"
    printf '[%s] 请求证书：%s\n' "$(ts $((NOW - 86400 * 3 + 600)))" "${DOMAINS//,/ }"
    printf '[%s] ✅ 新证书已写入 %s\n' "$(ts $((NOW - 86400 * 3 + 601)))" "$BUNDLE"
    printf '[%s] 重启 nginx...\n' "$(ts $((NOW - 86400 * 3 + 601)))"
    printf '[%s] ✅ nginx 已重启\n' "$(ts $((NOW - 86400 * 3 + 601)))"
    printf '[%s] ✅ 完成，证书到期时间：%s\n' "$(ts $((NOW - 86400 * 3 + 601)))" "$(openssl x509 -noout -enddate -in "$BUNDLE" 2>/dev/null | cut -d= -f2)"
  } > "$CFG_DIR/certbot.log"
  say "    写入样例日志 $CFG_DIR/certbot.log"
fi

say ""
say "✅ 沙箱就绪：$DEV_ROOT"
say "   浏览器预览：./dev.sh        （默认 http://127.0.0.1:8080）"
say "   命令行状态：./dev.sh status"
say "   执行续期：./dev.sh renew"
