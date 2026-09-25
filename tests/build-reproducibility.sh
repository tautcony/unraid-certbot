#!/bin/bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d "$ROOT/.build-test.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

git clone -q --no-hardlinks "$ROOT" "$WORK/repo"
git -C "$WORK/repo" checkout -q --detach "$(git -C "$ROOT" rev-parse HEAD)"
cp "$ROOT/build.sh" "$WORK/repo/build.sh"
mkdir -p "$WORK/repo/tools"
cp "$ROOT/tools/package.py" "$WORK/repo/tools/package.py"
git -C "$WORK/repo" add build.sh tools/package.py
if ! git -C "$WORK/repo" diff --cached --quiet; then
  git -C "$WORK/repo" -c user.name=Test -c user.email=test@example.invalid \
    commit -qm 'test: committed build inputs'
fi

cd "$WORK/repo"
TZ=UTC ./build.sh >/dev/null
pkg="dist/unraid-certbot-$(tr -d '[:space:]' < VERSION)-noarch-1.txz"
first="$(shasum -a 256 "$pkg" | cut -d' ' -f1)"

sleep 2
noise="source/unraid-certbot/usr/local/emhttp/plugins/unraid-certbot/include/local-only.cache"
printf 'local-only\n' > "$noise"
printf '%s\n' "$noise" >> .git/info/exclude
TZ=Pacific/Honolulu ./build.sh >/dev/null
second="$(shasum -a 256 "$pkg" | cut -d' ' -f1)"

if [ "$first" != "$second" ]; then
  echo "FAIL: 同一提交重复打包的 SHA256 不一致：$first != $second" >&2
  exit 1
fi

expected="$(git log -1 --format=%ct HEAD -- source/unraid-certbot build.sh VERSION tools/package.py)"
git add unraid-certbot.plg
git -c user.name=Test -c user.email=test@example.invalid \
  commit --allow-empty -qm 'test: committed release metadata'
TZ=Asia/Shanghai ./build.sh >/dev/null
after_commit="$(shasum -a 256 "$pkg" | cut -d' ' -f1)"
[ "$after_commit" = "$first" ] || {
  echo "FAIL: 发布元数据提交改变了包的 SHA256：$first != $after_commit" >&2
  exit 1
}

image="python:3.12.7-slim-bookworm@sha256:60d9996b6a8a3689d36db740b49f4327be3be09a21122bd02fb8895abb38b50d"
COPYFILE_DISABLE=1 tar --no-xattrs -cf - -C build/stage . | \
  docker run --rm -i --platform linux/amd64 "$image" sh -c \
    'mkdir /tmp/stage && tar -xf - -C /tmp/stage && tar -cf - -C /tmp/stage .' | \
  docker run --rm -i --platform linux/amd64 -v "$PWD:/repo:ro" -w /repo \
    "$image" python3 tools/package.py "$expected" > "$WORK/gnu-input.txz"
gnu_input="$(shasum -a 256 "$WORK/gnu-input.txz" | cut -d' ' -f1)"
[ "$gnu_input" = "$first" ] || {
  echo "FAIL: macOS 与 GNU tar 输入得到不同 SHA256：$first != $gnu_input" >&2
  exit 1
}

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
