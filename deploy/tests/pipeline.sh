#!/usr/bin/env bash
# Only temporary fixtures: no SSH, Docker daemon or real database.
set -Eeuo pipefail
source_root=$(cd "$(dirname "$0")/../.." && pwd)
test_root=$(mktemp -d)
trap 'rm -rf -- "$test_root"' EXIT
mkdir -p "$test_root/bin"
export TEST_LOG TEST_REPO TEST_FAIL TEST_BRANCH
cat > "$test_root/bin/git" <<'MOCK'
#!/usr/bin/env bash
set -eu
[[ "$1" = -c && "$2" = "safe.directory=$TEST_REPO" ]]
while [[ "$1" = -c ]]; do shift 2; done
printf 'git %s\n' "$*" >> "$TEST_LOG"
case "$1 $2" in
  'check-ref-format --branch') [[ "$TEST_FAIL" != invalid_branch ]] ;;
  'rev-parse --show-toplevel') printf '%s\n' "$TEST_REPO" ;;
  'status --porcelain')
    [[ "$TEST_FAIL" != dirty ]] || printf ' M local.php\n'
    ;;
  'stash push') ;;
  'fetch --prune') ;;
  'show-ref --verify') exit 1 ;;
  'checkout -b') printf '%s\n' "$3" > "$TEST_REPO/current-branch" ;;
  'checkout master') printf '%s\n' "$2" > "$TEST_REPO/current-branch" ;;
  'checkout feature/test') printf '%s\n' "$2" > "$TEST_REPO/current-branch" ;;
  'symbolic-ref --quiet')
    if [[ -f "$TEST_REPO/current-branch" ]]; then cat "$TEST_REPO/current-branch"; else printf '%s\n' "$TEST_BRANCH"; fi
    ;;
  'pull --ff-only')
    expected_branch="$TEST_BRANCH"
    [[ ! -f "$TEST_REPO/current-branch" ]] || expected_branch="$(cat "$TEST_REPO/current-branch")"
    [[ "$3" = origin && "$4" = "$expected_branch" ]]
    [[ "$(umask)" = 0022 ]]
    [[ "$TEST_FAIL" != pull ]] || exit 23
    printf 'updated code\n' > "$TEST_REPO/code.txt"
    ;;
  *) echo "Unexpected Git command: $*" >&2; exit 1 ;;
esac
MOCK
cat > "$test_root/bin/make" <<'MOCK'
#!/usr/bin/env bash
set -eu
printf 'make %s\n' "$*" >> "$TEST_LOG"
[[ "$*" = up ]]
[[ -f "$TEST_REPO/code.txt" ]] || { echo 'make ran before git pull' >&2; exit 1; }
[[ "$TEST_FAIL" != make ]] || exit 31
MOCK
cat > "$test_root/bin/docker" <<'MOCK'
#!/usr/bin/env bash
set -eu
printf 'docker %s\n' "$*" >> "$TEST_LOG"
grep -Fxq 'make up' "$TEST_LOG" || { echo 'Docker ran before make up' >&2; exit 1; }
echo 'The deploy wrapper must not call Docker directly' >&2
exit 1
MOCK
chmod +x "$test_root/bin/"*
export PATH="$test_root/bin:$PATH"

