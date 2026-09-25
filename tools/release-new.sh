#!/usr/bin/env bash
# Publish a new tag, then dispatch the release workflow for that tag.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BRANCH="${CB_RELEASE_BRANCH:-master}"
VERSION="${1:-$(tr -d '[:space:]' < "$ROOT/VERSION")}"
if [ "$VERSION" = --help ] || [ "$VERSION" = -h ]; then
  echo 'Usage: tools/release-new.sh [YYYY.MM.DD]'
  exit 0
fi
if [ "$#" -gt 1 ] || ! [[ "$VERSION" =~ ^[0-9]{4}\.[0-9]{2}\.[0-9]{2}$ ]]; then
  echo 'Error: expected one YYYY.MM.DD version' >&2
  exit 1
fi
cd "$ROOT"
[ "$VERSION" = "$(tr -d '[:space:]' < VERSION)" ] || { echo 'Error: VERSION does not match' >&2; exit 1; }
[ "$(git branch --show-current)" = "$BRANCH" ] || { echo "Error: checkout $BRANCH first" >&2; exit 1; }
[ -z "$(git status --porcelain --untracked-files=all)" ] || { echo 'Error: commit or remove worktree changes first' >&2; exit 1; }
git show-ref --verify --quiet "refs/tags/$VERSION" && { echo "Error: local tag $VERSION exists" >&2; exit 1; }
remote_tag="$(git ls-remote --tags --refs origin "refs/tags/$VERSION")"
[ -z "$remote_tag" ] || { echo "Error: remote tag $VERSION exists; use tools/republish-old.sh" >&2; exit 1; }
git fetch origin "$BRANCH"
git merge-base --is-ancestor "origin/$BRANCH" HEAD || { echo "Error: merge origin/$BRANCH first" >&2; exit 1; }
./lint.sh
command -v gh >/dev/null 2>&1 || { echo 'Error: GitHub CLI (gh) is required' >&2; exit 1; }
git push origin "$BRANCH"
git tag "$VERSION"
git push origin "refs/tags/$VERSION"
gh workflow run release.yml --ref "$BRANCH" -f version="$VERSION" -f mode=new
echo "Release workflow started for $VERSION"
