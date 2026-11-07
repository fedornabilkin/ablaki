#!/usr/bin/env bash
# Real Git fixtures and command stubs only. Never connects to a deployment host.
set -Eeuo pipefail
source_root=$(cd "$(dirname "$0")/../.." && pwd)
fixture=$(mktemp -d)
fixture=$(cd "$fixture" && pwd -P)
trap 'rm -rf -- "$fixture"' EXIT
mkdir -p "$fixture/bin"
if ! command -v flock >/dev/null; then
  printf '#!/usr/bin/env bash\nexit 0\n' > "$fixture/bin/flock"
fi
cat > "$fixture/bin/docker-compose" <<'MOCK'
#!/usr/bin/env bash
printf 'compose %s\n' "$*" >> "$FIXTURE_LOG"
if [[ "$*" = *release/world-update* && "$FIXTURE_SCENARIO" = world_failure ]]; then exit 5; fi
MOCK
cat > "$fixture/bin/docker" <<'MOCK'
#!/usr/bin/env bash
printf 'docker %s\n' "$*" >> "$FIXTURE_LOG"
if [[ "$*" = *Config.Labels* ]]; then echo production; elif [[ "$*" = *State.Running* ]]; then echo true; fi
MOCK
cat > "$fixture/bin/curl" <<'MOCK'
#!/usr/bin/env bash
printf 'curl %s\n' "$*" >> "$FIXTURE_LOG"
MOCK
cat > "$fixture/bin/make" <<'MOCK'
#!/usr/bin/env bash
printf 'make %s\n' "$*" >> "$FIXTURE_LOG"
MOCK
chmod +x "$fixture/bin/"*
export PATH="$fixture/bin:$PATH"
for scenario in tooling proxy app world world_profile world_failure runtime_mode runtime_content paused missing changed_base changed_compose unchecked; do
  repo="$fixture/$scenario"
  git init -q --bare "$repo-origin"
  git init -q -b master "$repo"
  cd "$repo"
  git config user.name Fixture; git config user.email fixture@example.invalid
  git config core.autocrlf false
  mkdir -p deploy docker/nginx yii2/common/runtime/financial-maintenance yii2/api/runtime yii2/console/world-migrations yii2/common/modules/world/data/economy
  echo drain > deploy/php-drain.sh
  printf '*\n!.gitignore\n' > yii2/api/runtime/.gitignore
  chmod 755 yii2/api/runtime/.gitignore
  printf 'services: {}\n' > docker-compose.yaml
  printf 'echo runtime >> "$FIXTURE_LOG"\n' > deploy/php-runtime-upgrade.sh
  git add .; git commit -qm published
  base=$(git rev-parse HEAD)
  git remote add origin "$repo-origin"; git push -q -u origin master
  case "$scenario" in
    proxy) echo proxy > docker/nginx/test.conf ;;
    app) echo application > yii2/code.php ;;
    world|world_failure) echo migration > yii2/console/world-migrations/m261107_test.php ;;
    world_profile) echo profile > yii2/common/modules/world/data/economy/closed-test-v1.json ;;
    changed_compose) echo changed >> docker-compose.yaml ;;
    runtime_mode) chmod 644 yii2/api/runtime/.gitignore ;;
    *) echo tooling > deploy/tooling.txt ;;
  esac
  git add .; git commit -qm release
  target=$(git rev-parse HEAD); git push -q origin master
  git reset --hard -q "$base"
  if [[ "$scenario" = runtime_mode || "$scenario" = runtime_content ]]; then chmod 666 yii2/api/runtime/.gitignore; fi
  if [[ "$scenario" = runtime_content ]]; then echo 'operator content' >> yii2/api/runtime/.gitignore; fi
  echo operator >> docker-compose.yaml
  control="$repo/yii2/common/runtime/financial-maintenance"; mkdir -p "$control"
  for file in completed world-activation-verified.json rating-precision-verified.json; do echo done > "$control/$file"; done
  [[ "$scenario" != paused ]] || touch "$control/paused"
  [[ "$scenario" != missing ]] || rm "$control/world-activation-verified.json"
  [[ "$scenario" != changed_base ]] || base=$target
  [[ "$scenario" != unchecked ]] || target=$(printf '%040d' 2)
  export FIXTURE_LOG="$repo/commands.log"
  export FIXTURE_SCENARIO="$scenario"
  touch "$control/writers.lock"
  sed "s@repo=/var/www/api.ablakin.ru@repo=$repo@" "$source_root/deploy/production-release.sh" > "$fixture/runner.sh"
  status=0
  bash "$fixture/runner.sh" "$target" "$base" > "$fixture/$scenario.log" 2>&1 || status=$?
  case "$scenario" in
    tooling|proxy|app|world|world_profile|runtime_mode)
      [[ "$status" = 0 ]] || { cat "$fixture/$scenario.log"; exit 1; }
      [[ "$(git rev-parse HEAD)" = "$target" ]]
      case "$scenario" in
        tooling) [[ ! -f "$FIXTURE_LOG" ]] ;;
        proxy) grep -Fxq 'compose exec -T nginx nginx -s reload' "$FIXTURE_LOG"; ! grep -q runtime "$FIXTURE_LOG" ;;
        app) grep -Fxq runtime "$FIXTURE_LOG"; grep -Fxq 'make up' "$FIXTURE_LOG" ;;
        runtime_mode) git diff --quiet -- yii2/api/runtime/.gitignore; grep -q 'Restored unchanged runtime sentinel mode' "$fixture/$scenario.log" ;;
        world|world_profile)
          publish=0; [[ "$scenario" != world_profile ]] || publish=1
          grep -Fq "release/world-update $publish" "$FIXTURE_LOG"
          grep -Fq 'docker start ablaki-world-worker-production' "$FIXTURE_LOG"
          [[ ! -e "$control/paused" && ! -e "$control/world-release.pending" ]]
          ;;
      esac
      ;;
    world_failure)
      [[ "$status" != 0 && -e "$control/paused" && "$(cat "$control/world-release.pending")" = "$target" ]]
      ! grep -Fq 'docker start' "$FIXTURE_LOG"
      # The same checked SHA can resume its own interrupted update.
      export FIXTURE_SCENARIO=world
      bash "$fixture/runner.sh" "$target" "$base" > "$fixture/resume.log" 2>&1 || { cat "$fixture/resume.log"; exit 1; }
      [[ ! -e "$control/paused" && ! -e "$control/world-release.pending" ]]
      ;;
    *) [[ "$status" != 0 && ! -f "$FIXTURE_LOG" ]] ;;
  esac
  [[ "$(cat docker-compose.yaml)" = $'services: {}\noperator' ]]
  if [[ "$scenario" = runtime_content ]]; then grep -Fxq 'operator content' yii2/api/runtime/.gitignore; fi
  printf 'PASS ordinary production release: %s\n' "$scenario"
done
