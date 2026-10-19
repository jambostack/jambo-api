#!/usr/bin/env bash
# ==============================================================================
# Jambo API — Production Deployment Script
# Zero-downtime, safe migration, asset build & cache warmup protocol
# ==============================================================================

set -euo pipefail

# Configuration
APP_DIR="${APP_DIR:-$(pwd)}"
RELEASE_TAG="${1:-}"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
NODE_BIN="${NODE_BIN:-npm}"

echo "=========================================================="
echo " Starting Jambo API Deployment"
echo " Target Directory: ${APP_DIR}"
echo " Timestamp:        $(date -u +"%Y-%m-%dT%H:%M:%SZ")"
echo "=========================================================="

cd "${APP_DIR}"

# 1. Environment & Prerequisite Checks
echo "--> [1/6] Checking system prerequisites..."
command -v "${PHP_BIN}" >/dev/null 2>&1 || { echo "ERROR: PHP binary not found"; exit 1; }
command -v "${COMPOSER_BIN}" >/dev/null 2>&1 || { echo "ERROR: Composer binary not found"; exit 1; }
command -v "${NODE_BIN}" >/dev/null 2>&1 || { echo "ERROR: npm binary not found"; exit 1; }

if [ ! -f ".env.local" ] && [ ! -f ".env" ]; then
    echo "ERROR: Neither .env nor .env.local found. Aborting deployment."
    exit 1
fi

PREV_COMMIT=$(git rev-parse HEAD)

rollback() {
    echo "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!"
    echo " Deployment FAILED! Initiating rollback to ${PREV_COMMIT}..."
    echo "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!"
    git checkout "${PREV_COMMIT}"
    "${PHP_BIN}" bin/console cache:clear --no-warmup || true
    "${PHP_BIN}" bin/console cache:warmup --env=prod || true
    exit 1
}

trap rollback ERR

# 2. Synchronize Source Code
echo "--> [2/6] Synchronizing repository..."
git fetch --all --tags --prune
if [ -n "${RELEASE_TAG}" ]; then
    echo "Checking out release tag: ${RELEASE_TAG}"
    git checkout "${RELEASE_TAG}"
else
    echo "Pulling latest main branch..."
    git pull origin main
fi

# 3. Install Production PHP Dependencies
echo "--> [3/6] Installing Composer production dependencies..."
"${COMPOSER_BIN}" install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts

# 4. Build Frontend Assets
echo "--> [4/6] Compiling production frontend assets..."
"${NODE_BIN}" ci --prefer-offline
"${NODE_BIN}" run build

# 5. Database Migrations
echo "--> [5/6] Executing database migrations..."
"${PHP_BIN}" bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing

# 6. Cache Warmup & Permissions
echo "--> [6/6] Purging and warming up Symfony production cache..."
"${PHP_BIN}" bin/console cache:clear --no-warmup
"${PHP_BIN}" bin/console cache:warmup --env=prod

# Ensure write permissions for runtime directories
mkdir -p var/cache var/log public/uploads/media/cache
chmod -R 775 var public/uploads || true

# Optional: Reload PHP-FPM if running under systemd
if command -v systemctl >/dev/null 2>&1 && systemctl is-active --quiet php8.4-fpm; then
    echo "Reloading php8.4-fpm service..."
    sudo systemctl reload php8.4-fpm || true
fi

echo "=========================================================="
echo " Jambo API Deployment completed successfully!"
echo " Current Version: $(git describe --tags --always)"
echo "=========================================================="
