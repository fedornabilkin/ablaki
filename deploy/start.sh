#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "$0")/.."
compose=(bash deploy/compose.sh)
"${compose[@]}" up --detach postgres
bash deploy/migrate.sh
test -d yii2/api/runtime
git rev-parse HEAD > yii2/api/runtime/deploy-version.txt
chmod 644 yii2/api/runtime/deploy-version.txt
"${compose[@]}" up --detach --no-deps --force-recreate php nginx
"${compose[@]}" ps
