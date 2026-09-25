#!/bin/bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

git clone -q --no-hardlinks "$ROOT" "$WORK/repo"
git -C "$WORK/repo" checkout -q --detach "$(git -C "$ROOT" rev-parse HEAD)"
cp "$ROOT/build.sh" "$WORK/repo/build.sh"

cd "$WORK/repo"
TZ=UTC ./build.sh >/dev/null
pkg="dist/unraid-certbot-$(tr -d '[:space:]' < VERSION)-noarch-1.txz"
first="$(shasum -a 256 "$pkg" | cut -d' ' -f1)"

sleep 2
TZ=Pacific/Honolulu ./build.sh >/dev/null
second="$(shasum -a 256 "$pkg" | cut -d' ' -f1)"

if [ "$first" != "$second" ]; then
  echo "FAIL: 同一提交重复打包的 SHA256 不一致：$first != $second" >&2
  exit 1
fi

expected="$(git log -1 --format=%ct HEAD)"
mkdir "$WORK/extracted"
tar -xJf "$pkg" -C "$WORK/extracted" ./install/slack-desc
file="$WORK/extracted/install/slack-desc"
if actual="$(stat -c %Y "$file" 2>/dev/null)"; then
  :
else
  actual="$(stat -f %m "$file")"
fi
[ "$actual" = "$expected" ] || {
  echo "FAIL: 包内文件时间 $actual 与提交时间 $expected 不一致" >&2
  exit 1
}

echo "同一提交重复打包一致：SHA256 $first"
