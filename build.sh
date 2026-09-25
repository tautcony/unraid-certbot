#!/bin/bash
#
# unraid-certbot 打包脚本
#
#   ./build.sh              # 用 VERSION 文件里的版本号打包
#   ./build.sh 2026.10.01   # 指定版本号打包（会写回 VERSION 文件）
#
# 产出：
#   dist/unraid-certbot-<版本>-noarch-1.txz   上传到 GitHub Release
#   unraid-certbot.plg                        已填入版本号与校验和
#
# 装到 Unraid 上的方式（二选一）：
#   1) 把 dist/*.txz 传到 GitHub Release（tag 用版本号），再把 .plg 推到仓库 main 分支
#   2) 不用 Release：把 dist/*.txz 提交到仓库里，然后把 .plg 里的 <URL> 改成
#      https://raw.githubusercontent.com/<你>/unraid-certbot/main/dist/<文件名>
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
NAME="unraid-certbot"
SRC="${ROOT}/source/${NAME}"
STAGE="${ROOT}/build/stage"
DIST="${ROOT}/dist"
PLG="${ROOT}/${NAME}.plg"

# ---------------------------------------------------------------------------
# 版本号
# ---------------------------------------------------------------------------

if [ "${1:-}" != "" ]; then
  VERSION="$1"
elif [ -f "${ROOT}/VERSION" ]; then
  VERSION="$(tr -d '[:space:]' < "${ROOT}/VERSION")"
else
  echo "错误：没有 VERSION 文件，也没有传入版本号" >&2
  exit 1
fi

if ! printf '%s' "$VERSION" | grep -Eq '^[0-9]{4}\.[0-9]{2}\.[0-9]{2}$'; then
  echo "错误：版本号格式必须为 YYYY.MM.DD，例如 2026.09.10" >&2
  exit 1
fi

if [ "${1:-}" != "" ]; then
  version_tmp="$(mktemp "${ROOT}/.VERSION.XXXXXX")"
  printf '%s\n' "$VERSION" > "$version_tmp"
  mv "$version_tmp" "${ROOT}/VERSION"
fi

PKG="${NAME}-${VERSION}-noarch-1.txz"
echo "==> 打包 ${NAME} ${VERSION}"

# ---------------------------------------------------------------------------
# 准备目录
# ---------------------------------------------------------------------------

[ -d "$SRC" ] || { echo "错误：找不到源码目录 $SRC" >&2; exit 1; }

rm -rf "${ROOT}/build"
mkdir -p "$STAGE" "$DIST"

# Slackware 包的内容就是「相对根目录的文件树」
cp -R "${SRC}/usr" "$STAGE/"

PLUGIN_ON_ROOT="${STAGE}/usr/local/emhttp/plugins/${NAME}"
[ -d "$PLUGIN_ON_ROOT" ] || { echo "错误：源码树结构不对，缺少 ${PLUGIN_ON_ROOT}" >&2; exit 1; }

# 权限位必须在打包前设好，否则解到 Unraid 上脚本不可执行
find "${PLUGIN_ON_ROOT}/scripts" -type f -exec chmod 0755 {} + 2>/dev/null || true
find "${PLUGIN_ON_ROOT}/event"   -type f -exec chmod 0755 {} + 2>/dev/null || true
find "$PLUGIN_ON_ROOT" -type f \( -name '*.page' -o -name '*.php' -o -name '*.cfg' -o -name '*.css' \) -exec chmod 0644 {} + 2>/dev/null || true
find "$PLUGIN_ON_ROOT" -type d -exec chmod 0755 {} + 2>/dev/null || true

# ---------------------------------------------------------------------------
# Slackware 包元数据
#
# installpkg/upgradepkg 认 install/slack-desc（缺了会警告）与 install/doinst.sh
# （解包后执行的收尾脚本）。这两个目录本身不会被装到文件系统里。
# ---------------------------------------------------------------------------

mkdir -p "${STAGE}/install"

# slack-desc 必须是 11 行、每行以 "<包名>:" 开头
{
  printf '%-16s %s\n' "${NAME}:" "${NAME} (Let's Encrypt 证书自动续期，Cloudflare DNS 验证)"
  printf '%-16s %s\n' "${NAME}:" ""
  printf '%-16s %s\n' "${NAME}:" "通过 Cloudflare DNS-01 验证为 Unraid webGUI 签发和续期 Let's Encrypt"
  printf '%-16s %s\n' "${NAME}:" "证书，并合并成 Unraid 认的 _unraid_bundle.pem。"
  printf '%-16s %s\n' "${NAME}:" ""
  printf '%-16s %s\n' "${NAME}:" "全部操作都可以在 webGUI 完成：填参数、看证书状态、看续期历史与日志、"
  printf '%-16s %s\n' "${NAME}:" "手动触发续期。支持定时自动续期。"
  printf '%-16s %s\n' "${NAME}:" ""
  printf '%-16s %s\n' "${NAME}:" "配置与证书默认放在 /boot，阵列未启动时也能续期。"
  printf '%-16s %s\n' "${NAME}:" ""
  printf '%-16s %s\n' "${NAME}:" "https://github.com/tautcony/unraid-certbot"
} > "${STAGE}/install/slack-desc"

