#!/bin/bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d "$ROOT/.build-test.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

git clone -q --no-hardlinks "$ROOT" "$WORK/repo"
git -C "$WORK/repo" checkout -q --detach "$(git -C "$ROOT" rev-parse HEAD)"
if git -C "$WORK/repo" ls-files --error-unmatch build.sh >/dev/null 2>&1; then
  git -C "$WORK/repo" rm -q build.sh
fi
mkdir -p "$WORK/repo/tools"
cp "$ROOT/tools/build.sh" "$WORK/repo/tools/build.sh"
cp "$ROOT/tools/package.py" "$WORK/repo/tools/package.py"
chmod +x "$WORK/repo/tools/build.sh"
git -C "$WORK/repo" add -A
if ! git -C "$WORK/repo" diff --cached --quiet; then
  git -C "$WORK/repo" -c user.name=Test -c user.email=test@example.invalid \
    commit -qm 'test: committed build inputs'
fi

cd "$WORK/repo"
TZ=UTC tools/build.sh >/dev/null
pkg="dist/unraid-certbot-$(tr -d '[:space:]' < VERSION)-noarch-1.txz"
first="$(shasum -a 256 "$pkg" | cut -d' ' -f1)"

TZ=UTC tools/build.sh --revision 2 >/dev/null
revised="dist/unraid-certbot-$(tr -d '[:space:]' < VERSION)-noarch-2.txz"
[ -s "$revised" ] || { echo 'FAIL: revised package missing' >&2; exit 1; }
grep -Fq '<!ENTITY pkgfile   "&name;-&version;-noarch-2.txz">' unraid-certbot.plg \
  || { echo 'FAIL: revised package URL missing from plg' >&2; exit 1; }
revised_sha="$(shasum -a 256 "$revised" | cut -d' ' -f1)"
grep -Fq "<SHA256>${revised_sha}</SHA256>" unraid-certbot.plg \
  || { echo 'FAIL: revised package checksum missing from plg' >&2; exit 1; }

sleep 2
noise="source/unraid-certbot/usr/local/emhttp/plugins/unraid-certbot/include/local-only.cache"
printf 'local-only\n' > "$noise"
printf '%s\n' "$noise" >> .git/info/exclude
TZ=Pacific/Honolulu tools/build.sh >/dev/null
second="$(shasum -a 256 "$pkg" | cut -d' ' -f1)"

if [ "$first" != "$second" ]; then
  echo "FAIL: 同一提交重复打包的 SHA256 不一致：$first != $second" >&2
  exit 1
fi

TZ=UTC tools/build.sh --local >/dev/null
tar -tJf "$pkg" | grep 'local-only.cache' >/dev/null || {
  echo 'FAIL: --local did not package worktree changes' >&2
  exit 1
}
TZ=UTC tools/build.sh >/dev/null

expected="$(git log -1 --format=%ct HEAD -- source/unraid-certbot tools/build.sh tools/package.py VERSION)"
git add unraid-certbot.plg
git -c user.name=Test -c user.email=test@example.invalid \
  commit --allow-empty -qm 'test: committed release metadata'
TZ=Asia/Shanghai tools/build.sh >/dev/null
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
