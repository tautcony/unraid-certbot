#!/usr/bin/env bash
# Move an existing version tag to committed source and rebuild that tag.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORKFLOW_BRANCH="${CB_RELEASE_BRANCH:-master}"
VERSION="${1:-}"
if [ "$VERSION" = --help ] || [ "$VERSION" = -h ]; then
  echo 'Usage: tools/republish-old.sh YYYY.MM.DD (run from the committed source branch)'
  exit 0
fi
if [ "$#" -ne 1 ] || ! [[ "$VERSION" =~ ^[0-9]{4}\.[0-9]{2}\.[0-9]{2}$ ]]; then
  echo 'Error: pass one existing YYYY.MM.DD tag' >&2
  exit 1
fi
cd "$ROOT"
SOURCE_BRANCH="$(git branch --show-current)"
[ -n "$SOURCE_BRANCH" ] || { echo 'Error: checkout a source branch first' >&2; exit 1; }
[ "$VERSION" = "$(tr -d '[:space:]' < VERSION)" ] || { echo 'Error: VERSION does not match' >&2; exit 1; }
[ -z "$(git status --porcelain --untracked-files=all)" ] || { echo 'Error: commit worktree changes first' >&2; exit 1; }
remote_tag="$(git ls-remote --tags --refs origin "refs/tags/$VERSION")"
[ -n "$remote_tag" ] || { echo "Error: remote tag $VERSION does not exist" >&2; exit 1; }
git fetch origin "$WORKFLOW_BRANCH" "$SOURCE_BRANCH"
git merge-base --is-ancestor "origin/$SOURCE_BRANCH" HEAD \
  || { echo "Error: merge origin/$SOURCE_BRANCH first" >&2; exit 1; }
git show "origin/$WORKFLOW_BRANCH:.github/workflows/release.yml" | grep -Fq 'ref: ${{ inputs.version }}' \
  || { echo 'Error: publish the updated release workflow before republishing' >&2; exit 1; }
command -v gh >/dev/null 2>&1 || { echo 'Error: GitHub CLI (gh) is required' >&2; exit 1; }
./lint.sh
git push origin "HEAD:refs/heads/$SOURCE_BRANCH"
old_tag="${remote_tag%%$'\t'*}"
git tag -f "$VERSION" HEAD
git push --force-with-lease="refs/tags/${VERSION}:${old_tag}" origin \
  "refs/tags/$VERSION:refs/tags/$VERSION"
gh workflow run release.yml --ref "$WORKFLOW_BRANCH" \
  -f version="$VERSION" -f mode=republish
echo "Rebuild workflow started for tag $VERSION"
