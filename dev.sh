#!/bin/bash
#
# unraid-certbot 本地调试入口。
#
#   ./dev.sh                 初始化沙箱并启动预览服务器（http://127.0.0.1:8080）
#   ./dev.sh serve           同上
#   ./dev.sh status          在沙箱里执行 renew.sh --status
#   ./dev.sh renew [参数]    在沙箱里执行一次续期（假 docker，不联网）
#   ./dev.sh reset           删掉沙箱重建
#   ./dev.sh seed            只初始化沙箱，不启动服务
#   ./dev.sh doctor          检查本机依赖
#
# 常用参数：
#   --port 9000              换预览端口（也可用 CB_DEV_PORT）
#   --no-seed                启动前不跑 seed.sh
#   --reset                  配合 serve/seed 先重建沙箱
#
# 环境变量（都由本脚本导出给插件使用）：
#   CB_DEV_ROOT  dev/run，所有 /boot、/usr/local/emhttp 路径都挂到它下面
#   CB_DOCKER    dev/bin/docker，不联网的假 certbot
#
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEV_ROOT="${CB_DEV_ROOT:-$ROOT/dev/run}"
DOCROOT="$DEV_ROOT/usr/local/emhttp"
PLUGIN_SRC="$ROOT/source/unraid-certbot/usr/local/emhttp/plugins/unraid-certbot"

usage() {
  # 打印文件开头的注释块（跳过 shebang 与紧随其后的空注释行）
  awk 'NR>2 && /^#/ { sub(/^# ?/, ""); print; next } NR>2 { exit }' "$0"
}

CMD="serve"
PORT="${CB_DEV_PORT:-8080}"
NO_SEED="no"
SEED_ARGS=()
RENEW_ARGS=()

while [ $# -gt 0 ]; do
  case "$1" in
    serve|status|renew|reset|seed|doctor|help) CMD="$1"; shift ;;
    --port)   PORT="${2:-8080}"; shift 2 ;;
    --port=*) PORT="${1#*=}"; shift ;;
    --reset)  SEED_ARGS+=(--reset); shift ;;
    --no-seed) NO_SEED="yes"; shift ;;
    -h|--help) CMD="help"; shift ;;
    *)        RENEW_ARGS+=("$1"); shift ;;
  esac
done

[ "$CMD" = "help" ] && { usage; exit 0; }

if [ "$CMD" = "doctor" ]; then
  echo "== unraid-certbot 本地调试依赖 =="
  fail=0
  for c in bash php openssl; do
    if command -v "$c" >/dev/null 2>&1; then
      printf '  ok   %-8s %s\n' "$c" "$(command -v "$c")"
    else
      printf '  FAIL %-8s 未安装\n' "$c"
      fail=1
    fi
  done
  php -r 'exit(version_compare(PHP_VERSION, "7.4", ">=") ? 0 : 1);' \
    && printf '  ok   %-8s %s\n' "php 版本" "$(php -r 'echo PHP_VERSION;')" \
    || { printf '  FAIL php 版本 %s，需要 7.4+\n' "$(php -r 'echo PHP_VERSION;')"; fail=1; }
  for c in xmllint; do
    command -v "$c" >/dev/null 2>&1 \
      && printf '  ok   %-8s %s\n' "$c" "$(command -v "$c")" \
      || printf '  --   %-8s 未安装（只影响 lint.sh 的 XML 检查）\n' "$c"
  done
  [ -d "$DEV_ROOT" ] \
    && printf '  ok   %-8s %s\n' "沙箱" "$DEV_ROOT" \
    || printf '  --   %-8s 还没建，运行 ./dev.sh 会自动创建\n' "沙箱"
  echo
  [ "$fail" -eq 0 ] && echo "依赖齐了" || echo "有依赖缺失"
  exit "$fail"
fi

if [ "$CMD" = "reset" ]; then
  exec "$ROOT/dev/seed.sh" --reset
fi

if [ "$CMD" = "seed" ]; then
  if [ "${#SEED_ARGS[@]}" -gt 0 ]; then
    exec "$ROOT/dev/seed.sh" "${SEED_ARGS[@]}"
  fi
  exec "$ROOT/dev/seed.sh"
fi

# ---------------------------------------------------------------------------
# 其余命令都要先有沙箱
# ---------------------------------------------------------------------------

if [ "$NO_SEED" != "yes" ]; then
  # status / renew 是命令行用法，沙箱初始化过程保持安静，只留插件自己的输出
  if [ "$CMD" = "status" ] || [ "$CMD" = "renew" ]; then
    if [ "${#SEED_ARGS[@]}" -gt 0 ]; then
      "$ROOT/dev/seed.sh" --quiet "${SEED_ARGS[@]}" || exit 1
    else
      "$ROOT/dev/seed.sh" --quiet || exit 1
    fi
  else
    if [ "${#SEED_ARGS[@]}" -gt 0 ]; then
      "$ROOT/dev/seed.sh" "${SEED_ARGS[@]}" || exit 1
    else
      "$ROOT/dev/seed.sh" || exit 1
    fi
  fi
fi

[ -x "$PLUGIN_SRC/scripts/renew.sh" ] || { echo "错误：找不到 $PLUGIN_SRC/scripts/renew.sh" >&2; exit 1; }

# 插件代码靠这两个变量认路：路径去沙箱，docker 换成假实现
export CB_DEV_ROOT="$DEV_ROOT"
export CB_DOCKER="${CB_DOCKER:-$DEV_ROOT/bin/docker}"
export PATH="$DEV_ROOT/bin:$PATH"

case "$CMD" in
  status)
    exec "$PLUGIN_SRC/scripts/renew.sh" --status
    ;;

  renew)
    if [ "${#RENEW_ARGS[@]}" -gt 0 ]; then
      exec "$PLUGIN_SRC/scripts/renew.sh" --trigger=manual "${RENEW_ARGS[@]}"
    fi
    exec "$PLUGIN_SRC/scripts/renew.sh" --trigger=manual
    ;;

  serve)
    command -v php >/dev/null 2>&1 || { echo "错误：需要 php 才能启动预览服务器" >&2; exit 1; }
    echo "==> 预览地址： http://127.0.0.1:${PORT}"
    echo "    设置页   ： http://127.0.0.1:${PORT}/Settings/UnraidCertbot"
    echo "    状态页   ： http://127.0.0.1:${PORT}/Utilities/CertStatus"
    echo "    沙箱目录 ： ${DEV_ROOT}"
    echo "    停止服务 ： Ctrl-C"
    echo
    exec php -S "127.0.0.1:${PORT}" -t "$DOCROOT" "$ROOT/dev/server.php"
    ;;

  *)
    echo "未知命令：$CMD" >&2
    usage
    exit 1
    ;;
esac
