#!/usr/bin/env bash
set -Eeuo pipefail
# Keep the repository cwd and existing Compose project/volumes.
if docker compose version >/dev/null 2>&1; then
  exec docker compose "$@"
fi
exec docker-compose "$@"
