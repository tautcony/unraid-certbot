#!/bin/bash
#
# unraid-certbot - 证书续期入口
#
# 使用 certbot/dns-cloudflare 容器完成 Cloudflare DNS-01 验证，
# 再将证书链和私钥合并为 Unraid webGUI 使用的 bundle 文件。
#
# 用法与退出码见 --help。
#
# 本地调试变量 CB_DEV_ROOT 和 CB_DOCKER 由 dev.sh 提供，详见 dev/README.md。
#
set -uo pipefail

PLUGIN="unraid-certbot"
IMAGE="certbot/dns-cloudflare"
STAGING_SERVER="https://acme-staging-v02.api.letsencrypt.org/directory"

# 不设 CB_DEV_ROOT 时就是 Unraid 上的真实路径
DEV_ROOT="${CB_DEV_ROOT:-}"

# 将 Unraid 绝对路径映射到本地调试根目录；已映射路径保持不变。
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
SCHEMA_FILE="${PLUGIN_DIR}/config-keys.txt"
CRED_FILE="${CFG_DIR}/cloudflare.ini"
LOG_FILE="${CFG_DIR}/certbot.log"
HISTORY_FILE="${CFG_DIR}/history.tsv"
LOCK_FILE="$(syspath "/var/run/${PLUGIN}.lock")"
SSL_CERTS_DIR="$(syspath "/boot/config/ssl/certs")"
NGINX_RC="$(syspath "/etc/rc.d/rc.nginx")"
DOCKER="${CB_DOCKER:-docker}"

# 日志和历史记录均限制长度，避免长期运行占满 flash。
MAX_LOG_BYTES=1048576
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
    printf '[%s] --- %s ---\n' \
      "$(date '+%Y-%m-%d %H:%M:%S')" \
      "$(cb_msg "日志超过 ${MAX_LOG_BYTES} 字节，已截断" "Log exceeded ${MAX_LOG_BYTES} bytes and was truncated")" >> "$LOG_FILE"
  fi
}

log() {
  local msg
  msg="[$(date '+%Y-%m-%d %H:%M:%S')] $*"
  printf '%s\n' "$msg" >> "$LOG_FILE" 2>/dev/null
  [ "$QUIET" = "yes" ] || printf '%s\n' "$msg"
}

# GNU 和 BSD stat 的参数不同，兼容 Unraid 和 macOS 调试环境。
cb_own() {
  stat -c '%U:%G %a' "$1" 2>/dev/null || stat -f '%Su:%Sg %Lp' "$1" 2>/dev/null || printf '?'
}
cb_fstype() {
  # Linux 优先读取 /proc/mounts；macOS 回退到 df。
  local t
  t=$(awk -v d="$1" '$2==d {print $3; exit}' /proc/mounts 2>/dev/null)
  [ -n "$t" ] || t=$(df -T "$1" 2>/dev/null | awk 'NR==2 {print $2}')
  printf '%s' "${t:-?}"
}

