#!/usr/bin/env bash
# Preserve the owner's untracked design documents before git pull starts tracking them.
set -Eeuo pipefail
cd /var/code/ablaki
[[ "$(pwd -P)" = /var/code/ablaki ]]
for document in docs/plan/mvp-ablaki-craft.md docs/tz/world.md; do
  if [[ -f "$document" ]] && ! git -c safe.directory=/var/code/ablaki ls-files --error-unmatch -- "$document" >/dev/null 2>&1; then
    [[ ! -L "$document" ]]
    git_directory=$(git -c safe.directory=/var/code/ablaki rev-parse --absolute-git-dir)
    backup_directory="$git_directory/craft-plan-backups"
    mkdir -p "$backup_directory"
    document_name=${document##*/}
    backup="$backup_directory/${document_name%.md}-$(date -u +%Y%m%dT%H%M%S)-$$.md"
    [[ ! -e "$backup" ]]
    mv -- "$document" "$backup"
    [[ -f "$backup" && ! -e "$document" ]]
    echo "Preserved $document in $backup"
  fi
done
