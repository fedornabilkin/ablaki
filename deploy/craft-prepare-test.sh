#!/usr/bin/env bash
# Stash the owner's untracked design documents before git pull starts tracking them.
set -Eeuo pipefail
die() { printf '::error title=Test document preparation failed::%s\n' "$*" >&2; exit 1; }
cd /var/code/ablaki
[[ "$(pwd -P)" = /var/code/ablaki ]]
documents=()
for document in docs/plan/mvp-ablaki-craft.md docs/tz/world.md; do
  [[ -f "$document" ]] || continue
  [[ ! -L "$document" && "$(readlink -f -- "$document")" = "/var/code/ablaki/$document" ]] || die "Unexpected document path: $document"
  tracked=$(git -c safe.directory=/var/code/ablaki ls-files -- "$document")
  [[ -z "$tracked" ]] || continue
  documents+=("$document")
done
if (( ${#documents[@]} )); then
  stash_message="deploy: test untracked design documents $(date -u '+%Y-%m-%d %H:%M:%S UTC')"
  git -c safe.directory=/var/code/ablaki -c user.name='Ablaki deploy' -c user.email='deploy@ablaki.ru' \
    stash push --include-untracked -m "$stash_message" -- "${documents[@]}"
  for document in "${documents[@]}"; do
    [[ ! -e "$document" ]] || die "Git stash did not clear $document; check write permission on its parent directory"
  done
  printf 'Preserved untracked design documents in Git stash: %s\n' "$stash_message"
fi
