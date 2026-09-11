#!/bin/bash
#
# unraid-certbot - 核心续期脚本
#
# 用 certbot/dns-cloudflare 容器通过 Cloudflare DNS-01 验证签发/续期证书，
# 然后把 fullchain + privkey 合并成 Unraid webGUI 认的 bundle 文件。
#
# 用法与退出码见 --help。
#
# 本地调试：CB_DEV_ROOT 把绝对路径映射到沙箱，CB_DOCKER 换成假 docker，
# 两者由 dev.sh 导出，详见 dev/README.md。
#
set -uo pipefail

PLUGIN="unraid-certbot"
IMAGE="certbot/dns-cloudflare"
STAGING_SERVER="https://acme-staging-v02.api.letsencrypt.org/directory"

# 不设 CB_DEV_ROOT 时就是 Unraid 上的真实路径
DEV_ROOT="${CB_DEV_ROOT:-}"

# 已在沙箱内的路径原样返回，避免重复加前缀
syspath() {
  if [ -z "${1:-}" ]; then
    printf ''
    return 0
  fi
  if [ -z "$DEV_ROOT" ]; then
    printf '%s' "$1"
    return 0
  fi
  local root="${DEV_ROOT%/}"
  case "$1" in
    "$root"|"$root"/*) printf '%s' "$1" ;;
    /*)                printf '%s%s' "$root" "$1" ;;
    *)                 printf '%s/%s' "$root" "$1" ;;
  esac
}

PLUGIN_DIR="$(syspath "/usr/local/emhttp/plugins/${PLUGIN}")"
CFG_DIR="$(syspath "/boot/config/plugins/${PLUGIN}")"
CFG_FILE="${CFG_DIR}/${PLUGIN}.cfg"
DEFAULT_CFG="${PLUGIN_DIR}/default.cfg"
CRED_FILE="${CFG_DIR}/cloudflare.ini"
LOG_FILE="${CFG_DIR}/certbot.log"
HISTORY_FILE="${CFG_DIR}/history.tsv"
LOCK_DIR="$(syspath "/var/run/${PLUGIN}.lock.d")"
SSL_CERTS_DIR="$(syspath "/boot/config/ssl/certs")"
NGINX_RC="$(syspath "/etc/rc.d/rc.nginx")"
DOCKER="${CB_DOCKER:-docker}"

MAX_LOG_BYTES=1048576   # 1 MiB，超过则截断保留后半
MAX_HISTORY_LINES=200

FORCE="no"
QUIET="no"
STAGING_OVERRIDE="no"
TRIGGER="manual"
STATUS_ONLY="no"

usage() {
  cat <<'USAGE'
用法：
  renew.sh [--force] [--quiet] [--staging] [--trigger=NAME] [--status]

  --force        强制续期（certbot --force-renewal），忽略到期时间
  --quiet        只写日志文件，不输出到 stdout（cron 用）
  --staging      本次使用 Let's Encrypt 测试环境（不产生可信证书，仅用于验证流程）
  --trigger=NAME 记录到历史里的触发来源：manual / cron / boot / webgui
  --status       只打印当前状态，不做任何变更
  -h, --help     显示本帮助

退出码：
  0  成功（含"证书未到期/未变化"）
  1  配置错误
  2  certbot 执行失败
  3  已有实例在运行
  4  Docker 不可用
USAGE
}

for arg in "$@"; do
  case "$arg" in
    --force)     FORCE="yes" ;;
    --quiet)     QUIET="yes" ;;
    --staging)   STAGING_OVERRIDE="yes" ;;
    --status)    STATUS_ONLY="yes" ;;
    --trigger=*) TRIGGER="${arg#*=}" ;;
    -h|--help)   usage; exit 0 ;;
    *)           echo "未知参数: $arg" >&2; exit 1 ;;
  esac
done

# ---------------------------------------------------------------------------
# 日志
# ---------------------------------------------------------------------------

mkdir -p "$CFG_DIR" 2>/dev/null

rotate_log() {
  [ -f "$LOG_FILE" ] || return 0
  local size
  size=$(stat -c %s "$LOG_FILE" 2>/dev/null || echo 0)
  if [ "$size" -gt "$MAX_LOG_BYTES" ]; then
    tail -c $((MAX_LOG_BYTES / 2)) "$LOG_FILE" > "${LOG_FILE}.tmp" 2>/dev/null \
      && mv "${LOG_FILE}.tmp" "$LOG_FILE"
    printf '[%s] --- 日志超过 %d 字节，已截断 ---\n' \
      "$(date '+%Y-%m-%d %H:%M:%S')" "$MAX_LOG_BYTES" >> "$LOG_FILE"
  fi
}

log() {
  local msg
  msg="[$(date '+%Y-%m-%d %H:%M:%S')] $*"
  printf '%s\n' "$msg" >> "$LOG_FILE" 2>/dev/null
  [ "$QUIET" = "yes" ] || printf '%s\n' "$msg"
}

record_history() {
  # $1=结果 $2=域名列表 $3=说明
  local result="$1" domains="$2" message="$3"
  message=$(printf '%s' "$message" | tr '\t\n' '  ')
  mkdir -p "$CFG_DIR" 2>/dev/null
  printf '%s\t%s\t%s\t%s\t%s\n' \
    "$(date '+%Y-%m-%d %H:%M:%S')" "$TRIGGER" "$result" "$domains" "$message" \
    >> "$HISTORY_FILE" 2>/dev/null

  local lines
  lines=$(wc -l < "$HISTORY_FILE" 2>/dev/null || echo 0)
  if [ "$lines" -gt "$MAX_HISTORY_LINES" ]; then
    tail -n "$MAX_HISTORY_LINES" "$HISTORY_FILE" > "${HISTORY_FILE}.tmp" 2>/dev/null \
      && mv "${HISTORY_FILE}.tmp" "$HISTORY_FILE"
  fi
}

fail() {
  # $1=退出码 $2=消息
  log "❌ $2"
  record_history "失败" "${DOMAINS:-}" "$2"
  exit "$1"
}

# ---------------------------------------------------------------------------
# 读取配置
#
# default.cfg 先读，用户 cfg 后读覆盖之。这里刻意不用 source，
# 因为配置文件在 flash 上、可被手工编辑，source 会执行其中的 $(...) 。
# ---------------------------------------------------------------------------

load_cfg() {
  local file line key val
  for file in "$DEFAULT_CFG" "$CFG_FILE"; do
    [ -f "$file" ] || continue
    while IFS= read -r line || [ -n "$line" ]; do
      line="${line%%#*}"
      case "$line" in
        *"="*) ;;
        *) continue ;;
      esac
      key="${line%%=*}"
      val="${line#*=}"
      key=$(printf '%s' "$key" | tr -d '[:space:]')
      val=$(printf '%s' "$val" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')
      # 去掉成对的引号
      case "$val" in
        \"*\") val="${val:1:${#val}-2}" ;;
        \'*\') val="${val:1:${#val}-2}" ;;
      esac
      # key 经过白名单校验后才赋给变量，避免注入
      case "$key" in
        *[!A-Za-z0-9_]*) continue ;;
        [0-9]*) continue ;;
        "") continue ;;
      esac
      # 不覆盖会影响脚本自身运行的环境变量
      case "$key" in
        IFS|PATH|HOME|SHELL|ENV|BASH_ENV|BASHOPTS|SHELLOPTS|PS4|LD_PRELOAD|LD_LIBRARY_PATH|TMPDIR|PWD|OLDPWD)
          continue ;;
      esac
      printf -v "$key" '%s' "$val"
    done < "$file"
  done
}

load_cfg

: "${ACME_EMAIL:=}"
: "${UNRAID_HOSTNAME:=}"
: "${DOMAINS:=}"
: "${PROPAGATION:=60}"
: "${CERT_DIR:=/boot/config/letsencrypt}"
: "${SCHEDULE:=daily}"
: "${RESTART_NGINX:=yes}"
: "${STAGING:=no}"

# 配置里的证书目录同样要套沙箱前缀
CERT_DIR="$(syspath "$CERT_DIR")"

[ "$STAGING_OVERRIDE" = "yes" ] && STAGING="yes"

# ---------------------------------------------------------------------------
# 状态模式：只读输出，供排障用
# ---------------------------------------------------------------------------

if [ "$STATUS_ONLY" = "yes" ]; then
  primary=$(printf '%s' "$DOMAINS" | tr ',' '\n' | tr -s '[:space:]' '\n' | sed '/^$/d' | head -n1)
  echo "运行模式    : $([ -n "$DEV_ROOT" ] && echo "本地沙箱 ${DEV_ROOT}" || echo 'Unraid 生产环境')"
  echo "插件目录    : ${PLUGIN_DIR}"
  echo "配置目录    : ${CFG_DIR}"
  echo "配置文件    : ${CFG_FILE} $([ -f "$CFG_FILE" ] && echo '(存在)' || echo '(缺失，使用默认值)')"
  echo "凭据文件    : ${CRED_FILE} $([ -s "$CRED_FILE" ] && echo '(已配置)' || echo '(缺失)')"
  echo "邮箱        : ${ACME_EMAIL:-<未设置>}"
  echo "主机名      : ${UNRAID_HOSTNAME:-<未设置>}"
  echo "域名        : ${DOMAINS:-<未设置>}"
  echo "主域名      : ${primary:-<未设置>}"
  echo "证书目录    : ${CERT_DIR}"
  echo "传播等待    : ${PROPAGATION}s"
  echo "测试环境    : ${STAGING}"
  echo "重启 nginx  : ${RESTART_NGINX}"
  if [ -x "$DOCKER" ] || command -v "$DOCKER" >/dev/null 2>&1; then
    echo "docker 命令 : ${DOCKER}"
  else
    echo "docker 命令 : ${DOCKER} (不可用)"
  fi
  bundle="${SSL_CERTS_DIR}/${UNRAID_HOSTNAME}_unraid_bundle.pem"
  echo "bundle 文件 : ${bundle} $([ -f "$bundle" ] && echo '(存在)' || echo '(缺失)')"
  exit 0
fi

# ---------------------------------------------------------------------------
# 前置检查
# ---------------------------------------------------------------------------

rotate_log
log "======== 开始续期 (触发: ${TRIGGER}${FORCE:+, force=${FORCE}}${STAGING:+, staging=${STAGING}}) ========"

if [ -z "$ACME_EMAIL" ]; then
  fail 1 "未配置邮箱，请在 Settings → Unraid Certbot 中填写"
fi
if [ -z "$UNRAID_HOSTNAME" ]; then
  fail 1 "未配置 Unraid 主机名，请在 Settings → Unraid Certbot 中填写"
fi
if [ -z "$DOMAINS" ]; then
  fail 1 "未配置域名，请在 Settings → Unraid Certbot 中填写"
fi
if [ ! -s "$CRED_FILE" ]; then
  fail 1 "缺少 Cloudflare 凭据文件 ${CRED_FILE}，请在设置页填写 API Token"
fi
case "$CERT_DIR" in
  /*) ;;
  *)  fail 1 "证书目录必须是绝对路径，当前为：${CERT_DIR}" ;;
esac

# 域名解析：逗号/空白/分号分隔，去重保序
#
# 注意 while read 循环读不到「最后一行没有换行符」的内容，
# 所以这里显式补一个换行，否则会静默丢掉最后一个域名。
DOMAIN_ARRAY=()
while IFS= read -r d; do
  [ -n "$d" ] || continue
  if [[ " ${DOMAIN_ARRAY[*]-} " == *" $d "* ]]; then
    continue
  fi
  DOMAIN_ARRAY+=("$d")
done < <(printf '%s\n' "$DOMAINS" | tr ',;' '\n' | tr -s '[:space:]' '\n' | sed '/^$/d')

if [ "${#DOMAIN_ARRAY[@]}" -eq 0 ]; then
  fail 1 "域名列表为空"
fi
PRIMARY_DOMAIN="${DOMAIN_ARRAY[0]}"

# 锁：mkdir 是原子的，无需依赖 flock；同时能识别陈旧的锁
if ! mkdir "$LOCK_DIR" 2>/dev/null; then
  if [ -f "${LOCK_DIR}/pid" ] && kill -0 "$(cat "${LOCK_DIR}/pid" 2>/dev/null)" 2>/dev/null; then
    log "⏭️  已有一次续期正在进行 (PID $(cat "${LOCK_DIR}/pid"))，本次跳过"
    exit 3
  fi
  log "⚠️  清理陈旧的锁目录 ${LOCK_DIR}"
  rm -rf "$LOCK_DIR"
  mkdir "$LOCK_DIR" 2>/dev/null || fail 3 "无法获取运行锁"
fi
printf '%s' "$$" > "${LOCK_DIR}/pid"
trap 'rm -rf "$LOCK_DIR"' EXIT

# Docker 可用性 —— 给出明确原因，而不是让 docker run 抛晦涩错误
if ! command -v "$DOCKER" >/dev/null 2>&1; then
  fail 4 "找不到 docker 命令（当前使用：${DOCKER}）"
fi
if ! "$DOCKER" info >/dev/null 2>&1; then
  fail 4 "Docker 服务未运行（通常是阵列未启动）。请启动阵列后重试。"
fi

if ! "$DOCKER" image inspect "$IMAGE" >/dev/null 2>&1; then
  log "⬇️  本地没有 ${IMAGE} 镜像，正在拉取..."
  if ! "$DOCKER" pull "$IMAGE"; then
    fail 2 "拉取镜像 ${IMAGE} 失败，请检查网络或先在终端手动 docker pull"
  fi
fi

mkdir -p "$CERT_DIR" || fail 1 "无法创建证书目录 ${CERT_DIR}"
chmod 700 "$CERT_DIR" 2>/dev/null

# ---------------------------------------------------------------------------
# 运行 certbot
# ---------------------------------------------------------------------------

CERTBOT_ARGS=(
  certonly
  --dns-cloudflare
  --dns-cloudflare-credentials /cloudflare.ini
  --dns-cloudflare-propagation-seconds "$PROPAGATION"
  --non-interactive
  --agree-tos
  --email "$ACME_EMAIL"
  --config-dir /etc/letsencrypt
  --work-dir /etc/letsencrypt/work
  --logs-dir /etc/letsencrypt/logs
  # 固定证书名，否则通配符证书（*.example.com）的目录名会随 certbot 版本变化，
  # 导致下面拼出的 live 路径找不到文件
  --cert-name "$PRIMARY_DOMAIN"
)

if [ "$STAGING" = "yes" ]; then
  CERTBOT_ARGS+=(--server "$STAGING_SERVER")
  log "⚠️  使用 Let's Encrypt 测试环境 —— 签出的证书不被浏览器信任"
fi
if [ "$FORCE" = "yes" ]; then
  CERTBOT_ARGS+=(--force-renewal)
fi

for d in "${DOMAIN_ARRAY[@]}"; do
  CERTBOT_ARGS+=(-d "$d")
done

log "📜 请求证书：${DOMAIN_ARRAY[*]}"

{
  printf '\n[%s] $ %s run --rm %s certbot %s\n' \
    "$(date '+%Y-%m-%d %H:%M:%S')" "$DOCKER" "$IMAGE" "${CERTBOT_ARGS[*]}"
} >> "$LOG_FILE"

"$DOCKER" run --rm \
  -v "${CERT_DIR}:/etc/letsencrypt" \
  -v "${CRED_FILE}:/cloudflare.ini:ro" \
  "$IMAGE" "${CERTBOT_ARGS[@]}" 2>&1 | tee -a "$LOG_FILE"

CERTBOT_RC="${PIPESTATUS[0]}"

if [ "$CERTBOT_RC" -ne 0 ]; then
  fail 2 "certbot 执行失败 (退出码 ${CERTBOT_RC})，详见日志"
fi

# ---------------------------------------------------------------------------
# 合并证书
# ---------------------------------------------------------------------------

LIVE_DIR="${CERT_DIR}/live/${PRIMARY_DOMAIN}"
CERT_FILE="${LIVE_DIR}/fullchain.pem"
KEY_FILE="${LIVE_DIR}/privkey.pem"
OUTPUT_FILE="${SSL_CERTS_DIR}/${UNRAID_HOSTNAME}_unraid_bundle.pem"

if [ ! -s "$CERT_FILE" ] || [ ! -s "$KEY_FILE" ]; then
  fail 2 "证书文件未生成：${CERT_FILE} 或 ${KEY_FILE} 不存在"
fi

mkdir -p "$SSL_CERTS_DIR" || fail 1 "无法创建 ${SSL_CERTS_DIR}"

TEMP_BUNDLE=$(mktemp) || fail 1 "无法创建临时文件"
cat "$CERT_FILE" "$KEY_FILE" > "$TEMP_BUNDLE" || { rm -f "$TEMP_BUNDLE"; fail 1 "合并证书失败"; }
chmod 600 "$TEMP_BUNDLE"

# 幂等：内容没变就不动 nginx
if [ -f "$OUTPUT_FILE" ] && cmp -s "$TEMP_BUNDLE" "$OUTPUT_FILE"; then
  rm -f "$TEMP_BUNDLE"
  log "ℹ️  证书内容未变化，跳过写入与重启"
  record_history "成功" "${DOMAIN_ARRAY[*]}" "证书未变化，无需更新"
  exit 0
fi

if [ -f "$OUTPUT_FILE" ]; then
  BACKUP_FILE="${OUTPUT_FILE}.$(date +%Y%m%d)"
  log "🌀 备份旧证书到 ${BACKUP_FILE}"
  cp "$OUTPUT_FILE" "$BACKUP_FILE" && chmod 600 "$BACKUP_FILE"
fi

mv "$TEMP_BUNDLE" "$OUTPUT_FILE" || { rm -f "$TEMP_BUNDLE"; fail 1 "写入 ${OUTPUT_FILE} 失败"; }
chmod 600 "$OUTPUT_FILE"
log "✅ 新证书已写入 ${OUTPUT_FILE}"

# ---------------------------------------------------------------------------
# 重启 nginx 让 webGUI 生效
# ---------------------------------------------------------------------------

if [ "$RESTART_NGINX" = "yes" ]; then
  log "🔁 重启 Unraid Web 管理服务 (nginx)..."
  if [ -x "$NGINX_RC" ] && "$NGINX_RC" restart >/dev/null 2>&1; then
    log "✅ nginx 已重启"
  else
    log "⚠️  nginx 重启返回非零，请检查 webGUI 是否正常"
  fi
else
  log "ℹ️  按配置跳过 nginx 重启（新证书将在下次重启 web 服务后生效）"
fi

EXPIRY=$(openssl x509 -noout -enddate -in "$OUTPUT_FILE" 2>/dev/null | cut -d= -f2)
log "🎉 完成。证书到期时间：${EXPIRY:-未知}"
record_history "成功" "${DOMAIN_ARRAY[*]}" "证书已更新，到期时间 ${EXPIRY:-未知}"
exit 0