for scenario in production test dirty pull make wrong_branch invalid_branch production_branch; do
  TEST_FAIL=$scenario
  TEST_REPO="$test_root/$scenario"
  mkdir -p "$TEST_REPO/.git" "$TEST_REPO/yii2/vendor"
  TEST_REPO=$(cd "$TEST_REPO" && pwd -P)
  TEST_LOG="$TEST_REPO/commands.log"
  TEST_BRANCH=master
  target=production
  branch=master
  if [[ "$scenario" = test ]]; then target=test; branch=feature/test; TEST_BRANCH=$branch; fi
  if [[ "$scenario" = wrong_branch ]]; then TEST_BRANCH=another; fi
  if [[ "$scenario" = production_branch ]]; then branch=feature/test; fi
  printf '# Local configuration\nDB_FIXTURE=preserved\n' > "$TEST_REPO/.env"
  cp "$TEST_REPO/.env" "$TEST_REPO/env-before"
  printf 'existing vendor\n' > "$TEST_REPO/yii2/vendor/autoload.php"
  : > "$TEST_LOG"
  status=0
  bash "$source_root/deploy/backend-deploy.sh" "$TEST_REPO" "$branch" "$target" > "$TEST_REPO/output.log" 2>&1 || status=$?
  case "$scenario" in
    production|test|dirty)
      [[ "$status" = 0 ]] || { cat "$TEST_REPO/output.log"; exit 1; }
      grep -Fxq "git pull --ff-only origin $branch" "$TEST_LOG"
      grep -Fxq 'make up' "$TEST_LOG"
      if [[ "$scenario" = dirty ]]; then grep -Eq '^git stash push -m deploy: production/master ' "$TEST_LOG"; fi
      ! grep -q '^docker' "$TEST_LOG"
      ;;
    pull)
      [[ "$status" = 23 ]]
      ! grep -q '^make\|^docker' "$TEST_LOG"
      ;;
    make)
      [[ "$status" = 31 ]]
      ! grep -q '^docker' "$TEST_LOG"
      ;;
    invalid_branch|production_branch)
      [[ "$status" != 0 ]]
      ! grep -q '^git pull\|^make\|^docker' "$TEST_LOG"
      ;;
    wrong_branch)
      [[ "$status" = 0 ]] || { cat "$TEST_REPO/output.log"; exit 1; }
      grep -Fxq "git pull --ff-only origin $branch" "$TEST_LOG"
      grep -Fxq 'make up' "$TEST_LOG"
      ;;
  esac
  cmp "$TEST_REPO/.env" "$TEST_REPO/env-before"
  [[ "$(cat "$TEST_REPO/yii2/vendor/autoload.php")" = 'existing vendor' ]]
  ! grep -Eq 'stop |down |build |force-recreate|up --detach postgres|getenv|check-platform|reset|pg_dump|pg_restore' "$TEST_LOG"
  printf 'PASS deployment scenario: %s\n' "$scenario"
done

# Exercise test-world activation with temporary paths and command fixtures only.
# In particular, no PostgreSQL dump, restore or database creation is permitted.
mkdir -p "$test_root/world-bin"
export TEST_DEPLOY_ROOT TEST_WORLD_FAIL
cat > "$test_root/world-bin/git" <<'MOCK'
#!/usr/bin/env bash
set -eu
[[ "$*" = 'symbolic-ref --quiet --short HEAD' ]]
printf 'test\n'
MOCK
cat > "$test_root/world-bin/flock" <<'MOCK'
#!/usr/bin/env bash
[[ "$*" = '-n 9' && "$TEST_WORLD_FAIL" != lock ]]
MOCK
cat > "$test_root/world-bin/docker" <<'MOCK'
#!/usr/bin/env bash
set -eu
printf 'docker %s\n' "$*" >> "$TEST_LOG"
case "$1 $2" in
  'container inspect') exit 0 ;;
  'inspect -f')
    if [[ "$3" = *State.Running* ]]; then
      if [[ "$TEST_WORLD_FAIL" = worker ]]; then printf 'false\n'; else printf 'true\n'; fi
    elif [[ "$TEST_WORLD_FAIL" = label ]]; then printf 'unexpected\n'; else printf 'test\n'; fi
    ;;
  'stop --time'|'rm ablaki-world-worker-test'|'update --restart') ;;
  *) echo "Unexpected Docker command: $*" >&2; exit 1 ;;
