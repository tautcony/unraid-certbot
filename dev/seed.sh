#!/bin/bash
#
# unraid-certbot 本地调试沙箱初始化。
#
# 在 dev/run/ 下搭出一份「像 Unraid 那样」的目录树：
#
#   dev/run/usr/local/emhttp/                        $docroot（.page 就在这里被渲染）
#     plugins/unraid-certbot -> source/...          软链，改源码立即生效
#     plugins/dynamix/include/Wrappers.php -> dev/… 仿真的 Unraid 函数
#     logging.htm -> dev/emhttp/logging.htm         仿真的日志框
#   dev/run/boot/config/plugins/unraid-certbot/     配置、凭据、历史、日志
#   dev/run/boot/config/ssl/certs/<主机名>_unraid_bundle.pem
#   dev/run/boot/config/letsencrypt/live/<主域名>/  certbot 的证书目录
#   dev/run/var/local/emhttp/var.ini                Unraid 服务器名
#   dev/run/bin/docker -> dev/bin/docker            假 docker
#   dev/run/etc/rc.d/rc.nginx -> dev/bin/rc.nginx   假 nginx 重启
#
# 用法：
#   ./dev/seed.sh              # 缺什么补什么，已存在的文件不动
#   ./dev/seed.sh --reset      # 先删掉整个沙箱再重建
#
# 可用环境变量覆盖样例数据：
#   CB_DEV_HOST（默认 tower）CB_DEV_EMAIL（默认 admin@example.com）
#   CB_DEV_DOMAINS（默认 example.com,www.example.com）
#   CB_DEV_CERT_DAYS（默认 90）
#
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEV_ROOT="${CB_DEV_ROOT:-$ROOT/dev/run}"
DOCROOT="$DEV_ROOT/usr/local/emhttp"
PLUGIN_SRC="$ROOT/source/unraid-certbot/usr/local/emhttp/plugins/unraid-certbot"
CFG_DIR="$DEV_ROOT/boot/config/plugins/unraid-certbot"
LETSENCRYPT_DIR="$DEV_ROOT/boot/config/letsencrypt"
SSL_DIR="$DEV_ROOT/boot/config/ssl/certs"

HOST="${CB_DEV_HOST:-tower}"
EMAIL="${CB_DEV_EMAIL:-admin@example.com}"
DOMAINS="${CB_DEV_DOMAINS:-example.com,www.example.com}"
CERT_DAYS="${CB_DEV_CERT_DAYS:-90}"
PRIMARY="${DOMAINS%%,*}"
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

# 进度信息：--quiet 时全部吞掉，警告和错误仍然走 stderr
say() { [ "$QUIET" = "yes" ] || printf '%s\n' "$*"; }

[ -d "$PLUGIN_SRC" ] || { echo "错误：找不到插件源码目录 $PLUGIN_SRC" >&2; exit 1; }

if [ "$RESET" = "yes" ]; then
  say "==> 清空沙箱 $DEV_ROOT"
  rm -rf "$DEV_ROOT"
fi

say "==> 初始化沙箱 $DEV_ROOT"

# ---------------------------------------------------------------------------
# 目录与软链
# ---------------------------------------------------------------------------

mkdir -p \
  "$DOCROOT/plugins/dynamix/include" \
  "$CFG_DIR" \
  "$LETSENCRYPT_DIR" \
  "$SSL_DIR" \
  "$DEV_ROOT/var/local/emhttp" \
  "$DEV_ROOT/var/run" \
  "$DEV_ROOT/bin" \
  "$DEV_ROOT/etc/rc.d"

# -n 保证已存在的目录/文件软链被替换而不是被当成目录下钻
ln -sfn "$PLUGIN_SRC"                                   "$DOCROOT/plugins/unraid-certbot"
ln -sfn "$ROOT/dev/emhttp/logging.htm"                  "$DOCROOT/logging.htm"
ln -sfn "$ROOT/dev/emhttp/plugins/dynamix/include/Wrappers.php" \
                                                        "$DOCROOT/plugins/dynamix/include/Wrappers.php"
ln -sfn "$ROOT/dev/bin/docker"                          "$DEV_ROOT/bin/docker"
ln -sfn "$ROOT/dev/bin/rc.nginx"                        "$DEV_ROOT/etc/rc.d/rc.nginx"

chmod 0755 "$ROOT/dev/bin/docker" "$ROOT/dev/bin/rc.nginx" 2>/dev/null

# ---------------------------------------------------------------------------
# Unraid 服务器名（决定 bundle 文件名）
# ---------------------------------------------------------------------------

printf 'NAME="%s"\n' "$HOST" > "$DEV_ROOT/var/local/emhttp/var.ini"

# ---------------------------------------------------------------------------
# 插件配置（已存在则不覆盖，方便保留手工改动）
# ---------------------------------------------------------------------------

if [ ! -f "$CFG_DIR/unraid-certbot.cfg" ]; then
  cat > "$CFG_DIR/unraid-certbot.cfg" <<EOF
