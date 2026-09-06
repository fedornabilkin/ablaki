#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "$0")/.."
compose=(bash deploy/compose.sh)
for path in '@vendor/dektrium/yii2-user/migrations' '@fedornabilkin/binds/migrations' '@fedornabilkin/redirect/migrations' '@yii/rbac/migrations' '@app/migrations'; do
  "${compose[@]}" run --rm --no-deps -T -e YII_DEBUG=0 -e YII_ENV=prod --workdir /web/yii2 --entrypoint php php \
    yii migrate/up --migrationPath="$path" --interactive=0
done
"${compose[@]}" run --rm --no-deps -T -e YII_DEBUG=0 -e YII_ENV=prod --workdir /web/yii2 --entrypoint php php yii cache/flush-schema --interactive=0
