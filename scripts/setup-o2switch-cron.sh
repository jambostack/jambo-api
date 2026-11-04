#!/usr/bin/env bash
# ==============================================================================
# Jambo API — o2switch Scheduled Cron Jobs Configuration
# Installs or displays production crontab commands for maintenance and flows
# ==============================================================================

set -euo pipefail

APP_DIR="${JAMBO_REMOTE_DIR:-/home/gupi7723/api.jambostack.site}"
PHP_BIN="${PHP_BIN:-php}"

cat <<CRON_CONFIG
# ==============================================================================
# JAMBO API (api.jambostack.site) - PRODUCTION CRONTAB (o2switch)
# ==============================================================================

# 1. Automations & Scheduled Flow Triggers (Every minute)
* * * * * cd ${APP_DIR} && ${PHP_BIN} bin/console automation:run-scheduled --no-interaction >> var/log/cron-automations.log 2>&1

# 2. Scheduled Content Publishing (Every minute)
* * * * * cd ${APP_DIR} && ${PHP_BIN} bin/console app:publish-scheduled --no-interaction >> var/log/cron-publish.log 2>&1

# 3. Nightly Purge: Expired Security Tokens & Inactive EndUser Sessions (>30 days) (Daily at 03:00)
0 3 * * * cd ${APP_DIR} && ${PHP_BIN} bin/console app:purge-expired-tokens --session-days=30 --no-interaction >> var/log/cron-tokens.log 2>&1

# 4. Weekly Audit & Webhook Logs Retention Cleanup (Sunday at 03:30)
30 3 * * 0 cd ${APP_DIR} && ${PHP_BIN} bin/console app:purge-audit-logs --audit-days=90 --webhook-days=30 --email-days=30 --automation-days=30 --no-interaction >> var/log/cron-audit.log 2>&1

# 5. Weekly Media Cache Pruning (Sunday at 04:00)
0 4 * * 0 cd ${APP_DIR} && ${PHP_BIN} bin/console app:media:prune-cache --days=30 --no-interaction >> var/log/cron-media.log 2>&1

# ==============================================================================
CRON_CONFIG