# 检查证书目录的写权限和符号链接能力；certbot 会在该目录下维护 archive/live。
audit_cert_dir() {
  local probe="${CERT_DIR}/.cb-probe-$$"
  local fstype mountopts
  echo "$(cb_msg '—— 证书目录体检 ——' '—— Certificate directory check ——')"
  echo "$(cb_msg '目录        ' 'Directory   '): ${CERT_DIR}"
  if [ ! -d "$CERT_DIR" ]; then
    echo "$(cb_msg '状态        : 不存在（首次续期时会自动创建）' 'Status      : Missing (will be created at first renewal)')"
    return 0
  fi
  echo "$(cb_msg '属主/权限   ' 'Owner/mode  '): $(cb_own "$CERT_DIR")"

  fstype=$(cb_fstype "$CERT_DIR")
  mountopts=$(awk -v d="$CERT_DIR" '$2==d {print $4}' /proc/mounts 2>/dev/null | head -n1)
  if [ -n "$mountopts" ]; then
    echo "$(cb_msg '文件系统    ' 'Filesystem  '): ${fstype}  $(cb_msg '挂载选项' 'mount options'): ${mountopts}"
  else
    echo "$(cb_msg '文件系统    ' 'Filesystem  '): ${fstype}"
  fi

  if [ -w "$CERT_DIR" ]; then
    echo "$(cb_msg '可写(本机)  : 是' 'Writable    : Yes')"
  else
    echo "$(cb_msg '可写(本机)  : 否（续期会失败）' 'Writable    : No (renewal will fail)')"
  fi

  if ln -s "$CERT_DIR" "$probe" 2>/dev/null; then
    rm -f "$probe"
    echo "$(cb_msg '符号链接    : 支持' 'Symlinks    : Supported')"
  else
    rm -f "$probe"
    echo "$(cb_msg '符号链接    : 不支持（续期会失败）' 'Symlinks    : Unsupported (renewal will fail)')"
  fi

  local archive="${CERT_DIR}/archive" live="${CERT_DIR}/live"
  [ -d "$archive" ] && echo "$(cb_msg 'archive 属主' 'archive owner'): $(cb_own "$archive")"
  [ -d "$live" ]    && echo "$(cb_msg 'live 属主   ' 'live owner    '): $(cb_own "$live")"
  case "${mountopts}" in
    ro|ro,*|*,ro|*,ro,*)
      echo "$(cb_msg '注意        : 只读挂载（续期会失败）' 'Warning     : Read-only mount (renewal will fail)')" ;;
  esac
}

