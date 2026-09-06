#!/usr/bin/env bash
# Surface fixture-only CI failures in the public check annotations as well as logs.
set -Eeuo pipefail
[[ "${CI:-}" = true && "$#" -gt 0 ]] || exit 1
log_file=$(mktemp "${RUNNER_TEMP:-/tmp}/ablaki-check.XXXXXXXX")
trap 'rm -f -- "$log_file"' EXIT
if "$@" 2>&1 | tee "$log_file"; then
  exit 0
else
  result=$?
  details=$(tail -n 25 "$log_file")
  details=${details//%/%25}
  details=${details//$'\r'/%0D}
  details=${details//$'\n'/%0A}
  printf '::error title=Deployment integration check failed::%s\n' "$details"
  exit "$result"
fi
