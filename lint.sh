#!/bin/bash
#
# 静态检查。本地和 CI 共用同一份，避免两边命令漂移。
#
#   ./lint.sh
#
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$ROOT/source/unraid-certbot/usr/local/emhttp/plugins/unraid-certbot"
FAILED=0

ok()   { printf '  ok   %s\n' "$1"; }
bad()  { printf '  FAIL %s\n' "$1"; FAILED=1; }
skip() { printf '  --   %s（未安装，跳过）\n' "$1"; }

rel() { printf '%s' "${1#"$ROOT"/}"; }

echo "== bash =="
for f in "$ROOT"/build.sh "$ROOT"/lint.sh "$ROOT"/dev.sh "$ROOT"/dev/*.sh "$ROOT"/dev/bin/* \
         "$PLUGIN_DIR"/scripts/*.sh "$PLUGIN_DIR"/event/*; do
  [ -f "$f" ] || continue
  if bash -n "$f" 2>/dev/null; then
    ok "$(rel "$f")"
  else
    bad "$(rel "$f")"
    bash -n "$f"
  fi
done

# 插件代码是 php 7.4+；dev/ 下的仿真实现同样按这个版本检查语法
PHP_FILES=("$PLUGIN_DIR"/include/*.php "$PLUGIN_DIR"/*.page)
while IFS= read -r f; do PHP_FILES+=("$f"); done < <(find "$ROOT/dev" -type f -name '*.php' 2>/dev/null | sort)

echo "== php =="
if command -v php >/dev/null 2>&1; then
  for f in "${PHP_FILES[@]}"; do
    [ -f "$f" ] || continue
    if php -l "$f" >/dev/null 2>&1; then
      ok "$(rel "$f")"
    else
      bad "$(rel "$f")"
      php -l "$f"
    fi
  done
else
  skip php
fi

echo "== xml =="
if command -v xmllint >/dev/null 2>&1; then
  if xmllint --noout "$ROOT/unraid-certbot.plg" 2>/dev/null; then
    ok "unraid-certbot.plg"
  else
    bad "unraid-certbot.plg"
    xmllint --noout "$ROOT/unraid-certbot.plg"
  fi
else
  skip xmllint
fi

if command -v curl >/dev/null 2>&1 && command -v openssl >/dev/null 2>&1; then
  echo "== regression =="
  if bash "$ROOT/tests/regression.sh"; then ok 'tests/regression.sh'; else bad 'tests/regression.sh'; fi
fi

# xmllint 只检查 XML 是否合法，不展开实体。
# .plg 里全是 &name; 这类实体，实体写错会导致 Unraid 装出来的路径是空的，
# 所以这里按 Unraid 自己的方式（simplexml + LIBXML_NOCDATA）解析一遍。
if command -v php >/dev/null 2>&1; then
  echo "== plg 实体 =="
  php -r '
    $f = $argv[1];
    $x = @simplexml_load_file($f, null, LIBXML_NOCDATA);
    if ($x === false) { fwrite(STDERR, "  解析失败\n"); exit(1); }
    $err = 0;
    foreach (["name", "version", "launch", "pluginURL"] as $a) {
      if (trim((string)$x[$a]) === "") { fwrite(STDERR, "  属性 $a 展开后为空\n"); $err = 1; }
    }
    $pkg = null;
    foreach ($x->FILE as $file) {
      if (isset($file->URL)) { $pkg = $file; }
    }
    if ($pkg === null) { fwrite(STDERR, "  没有找到带 <URL> 的 FILE 块\n"); exit(1); }
    if (strpos((string)$pkg["Name"], "unraid-certbot") === false) {
      fwrite(STDERR, "  包路径没有展开：" . $pkg["Name"] . "\n"); $err = 1;
    }
    $md5 = trim((string)$pkg->MD5);
    if ($md5 !== "" && !preg_match("/^[0-9a-f]{32}$/", $md5)) {
      fwrite(STDERR, "  MD5 格式不对：$md5\n"); $err = 1;
    }
    // 下载地址与包名必须指向 .plg 声明的版本（URL 里的 &version; 已由 simplexml 展开）
    $ver = trim((string)$x["version"]);
    $url = trim((string)$pkg->URL);
    if ($ver === "" || strpos($url, "releases/download/$ver/") === false) {
      fwrite(STDERR, "  下载地址与版本号对不上：version=$ver URL=$url\n"); $err = 1;
    }
    if (strpos((string)$pkg["Name"], $ver) === false) {
      fwrite(STDERR, "  包名里没有版本号：" . $pkg["Name"] . "\n"); $err = 1;
    }
    if ($err) exit(1);
    printf("  ok   name=%s version=%s\n", $x["name"], $x["version"]);
  ' "$ROOT/unraid-certbot.plg" || FAILED=1
fi

echo
if [ "$FAILED" -eq 0 ]; then
  echo "全部通过"
else
  echo "有检查未通过"
fi
exit "$FAILED"