esac
MOCK
cat > "$test_root/world-bin/docker-compose" <<'MOCK'
#!/usr/bin/env bash
set -eu
printf 'compose %s\n' "$*" >> "$TEST_LOG"
case "$*" in
  'stop -t 60 nginx php') ;;
  'run --rm --no-deps -T --entrypoint php -e WORLD_INSTALL=confirmed-world-install -e WORLD_TEST_SETUP=confirmed-test-checkout php /web/yii2/yii world-setup/test-ready')
    [[ -f "$TEST_DEPLOY_ROOT/world-activation.pending" ]]
    [[ "$TEST_WORLD_FAIL" != setup ]] || exit 23
    ;;
  'run --rm --no-deps -T --entrypoint php php /web/yii2/yii world-worker/run 200')
    [[ "$TEST_WORLD_FAIL" != handlers ]] || exit 24
    ;;
  'up -d --no-deps php nginx') ;;
  'run -d --no-deps --name ablaki-world-worker-test --label ablaki.world.worker=test --entrypoint /bin/sh php -c '*) ;;
  *) echo "Unexpected Compose command (including any database backup/restore): $*" >&2; exit 1 ;;
esac
MOCK
chmod +x "$test_root/world-bin/"*
export PATH="$test_root/world-bin:$PATH"

for scenario in ready resume setup handlers worker label lock; do
  TEST_WORLD_FAIL=$scenario
  TEST_REPO="$test_root/world-$scenario/repo"
  TEST_DEPLOY_ROOT="$test_root/world-$scenario/deploy"
  mkdir -p "$TEST_REPO" "$TEST_DEPLOY_ROOT/backups"
  TEST_REPO=$(cd "$TEST_REPO" && pwd -P)
  TEST_DEPLOY_ROOT=$(cd "$TEST_DEPLOY_ROOT" && pwd -P)
  printf 'existing backup\n' > "$TEST_DEPLOY_ROOT/backups/previous.dump"
  printf '# Existing settings\nDB_FIXTURE=preserved\nWORLD_READ=0\n' > "$TEST_REPO/.env"
  TEST_LOG="$TEST_REPO/commands.log"
  : > "$TEST_LOG"
  [[ "$scenario" != resume ]] || touch "$TEST_DEPLOY_ROOT/world-activation.pending"
  sed -e "s@/var/code/ablaki@$TEST_REPO@g" -e "s@/opt/ablaki-backend-test@$TEST_DEPLOY_ROOT@g" \
    "$source_root/deploy/world-open-test.sh" > "$TEST_REPO/activate.sh"
  status=0
  bash "$TEST_REPO/activate.sh" > "$TEST_REPO/output.log" 2>&1 || status=$?
  if [[ "$scenario" = ready || "$scenario" = resume ]]; then
    [[ "$status" = 0 ]] || { cat "$TEST_REPO/output.log"; exit 1; }
    [[ ! -e "$TEST_DEPLOY_ROOT/world-activation.pending" ]]
    grep -Fxq 'compose up -d --no-deps php nginx' "$TEST_LOG"
    grep -Fq 'docker update --restart unless-stopped' "$TEST_LOG"
    for flag in WORLD_READ WORLD_WRITE STORAGE_V2 ECONOMY_TICK WORLD_WORKER WORLD_TEST_MODE; do
      [[ "$(grep -c "^$flag=1$" "$TEST_REPO/.env")" = 1 ]]
    done
  else
    [[ "$status" != 0 ]]
    if [[ "$scenario" = setup || "$scenario" = handlers || "$scenario" = worker ]]; then
      [[ -f "$TEST_DEPLOY_ROOT/world-activation.pending" ]]
      grep -Fq 'rollout checkpoints are retained' "$TEST_REPO/output.log"
    fi
    if [[ "$scenario" != worker ]]; then ! grep -q '^compose up' "$TEST_LOG"; fi
    if [[ "$scenario" = label || "$scenario" = lock ]]; then
      [[ ! -e "$TEST_DEPLOY_ROOT/world-activation.pending" ]]
      ! grep -q '^compose\|^docker stop' "$TEST_LOG"
    fi
  fi
  grep -Fxq 'DB_FIXTURE=preserved' "$TEST_REPO/.env"
  [[ "$(cat "$TEST_DEPLOY_ROOT/backups/previous.dump")" = 'existing backup' ]]
  [[ "$(find "$TEST_DEPLOY_ROOT/backups" -type f | wc -l)" -eq 1 ]]
  ! grep -Eq 'pg_dump|pg_restore|createdb|dropdb|world-verify-test' "$TEST_LOG"
  printf 'PASS world activation without database backup: %s\n' "$scenario"
done