cat > "${STAGE}/install/doinst.sh" <<'DOINST'
#!/bin/sh
# 解包后兜底设置可执行权限（某些解包工具会丢掉权限位）
P=/usr/local/emhttp/plugins/unraid-certbot
[ -d "$P/scripts" ] && chmod 0755 "$P"/scripts/*.sh 2>/dev/null
[ -d "$P/event" ]   && chmod 0755 "$P"/event/* 2>/dev/null
exit 0
DOINST
chmod 0755 "${STAGE}/install/doinst.sh"

# ---------------------------------------------------------------------------
# 打 tar.xz
# ---------------------------------------------------------------------------

# tar 会记录每个条目的 mtime；统一为当前提交的 committer 时间，重跑同一提交不变。
COMMIT_TIME="$(TZ=UTC git -C "$ROOT" log -1 --format=%cd --date=format-local:%Y%m%d%H%M.%S HEAD)"
[ -n "$COMMIT_TIME" ] || { echo "错误：无法获取当前提交时间" >&2; exit 1; }
TZ=UTC find "$STAGE" -exec touch -h -t "$COMMIT_TIME" {} +

# 显式指定条目顺序，避免文件系统遍历顺序影响 tar 字节流。
FILE_LIST="${ROOT}/build/archive-files"
(cd "$STAGE" && find . -print0 | LC_ALL=C sort -z) > "$FILE_LIST"

# 优先用 GNU tar；macOS 上可能是 gtar，也可能是支持 --format=gnutar 的 bsdtar
TAR="tar"
if command -v gtar >/dev/null 2>&1; then
  TAR="gtar"
fi

TAR_FORMAT="gnu"
if ! "$TAR" --format=gnu -cf /dev/null --files-from /dev/null 2>/dev/null; then
  TAR_FORMAT="gnutar"
fi

"$TAR" --format="${TAR_FORMAT}" --owner=0 --group=0 --numeric-owner \
  --no-recursion --null -cJf "${DIST}/${PKG}" -C "$STAGE" -T "$FILE_LIST"

# ---------------------------------------------------------------------------
# 校验和
# ---------------------------------------------------------------------------

if command -v md5sum >/dev/null 2>&1; then
  MD5="$(md5sum "${DIST}/${PKG}" | cut -d' ' -f1)"
  SHA="$(sha256sum "${DIST}/${PKG}" | cut -d' ' -f1)"
else
  MD5="$(md5 -q "${DIST}/${PKG}")"
  SHA="$(shasum -a 256 "${DIST}/${PKG}" | cut -d' ' -f1)"
fi

echo "==> MD5    ${MD5}"
echo "==> SHA256 ${SHA}"

# ---------------------------------------------------------------------------
# 回填 .plg
# ---------------------------------------------------------------------------

[ -f "$PLG" ] || { echo "错误：找不到 $PLG" >&2; exit 1; }

# 用临时文件再覆盖，避免 sed -i 在 macOS/Linux 上的参数差异
sed -e "s|^<!ENTITY version   \"[^\"]*\">|<!ENTITY version   \"${VERSION}\">|" \
    -e "s|<MD5>[^<]*</MD5>|<MD5>${MD5}</MD5>|" \
    -e "s|<SHA256>[^<]*</SHA256>|<SHA256>${SHA}</SHA256>|" \
    "$PLG" > "${PLG}.tmp"
mv "${PLG}.tmp" "$PLG"

# ---------------------------------------------------------------------------
# 自检
# ---------------------------------------------------------------------------

echo "==> 包内容："
"$TAR" -tJf "${DIST}/${PKG}" | sort | sed 's/^/    /'

if ! grep -q "<MD5>${MD5}</MD5>" "$PLG"; then
  echo "警告：.plg 里的 MD5 没被正确替换，请检查模板" >&2
fi

echo ""
echo "✅ 完成"
echo ""
echo "接下来："
echo "  1. 把 dist/${PKG} 上传到 GitHub Release，tag 用 ${VERSION}"
echo "  2. 提交并推送 ${NAME}.plg 到 main 分支"
echo "  3. Unraid 上的安装地址："
echo "     https://raw.githubusercontent.com/tautcony/${NAME}/main/${NAME}.plg"
echo ""
echo "本地自检："
echo "  bash -n source/${NAME}/usr/local/emhttp/plugins/${NAME}/scripts/renew.sh"
echo "  php -l  source/${NAME}/usr/local/emhttp/plugins/${NAME}/include/status.php"
echo "  xmllint --noout ${NAME}.plg"
