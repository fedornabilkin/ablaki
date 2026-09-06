#!/usr/bin/env bash
# Only temporary fixtures: no SSH, Docker daemon or real database.
set -Eeuo pipefail
source_root=$(cd "$(dirname "$0")/../.." && pwd)
test_root=$(mktemp -d)
trap 'rm -rf -- "$test_root"' EXIT
mkdir -p "$test_root/bin"
export TEST_LOG TEST_REPO TEST_FAIL TEST_BRANCH TEST_SHA
TEST_SHA=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
cat > "$test_root/bin/git" <<'MOCK'
#!/usr/bin/env bash
set -eu
[[ "$1" = -c && "$2" = "safe.directory=$TEST_REPO" ]]
shift 2
printf 'git %s\n' "$*" >> "$TEST_LOG"
case "$1 $2" in
  'check-ref-format --branch') [[ "$TEST_FAIL" != invalid_branch ]] ;;
  'rev-parse --show-toplevel') printf '%s\n' "$TEST_REPO" ;;
  'symbolic-ref --quiet') printf '%s\n' "$TEST_BRANCH" ;;
  'pull --ff-only')
    [[ "$3" = origin && "$4" = "$TEST_BRANCH" ]]
    [[ "$(umask)" = 0022 ]]
    [[ "$TEST_FAIL" != pull ]] || exit 23
    printf 'updated code\n' > "$TEST_REPO/code.txt"
    ;;
  'rev-parse HEAD') printf '%s\n' "$TEST_SHA" ;;
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
# Exercise the actual make up runner with Docker commands substituted below.
bash deploy/start.sh
MOCK
cat > "$test_root/bin/docker" <<'MOCK'
#!/usr/bin/env bash
set -eu
printf 'docker %s\n' "$*" >> "$TEST_LOG"
grep -Fxq 'make up' "$TEST_LOG" || { echo 'Docker ran before make up' >&2; exit 1; }
[[ "$1" = compose ]]
shift
case "$*" in
  version|ps|'up --detach --no-deps php nginx') ;;
  run*)
    [[ "$*" = *'--entrypoint php php yii '* ]]
    if [[ "$*" = *'migrate/up'* && "$TEST_FAIL" = migration ]]; then exit 42; fi
    ;;
  *) echo "Unexpected Docker operation: $*" >&2; exit 1 ;;
esac
MOCK
chmod +x "$test_root/bin/"*
export PATH="$test_root/bin:$PATH"

for scenario in production test pull make migration wrong_branch invalid_branch production_branch; do
  TEST_FAIL=$scenario
  TEST_REPO="$test_root/$scenario"
  mkdir -p "$TEST_REPO/.git" "$TEST_REPO/yii2/vendor" "$TEST_REPO/yii2/api/runtime" "$TEST_REPO/deploy"
  TEST_REPO=$(cd "$TEST_REPO" && pwd -P)
  TEST_LOG="$TEST_REPO/commands.log"
  TEST_BRANCH=master
  target=production
  branch=master
  if [[ "$scenario" = test ]]; then target=test; branch=feature/test; TEST_BRANCH=$branch; fi
  if [[ "$scenario" = wrong_branch ]]; then TEST_BRANCH=another; fi
  if [[ "$scenario" = production_branch ]]; then branch=feature/test; fi
  cp "$source_root/deploy/"{compose,migrate,start}.sh "$TEST_REPO/deploy/"
  printf '# Local configuration\nDB_FIXTURE=preserved\n' > "$TEST_REPO/.env"
  cp "$TEST_REPO/.env" "$TEST_REPO/env-before"
  printf 'existing vendor\n' > "$TEST_REPO/yii2/vendor/autoload.php"
  : > "$TEST_LOG"
  status=0
  bash "$source_root/deploy/backend-deploy.sh" "$TEST_REPO" "$branch" "$target" > "$TEST_REPO/output.log" 2>&1 || status=$?
  case "$scenario" in
    production|test)
      [[ "$status" = 0 ]] || { cat "$TEST_REPO/output.log"; exit 1; }
      grep -Fxq "git pull --ff-only origin $branch" "$TEST_LOG"
      grep -Fxq 'make up' "$TEST_LOG"
      [[ "$(grep -c -- 'yii migrate/up' "$TEST_LOG")" = 5 ]]
      grep -Fxq 'docker compose up --detach --no-deps php nginx' "$TEST_LOG"
      [[ "$(cat "$TEST_REPO/yii2/api/runtime/deploy-version.txt")" = "$TEST_SHA" ]]
      ;;
    pull)
      [[ "$status" = 23 ]]
      ! grep -q '^make\|^docker' "$TEST_LOG"
      ;;
    make)
      [[ "$status" = 31 ]]
      ! grep -q '^docker' "$TEST_LOG"
      ;;
    migration)
      [[ "$status" = 42 ]]
      ! grep -q 'up --detach' "$TEST_LOG"
      [[ ! -f "$TEST_REPO/yii2/api/runtime/deploy-version.txt" ]]
      ;;
    wrong_branch|invalid_branch|production_branch)
      [[ "$status" != 0 ]]
      ! grep -q '^git pull\|^make\|^docker' "$TEST_LOG"
      ;;
  esac
  cmp "$TEST_REPO/.env" "$TEST_REPO/env-before"
  [[ "$(cat "$TEST_REPO/yii2/vendor/autoload.php")" = 'existing vendor' ]]
  ! grep -Eq 'stop |down |build |force-recreate|up --detach postgres|getenv|check-platform|reset|checkout|pg_dump|pg_restore' "$TEST_LOG"
  printf 'PASS deployment scenario: %s\n' "$scenario"
done
