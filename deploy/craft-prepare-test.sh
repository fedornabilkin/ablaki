#!/usr/bin/env bash
# Preserve the owner's untracked design documents before git pull starts tracking them.
set -Eeuo pipefail
umask 077
die() { printf '::error title=Test document preparation failed::%s\n' "$*" >&2; exit 1; }
backup_root=${1:?Test deployment directory is required}
[[ "$backup_root" = /opt/ablaki-backend-test ]] || die 'Unexpected backup location'
[[ -d "$backup_root" && -w "$backup_root" && -x "$backup_root" ]] || die 'The deployment user must be able to write to the test deployment directory'
cd /var/code/ablaki
[[ "$(pwd -P)" = /var/code/ablaki ]]
for document in docs/plan/mvp-ablaki-craft.md docs/tz/world.md; do
  [[ -f "$document" ]] || continue
  [[ ! -L "$document" && "$(readlink -f -- "$document")" = "/var/code/ablaki/$document" ]] || die "Unexpected document path: $document"
  tracked=$(git -c safe.directory=/var/code/ablaki ls-files -- "$document")
  [[ -z "$tracked" ]] || continue

  # The workflow already uploads scripts here as the deployment user. A fresh
  # private directory avoids ownership left by earlier runs inside .git.
  backup_directory=$(mktemp -d "$backup_root/document-backup.XXXXXXXX")
  backup="$backup_directory/${document##*/}"
  cp -- "$document" "$backup"
  cmp -s -- "$document" "$backup" || die "Backup verification failed; original retained: $document"
  echo "Preserved $document in $backup"

  document_directory=${document%/*}
  if [[ ! -w "$document_directory" || ! -x "$document_directory" ]]; then
    id >&2
    ls -ldn -- "$document_directory" "$document" >&2
    die "The deployment user needs write/search permission on /var/code/ablaki/$document_directory; original retained, backup: $backup"
  fi
  if ! rm -- "$document"; then
    id >&2
    ls -ldn -- "$document_directory" "$document" >&2
    die "Cannot remove the backed-up untracked document; check directory ACL/sticky/immutable attributes. Backup: $backup"
  fi
  echo "Removed the backed-up untracked copy of $document before git pull"
done
