#!/usr/bin/env bash
# Shared CI entry points; every database suite uses only its allowlisted fixture.
set -Eeuo pipefail
cd "$(dirname "$0")/../yii2"
case "${1:?Choose isolated, database or mariadb}" in
  isolated)
    unset ABL_TEST_DSN
    suites=(frontend-lists game-overview five-game forum-gift forum-install topic68-economy worker-barrier-test world-economy-gameplay user-activity user-cleanup craft-domain world-event-domain world-domain-regression world-build-input world-development-route world-rating world-telegram-reward world-npc-funding)
    if [[ "${CORE_CHECKED:-false}" != true ]]; then
      suites+=(api-route-catalog world-player-read auth-response telegram-auth daily-reward exchange-refund frontend-api-routing classic-craft-install world-economy-profile)
    fi
    for suite in "${suites[@]}"; do php "tests/release/$suite.php"; done
    ;;
  database|mariadb)
    [[ -n "${ABL_TEST_DSN:-}" ]]
    if [[ "$1" = mariadb ]]; then php tests/release/world-test-credits.php; fi
    php tests/release/target-database.php
    php tests/release/world-economy-race.php
    php tests/release/reset-ci-database.php
    php tests/release/world-telegram-reward.php
    php tests/release/reset-ci-database.php
    php tests/release/world-npc-funding.php
    php tests/release/reset-ci-database.php
    php tests/release/world-rating.php
    php tests/release/user-activity.php
    ;;
  *) exit 2 ;;
esac
