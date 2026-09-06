#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "$0")/.."
compose=(bash deploy/compose.sh)
# Both databases already exist and run independently of code deployment.
# Yii selects the existing connection from this checkout's .env/local config.
bash deploy/migrate.sh
test -d yii2/api/runtime
git -c safe.directory="$(pwd)" rev-parse HEAD > yii2/api/runtime/deploy-version.txt
chmod 644 yii2/api/runtime/deploy-version.txt
"${compose[@]}" up --detach --no-deps php nginx
"${compose[@]}" ps