# 本地调试样例配置，可随意修改；dev.sh reset 会重建
ACME_EMAIL="${EMAIL}"
UNRAID_HOSTNAME="${HOST}"
DOMAINS="${DOMAINS}"
PROPAGATION="10"
CERT_DIR="/boot/config/letsencrypt"
SCHEDULE="daily"
RESTART_NGINX="yes"
STAGING="no"
RUN_AT_BOOT="no"
EOF
  say "    写入样例配置 $CFG_DIR/unraid-certbot.cfg"
fi

if [ ! -s "$CFG_DIR/cloudflare.ini" ]; then
  cat > "$CFG_DIR/cloudflare.ini" <<'EOF'
# 本地调试用的假 Token，不会被任何真实服务使用
dns_cloudflare_api_token = dev0000000000000000000000000000000000
EOF
  chmod 600 "$CFG_DIR/cloudflare.ini"
  say "    写入假 Cloudflare 凭据 $CFG_DIR/cloudflare.ini（权限 600）"
fi

# ---------------------------------------------------------------------------
# 样例证书
#
# 直接复用 dev/bin/docker 这个假 certbot，产物布局与真实 certbot 一致；
# 再把 fullchain + privkey 合并成 Unraid 认的 bundle。
# ---------------------------------------------------------------------------

if [ ! -s "$BUNDLE" ] || [ ! -s "$LETSENCRYPT_DIR/live/$PRIMARY/fullchain.pem" ]; then
  say "==> 用假 docker 生成样例证书（$CERT_DAYS 天有效期）"
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
    $(for d in ${DOMAINS//,/ }; do printf -- '-d %s ' "$d"; done) > "$stub_log" 2>&1
  stub_rc=$?
  [ "$QUIET" = "yes" ] || cat "$stub_log"
  [ "$stub_rc" -eq 0 ] || cat "$stub_log" >&2
  rm -f "$stub_log"

  live="$LETSENCRYPT_DIR/live/$PRIMARY"
  if [ -s "$live/fullchain.pem" ] && [ -s "$live/privkey.pem" ]; then
    cat "$live/fullchain.pem" "$live/privkey.pem" > "$BUNDLE"
    chmod 600 "$BUNDLE"
    say "    写入 $BUNDLE"
  else
    echo "警告：样例证书生成失败，状态页会显示「尚未签发」" >&2
  fi
fi

# ---------------------------------------------------------------------------
# 样例历史与日志（仅首次生成，之后由 renew.sh 自己追加）
# ---------------------------------------------------------------------------

ts() { date -r "$1" '+%Y-%m-%d %H:%M:%S' 2>/dev/null || date -d "@$1" '+%Y-%m-%d %H:%M:%S'; }
NOW="$(date +%s)"

if [ ! -f "$CFG_DIR/history.tsv" ]; then
  {
    printf '%s\t%s\t%s\t%s\t%s\n' "$(ts $((NOW - 86400 * 6)))" cron   "成功" "$DOMAINS" "证书未变化，无需更新"
    printf '%s\t%s\t%s\t%s\t%s\n' "$(ts $((NOW - 86400 * 3)))" manual "失败" "$DOMAINS" "certbot 执行失败 (退出码 1)，详见日志"
    printf '%s\t%s\t%s\t%s\t%s\n' "$(ts $((NOW - 86400 * 3 + 600)))" manual "成功" "$DOMAINS" "证书已更新，到期时间 $(LC_ALL=C date -v+89d '+%b %d %H:%M:%S %Y GMT' 2>/dev/null || LC_ALL=C date -d '+89 days' '+%b %d %H:%M:%S %Y GMT')"
  } > "$CFG_DIR/history.tsv"
  say "    写入样例续期历史 $CFG_DIR/history.tsv"
fi

if [ ! -f "$CFG_DIR/certbot.log" ]; then
  {
    printf '[%s] ======== 开始续期 (触发: manual, staging=no) ========\n' "$(ts $((NOW - 86400 * 3 + 600)))"
    printf '[%s] 📜 请求证书：%s\n' "$(ts $((NOW - 86400 * 3 + 600)))" "${DOMAINS//,/ }"
    printf '[%s] 假 docker：模拟 certbot 运行（不联网、不启动容器）\n' "$(ts $((NOW - 86400 * 3 + 600)))"
    printf '[%s] ✅ 新证书已写入 %s\n' "$(ts $((NOW - 86400 * 3 + 601)))" "$BUNDLE"
    printf '[%s] 🔁 重启 Unraid Web 管理服务 (nginx)...\n' "$(ts $((NOW - 86400 * 3 + 601)))"
    printf '[%s] ✅ nginx 已重启\n' "$(ts $((NOW - 86400 * 3 + 601)))"
    printf '[%s] 🎉 完成。证书到期时间：%s\n' "$(ts $((NOW - 86400 * 3 + 601)))" "$(openssl x509 -noout -enddate -in "$BUNDLE" 2>/dev/null | cut -d= -f2)"
  } > "$CFG_DIR/certbot.log"
  say "    写入样例日志 $CFG_DIR/certbot.log"
fi

say ""
say "✅ 沙箱就绪：$DEV_ROOT"
say "   浏览器预览：./dev.sh        （默认 http://127.0.0.1:8080）"
say "   命令行状态：./dev.sh status"
say "   跑一次续期：./dev.sh renew"
