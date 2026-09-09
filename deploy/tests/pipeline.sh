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
shift 2
printf 'git %s\n' "$*" >> "$TEST_LOG"
case "$1 $2" in
  'check-ref-format --branch') [[ "$TEST_FAIL" != invalid_branch ]] ;;
  'rev-parse --show-toplevel') printf '%s\n' "$TEST_REPO" ;;
  'status --porcelain') ;;
  'fetch --prune') ;;
  'show-ref --verify') exit 1 ;;
  'switch --track') printf '%s\n' "$4" > "$TEST_REPO/current-branch" ;;
  'switch feature/test') printf '%s\n' "$2" > "$TEST_REPO/current-branch" ;;
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

for scenario in production test pull make wrong_branch invalid_branch production_branch; do
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
    production|test)
      [[ "$status" = 0 ]] || { cat "$TEST_REPO/output.log"; exit 1; }
      grep -Fxq "git pull --ff-only origin $branch" "$TEST_LOG"
      grep -Fxq 'make up' "$TEST_LOG"
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
  ! grep -Eq 'stop |down |build |force-recreate|up --detach postgres|getenv|check-platform|reset|checkout|pg_dump|pg_restore' "$TEST_LOG"
  printf 'PASS deployment scenario: %s\n' "$scenario"
done