record_history() {
  # 参数依次为结果、域名列表和说明。
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

# 从本次运行日志中提取最后一条有用的 certbot 错误，供历史记录展示。
certbot_reason() {
  [ -f "$LOG_FILE" ] || return 0
  tail -n 25 "$LOG_FILE" 2>/dev/null \
    | grep -v '^\[' \
    | grep -v '❌' \
    | grep -v '^[[:space:]]*$' \
    | grep -Ei 'Error|error occurred|PermissionError|Traceback|Failed|Unauthorized|Invalid|rate ?limit|challenge|Connection' \
    | tail -n 1 \
    | cut -c1-180
}

fail() {
  # 参数依次为退出码和用户可读消息。
  log "❌ $2"
  record_history "failed" "${DOMAINS:-}" "$2"
  exit "$1"
}

# ---------------------------------------------------------------------------
# 读取配置。默认配置先加载，用户配置随后覆盖；不使用 source，避免执行配置内容。
# ---------------------------------------------------------------------------

load_cfg() {
  local file key val
  for file in "$DEFAULT_CFG" "$CFG_FILE"; do
    [ -f "$file" ] || continue
    if ! php -r 'exit(is_array(@parse_ini_file($argv[1], false, INI_SCANNER_RAW)) ? 0 : 1);' "$file"; then
      echo "配置文件格式不正确：$file" >&2
      return 1
    fi
    while IFS= read -r -d '' key && IFS= read -r -d '' val; do
      case "$key" in
        ''|*[!A-Za-z0-9_]*|[0-9]*) continue ;;
      esac
      grep -qxF -- "$key" "$SCHEMA_FILE" 2>/dev/null || continue
      printf -v "$key" '%s' "$val"
    done < <(php -r '
      $cfg = parse_ini_file($argv[1], false, INI_SCANNER_RAW);
      foreach ($cfg as $key => $value) {
          if (is_string($value)) echo $key, "\0", $value, "\0";
      }
    ' "$file")
  done
}

load_cfg || exit 1

LOG_LANG="${UI_LANGUAGE:-auto}"
if [ "$LOG_LANG" = auto ]; then
  if [ -n "$DEV_ROOT" ] && [ -n "${CB_DEV_LOCALE:-}" ]; then
    LOG_LANG="$CB_DEV_LOCALE"
  else
    LOG_LANG=$(php -r '
      $cfg = @parse_ini_file($argv[1], false, INI_SCANNER_RAW);
      echo is_array($cfg) ? ($cfg["locale"] ?? "zh_CN") : "zh_CN";
    ' "$(syspath /boot/config/plugins/dynamix/dynamix.cfg)" 2>/dev/null)
  fi
fi
cb_msg() {
  if [ "$LOG_LANG" = en_US ]; then printf '%s' "$2"; else printf '%s' "$1"; fi
}

: "${ACME_EMAIL:=}"
: "${UNRAID_HOSTNAME:=}"
: "${DOMAINS:=}"
: "${PROPAGATION:=60}"
: "${CERT_DIR:=/mnt/user/appdata/letsencrypt}"
: "${SCHEDULE:=daily}"
: "${RESTART_NGINX:=yes}"
: "${STAGING:=no}"

valid_cert_dir() {
  local raw="$1" rest part path
  [[ "$raw" == /mnt/user/appdata/* ]] || return 1
  rest="${raw#/mnt/user/appdata/}"
  [ -n "$rest" ] && [[ "$rest" != *'//'* ]] && [[ "$rest" != */ ]] || return 1
  IFS='/' read -r -a parts <<< "$rest"
  for part in "${parts[@]}"; do
    [[ "$part" =~ ^[A-Za-z0-9._-]+$ ]] && [ "$part" != '.' ] && [ "$part" != '..' ] || return 1
  done
  path="$(syspath /mnt/user/appdata)"
  [ ! -L "$path" ] || return 1
  for part in "${parts[@]}"; do
    path="$path/$part"
    [ ! -L "$path" ] || return 1
  done
  if [ -d "$path" ]; then
    [ "$(cd "$path" && pwd -P)" = "$path" ] || return 1
    if [ -f /proc/mounts ] && awk -v target="$path" '$2 == target {found=1} END {exit !found}' /proc/mounts; then
      return 1
    fi
    [ "$(stat -c %d "$path" 2>/dev/null || stat -f %d "$path")" = "$(stat -c %d "$(dirname "$path")" 2>/dev/null || stat -f %d "$(dirname "$path")")" ] || return 1
  fi
}

if ! valid_cert_dir "$CERT_DIR"; then
  echo "$(cb_msg "证书目录必须位于 /mnt/user/appdata 的普通子目录：$CERT_DIR" "Certificate directory must be a regular subdirectory of /mnt/user/appdata: $CERT_DIR")" >&2
  exit 1
fi
CERT_DIR="$(syspath "$CERT_DIR")"

[ "$STAGING_OVERRIDE" = "yes" ] && STAGING="yes"

# ---------------------------------------------------------------------------
# 状态模式：只读输出，供排障使用。
# ---------------------------------------------------------------------------

if [ "$STATUS_ONLY" = "yes" ]; then
  primary=$(printf '%s' "$DOMAINS" | tr ',' '\n' | tr -s '[:space:]' '\n' | sed '/^$/d' | head -n1)
  echo "$(cb_msg '运行模式    ' 'Mode        '): $([ -n "$DEV_ROOT" ] && cb_msg "本地沙箱 ${DEV_ROOT}" "Local sandbox ${DEV_ROOT}" || cb_msg 'Unraid 生产环境' 'Unraid production')"
  echo "$(cb_msg '插件目录    ' 'Plugin dir  '): ${PLUGIN_DIR}"
  echo "$(cb_msg '配置目录    ' 'Config dir  '): ${CFG_DIR}"
  echo "$(cb_msg '配置文件    ' 'Config file '): ${CFG_FILE} $([ -f "$CFG_FILE" ] && cb_msg '(存在)' '(present)' || cb_msg '(缺失，使用默认值)' '(missing; defaults used)')"
  echo "$(cb_msg '凭据文件    ' 'Credentials '): ${CRED_FILE} $([ -s "$CRED_FILE" ] && cb_msg '(已配置)' '(configured)' || cb_msg '(缺失)' '(missing)')"
  echo "$(cb_msg '邮箱        ' 'Email       '): ${ACME_EMAIL:-$(cb_msg '<未设置>' '<not set>')}"
  echo "$(cb_msg '主机名      ' 'Hostname    '): ${UNRAID_HOSTNAME:-$(cb_msg '<未设置>' '<not set>')}"
  echo "$(cb_msg '域名        ' 'Domains     '): ${DOMAINS:-$(cb_msg '<未设置>' '<not set>')}"
  echo "$(cb_msg '主域名      ' 'Primary     '): ${primary:-$(cb_msg '<未设置>' '<not set>')}"
  echo "$(cb_msg '证书目录    ' 'Cert dir    '): ${CERT_DIR}"
  echo "$(cb_msg '传播等待    ' 'Propagation '): ${PROPAGATION}s"
  echo "$(cb_msg '测试环境    ' 'Staging     '): ${STAGING}"
  echo "$(cb_msg '重启 nginx  ' 'Restart nginx'): ${RESTART_NGINX}"
  if [ -x "$DOCKER" ] || command -v "$DOCKER" >/dev/null 2>&1; then
    echo "$(cb_msg 'docker 命令 ' 'Docker cmd  '): ${DOCKER}"
  else
    echo "$(cb_msg 'docker 命令 ' 'Docker cmd  '): ${DOCKER} $(cb_msg '(不可用)' '(unavailable)')"
  fi
  bundle="${SSL_CERTS_DIR}/${UNRAID_HOSTNAME}_unraid_bundle.pem"
  echo "$(cb_msg 'bundle 文件 ' 'Bundle file '): ${bundle} $([ -f "$bundle" ] && cb_msg '(存在)' '(present)' || cb_msg '(缺失)' '(missing)')"
  audit_cert_dir
  exit 0
fi

# ---------------------------------------------------------------------------
# 前置检查：配置、锁、Docker 和证书目录。
# ---------------------------------------------------------------------------

rotate_log
run_flags=""
[ "$FORCE" = "yes" ] && run_flags="$run_flags, force=yes"
[ "$STAGING" = "yes" ] && run_flags="$run_flags, staging=yes"
log "$(cb_msg "======== 开始续期 (触发: ${TRIGGER}${run_flags}) ========" "======== Renewal started (trigger: ${TRIGGER}${run_flags}) ========")"

if [ -z "$ACME_EMAIL" ]; then
  fail 1 "$(cb_msg '未配置邮箱' 'Email is not configured')"
fi
if [ -z "$UNRAID_HOSTNAME" ]; then
  fail 1 "$(cb_msg '未配置主机名' 'Hostname is not configured')"
fi
if ! [[ "$UNRAID_HOSTNAME" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,62}$ ]]; then
  fail 1 "$(cb_msg '主机名格式不正确' 'Invalid hostname')"
fi
if [ -z "$DOMAINS" ]; then
  fail 1 "$(cb_msg '未配置域名' 'Domains are not configured')"
fi
if ! [[ "$PROPAGATION" =~ ^[0-9]+$ ]] || [ "$PROPAGATION" -lt 10 ] || [ "$PROPAGATION" -gt 900 ]; then
  fail 1 "$(cb_msg 'DNS 传播等待时间不正确' 'Invalid DNS propagation wait')"
fi
if [ ! -s "$CRED_FILE" ]; then
  fail 1 "$(cb_msg '未配置 Cloudflare API Token' 'Cloudflare API Token is not configured')"
fi
case "$CERT_DIR" in
  /*) ;;
  *)  fail 1 "$(cb_msg "证书目录必须是绝对路径，当前为：${CERT_DIR}" "Certificate directory must be an absolute path: ${CERT_DIR}")" ;;
esac

# 域名支持逗号、分号或空白分隔，并按首次出现顺序去重。
DOMAIN_ARRAY=()
while IFS= read -r d; do
  [ -n "$d" ] || continue
  check_domain="${d#\*.}"
  if ! [[ "$check_domain" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ ]] \
    || [[ "$check_domain" == *..* ]] || [[ "$check_domain" != *.* ]]; then
    fail 1 "$(cb_msg "域名格式不正确：$d" "Invalid domain: $d")"
  fi
  if [[ " ${DOMAIN_ARRAY[*]-} " == *" $d "* ]]; then
    continue
  fi
  DOMAIN_ARRAY+=("$d")
done < <(printf '%s\n' "$DOMAINS" | tr ',;' '\n' | tr -s '[:space:]' '\n' | sed '/^$/d')

if [ "${#DOMAIN_ARRAY[@]}" -eq 0 ]; then
  fail 1 "$(cb_msg '域名列表为空' 'Domain list is empty')"
fi
PRIMARY_DOMAIN="${DOMAIN_ARRAY[0]}"

if ! command -v flock >/dev/null 2>&1; then
  fail 3 "$(cb_msg '缺少 flock 命令' 'flock command is missing')"
fi
exec 9>"$LOCK_FILE" || fail 3 "$(cb_msg '无法打开运行锁' 'Cannot open renewal lock')"
if ! flock -n 9; then
  log "$(cb_msg '已有续期正在进行，跳过' 'Another renewal is running; skipped')"
  exit 3
fi

# 先检查 Docker 服务，再拉取所需镜像，尽早报告可操作的错误。
if ! command -v "$DOCKER" >/dev/null 2>&1; then
  fail 4 "$(cb_msg '未找到 docker 命令' 'Docker command not found')"
fi
if ! "$DOCKER" info >/dev/null 2>&1; then
  fail 4 "$(cb_msg 'Docker 服务未运行（通常是阵列未启动）' 'Docker is unavailable (the array may be stopped)')"
fi

if ! "$DOCKER" image inspect "$IMAGE" >/dev/null 2>&1; then
  log "$(cb_msg "正在拉取镜像 ${IMAGE}" "Pulling image ${IMAGE}")"
  if ! "$DOCKER" pull "$IMAGE"; then
    fail 2 "$(cb_msg '拉取镜像失败，请检查网络' 'Image pull failed; check the network')"
  fi
fi

mkdir -p -m 700 "$CERT_DIR" || fail 1 "$(cb_msg "无法创建证书目录 ${CERT_DIR}" "Cannot create certificate directory ${CERT_DIR}")"

# certbot 需要在 /etc/letsencrypt 下创建 archive/live 符号链接。
case "$(echo "$(cb_fstype "$CERT_DIR")" | tr 'A-Z' 'a-z')" in
  vfat|msdos|exfat|fat|fat32)
    fail 2 "$(cb_msg "证书目录位于 $(cb_fstype "$CERT_DIR") 文件系统，不支持符号链接，请调整路径" "Certificate directory is on $(cb_fstype "$CERT_DIR"), which does not support symbolic links")"
    ;;
esac
if [ ! -w "$CERT_DIR" ]; then
  fail 2 "$(cb_msg '证书目录不可写，请检查权限（root:root 700）' 'Certificate directory is not writable; check permissions (root:root 700)')"
fi

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
  # 固定证书目录名，避免 certbot 版本变化导致后续路径失效。
  --cert-name "$PRIMARY_DOMAIN"
)

if [ "$STAGING" = "yes" ]; then
  CERTBOT_ARGS+=(--server "$STAGING_SERVER")
  log "$(cb_msg "⚠️ 使用 Let's Encrypt 测试环境，签发的证书不受浏览器信任" "Warning: Let's Encrypt staging certificates are not trusted by browsers")"
fi
if [ "$FORCE" = "yes" ]; then
  CERTBOT_ARGS+=(--force-renewal)
fi

for d in "${DOMAIN_ARRAY[@]}"; do
  CERTBOT_ARGS+=(-d "$d")
done

log "$(cb_msg "请求证书：${DOMAIN_ARRAY[*]}" "Requesting certificate: ${DOMAIN_ARRAY[*]}")"

{
  printf '\n[%s] $ %s run --rm %s certbot %s\n' \
    "$(date '+%Y-%m-%d %H:%M:%S')" "$DOCKER" "$IMAGE" "${CERTBOT_ARGS[*]}"
} >> "$LOG_FILE"

"$DOCKER" run --rm \
  --user 0:0 \
  -v "${CERT_DIR}:/etc/letsencrypt" \
  -v "${CRED_FILE}:/cloudflare.ini:ro" \
  "$IMAGE" "${CERTBOT_ARGS[@]}" 2>&1 | tee -a "$LOG_FILE"

CERTBOT_RC="${PIPESTATUS[0]}"

if [ "$CERTBOT_RC" -ne 0 ]; then
  reason="$(certbot_reason)"
  if [ -n "$reason" ]; then
    fail 2 "$(cb_msg "certbot 执行失败 (退出码 ${CERTBOT_RC})：${reason}" "certbot failed (exit ${CERTBOT_RC}): ${reason}")"
  fi
  fail 2 "$(cb_msg "certbot 执行失败 (退出码 ${CERTBOT_RC})，详见运行日志" "certbot failed (exit ${CERTBOT_RC}); see the run log")"
fi

# ---------------------------------------------------------------------------
# 合并证书并按内容幂等更新 bundle
# ---------------------------------------------------------------------------

LIVE_DIR="${CERT_DIR}/live/${PRIMARY_DOMAIN}"
CERT_FILE="${LIVE_DIR}/fullchain.pem"
KEY_FILE="${LIVE_DIR}/privkey.pem"
OUTPUT_FILE="${SSL_CERTS_DIR}/${UNRAID_HOSTNAME}_unraid_bundle.pem"

if [ ! -s "$CERT_FILE" ] || [ ! -s "$KEY_FILE" ]; then
  fail 2 "$(cb_msg "证书文件未生成：${CERT_FILE} 或 ${KEY_FILE} 不存在" "Certificate files were not created: ${CERT_FILE} or ${KEY_FILE} is missing")"
fi

mkdir -p "$SSL_CERTS_DIR" || fail 1 "$(cb_msg "无法创建 ${SSL_CERTS_DIR}" "Cannot create ${SSL_CERTS_DIR}")"

TEMP_BUNDLE=$(mktemp "${SSL_CERTS_DIR}/.unraid-certbot-bundle.XXXXXX") || fail 1 "$(cb_msg '无法创建临时文件' 'Cannot create temporary file')"
trap 'rm -f "$TEMP_BUNDLE"' EXIT
cat "$CERT_FILE" "$KEY_FILE" > "$TEMP_BUNDLE" || { rm -f "$TEMP_BUNDLE"; fail 1 "$(cb_msg '合并证书失败' 'Cannot combine certificate and key')"; }
chmod 600 "$TEMP_BUNDLE" || fail 1 "$(cb_msg '无法设置 bundle 权限' 'Cannot set bundle permissions')"
if ! openssl x509 -noout -in "$TEMP_BUNDLE" >/dev/null 2>&1 \
   || ! openssl pkey -noout -in "$KEY_FILE" >/dev/null 2>&1; then
  fail 2 "$(cb_msg '证书或私钥不是有效的 PEM' 'Certificate or private key is not valid PEM')"
fi
cert_pub="$(openssl x509 -pubkey -noout -in "$CERT_FILE" 2>/dev/null | openssl pkey -pubin -outform DER 2>/dev/null | openssl dgst -sha256)"
key_pub="$(openssl pkey -pubout -in "$KEY_FILE" 2>/dev/null | openssl pkey -pubin -outform DER 2>/dev/null | openssl dgst -sha256)"
[ -n "$cert_pub" ] && [ "$cert_pub" = "$key_pub" ] || fail 2 "$(cb_msg '证书与私钥不匹配' 'Certificate and private key do not match')"

PENDING_FILE="${CFG_DIR}/nginx.pending"

# 内容未变化时不写文件，也不重启 nginx。
if [ -f "$OUTPUT_FILE" ] && cmp -s "$TEMP_BUNDLE" "$OUTPUT_FILE"; then
  rm -f "$TEMP_BUNDLE"
  if [ "$RESTART_NGINX" = "yes" ] && [ -f "$PENDING_FILE" ]; then
    log "$(cb_msg '证书内容未变化，重试 nginx 应用' 'Certificate unchanged; retrying nginx restart')"
    if [ -x "$NGINX_RC" ] && "$NGINX_RC" restart >/dev/null 2>&1; then
      rm -f "$PENDING_FILE" || fail 1 "$(cb_msg '无法清除 nginx 待应用状态' 'Cannot clear pending nginx state')"
      record_history "success" "${DOMAIN_ARRAY[*]}" "$(cb_msg '待处理的 nginx 重启已完成' 'Pending nginx restart applied')"
      exit 0
    fi
    fail 2 "$(cb_msg 'nginx 重启仍失败，证书待应用' 'nginx restart still failed; certificate is pending')"
  fi
  log "$(cb_msg '证书内容未变化，跳过写入与重启' 'Certificate unchanged; skipping write and restart')"
  record_history "success" "${DOMAIN_ARRAY[*]}" "$(cb_msg '证书未变化，无需更新' 'Certificate unchanged; no update needed')"
  exit 0
fi

if [ -f "$OUTPUT_FILE" ]; then
  BACKUP_FILE="${OUTPUT_FILE}.$(date +%Y%m%d)"
  [ ! -d "$BACKUP_FILE" ] || fail 1 "$(cb_msg '备份路径是目录，未替换 bundle' 'Backup path is a directory; bundle was not replaced')"
  BACKUP_TMP=$(mktemp "${SSL_CERTS_DIR}/.unraid-certbot-backup.XXXXXX") || fail 1 "$(cb_msg '无法创建备份临时文件' 'Cannot create temporary backup')"
  log "$(cb_msg "备份旧证书到 ${BACKUP_FILE}" "Backing up certificate to ${BACKUP_FILE}")"
  if ! cp "$OUTPUT_FILE" "$BACKUP_TMP" || ! chmod 600 "$BACKUP_TMP" \
    || ! mv "$BACKUP_TMP" "$BACKUP_FILE"; then
    rm -f "$BACKUP_TMP"
    fail 1 "$(cb_msg '备份旧证书失败，未替换 bundle' 'Certificate backup failed; bundle was not replaced')"
  fi
fi

if [ "$RESTART_NGINX" = "yes" ]; then
  printf '%s\n' "$OUTPUT_FILE" > "$PENDING_FILE" || fail 1 "$(cb_msg '无法记录 nginx 待应用状态' 'Cannot record pending nginx state')"
fi
mv "$TEMP_BUNDLE" "$OUTPUT_FILE" || {
  rm -f "$TEMP_BUNDLE"
  [ "$RESTART_NGINX" != "yes" ] || rm -f "$PENDING_FILE"
  fail 1 "$(cb_msg "写入 ${OUTPUT_FILE} 失败" "Cannot write ${OUTPUT_FILE}")"
}
log "$(cb_msg "✅ 新证书已写入 ${OUTPUT_FILE}" "Certificate written to ${OUTPUT_FILE}")"

# ---------------------------------------------------------------------------
# 重启 nginx 让 webGUI 生效
# ---------------------------------------------------------------------------

if [ "$RESTART_NGINX" = "yes" ]; then
  log "$(cb_msg '重启 nginx...' 'Restarting nginx...')"
  if [ -x "$NGINX_RC" ] && "$NGINX_RC" restart >/dev/null 2>&1; then
    rm -f "$PENDING_FILE" || fail 1 "$(cb_msg '无法清除 nginx 待应用状态' 'Cannot clear pending nginx state')"
    log "$(cb_msg '✅ nginx 已重启' 'nginx restarted')"
  else
    fail 2 "$(cb_msg 'nginx 重启失败，证书待应用，下次续期会重试' 'nginx restart failed; certificate is pending and the next renewal will retry')"
  fi
else
  log "$(cb_msg '已跳过 nginx 重启，新证书将在下次重启 web 服务后生效' 'nginx restart skipped; the certificate will apply on the next web service restart')"
fi

EXPIRY=$(openssl x509 -noout -enddate -in "$OUTPUT_FILE" 2>/dev/null | cut -d= -f2)
log "$(cb_msg "✅ 完成，证书到期时间：${EXPIRY:-未知}" "Complete; certificate expires: ${EXPIRY:-unknown}")"
record_history "success" "${DOMAIN_ARRAY[*]}" "$(cb_msg "证书已更新，到期时间 ${EXPIRY:-未知}" "Certificate updated; expires ${EXPIRY:-unknown}")"
exit 0
