#!/usr/bin/env bash
set -Eeuo pipefail
umask 022

die() { printf '::error title=Backend deployment failed::%s\n' "$*" >&2; exit 1; }
phase='Checking the checkout'
trap 'code=$?; printf "::error title=Backend deployment failed::%s (line %s, exit %s)\n" "$phase" "$LINENO" "$code" >&2' ERR
repo=${1:?Repository path is required}
branch=${2:-master}
target=${3:-production}
[[ "$target" = production || "$target" = test ]] || die 'Invalid deployment environment'
[[ "$target" != production || "$branch" = master ]] || die 'Production only accepts master'
[[ "$repo" = /* && "$repo" != / && -d "$repo/.git" ]] || die 'An existing checkout is required'
cd "$repo"
repo=$(pwd -P)
# Trust only this checkout for this process; keep compatibility with the VPS Git.
git() { command git -c safe.directory="$repo" "$@"; }
git check-ref-format --branch "$branch" >/dev/null
[[ "$(git rev-parse --show-toplevel)" = "$repo" ]] || die 'Repository path mismatch'

phase='Preparing the checkout'
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
  stash_message="deploy: $target/$branch $(date -u '+%Y-%m-%d %H:%M:%S UTC')"
  printf '[backend-deploy] stashing local changes: %s\n' "$stash_message"
  git stash push -m "$stash_message"
fi
git fetch --prune origin "$branch"
if [[ "$(git symbolic-ref --quiet --short HEAD || true)" != "$branch" ]]; then
  if git show-ref --verify --quiet "refs/heads/$branch"; then
    git switch "$branch"
  else
    git switch --track -c "$branch" "origin/$branch"
  fi
fi
[[ "$(git symbolic-ref --quiet --short HEAD)" = "$branch" ]] || die 'Unable to switch the VPS checkout to the requested branch'

phase='git pull'
printf '[backend-deploy] git pull (%s, %s)\n' "$target" "$branch"
git pull --ff-only origin "$branch"

phase='make up'
printf '[backend-deploy] make up\n'
make up
printf '[backend-deploy] git pull and make up completed\n'
