#!/usr/bin/env bash
# Prepares a Claude Code cloud session so it can run tests immediately.
# Local sessions are skipped: the owner manages their own environment.
set -euo pipefail

if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

cd "$CLAUDE_PROJECT_DIR"

for tool in php composer node npm; do
  if ! command -v "$tool" >/dev/null 2>&1; then
    echo "session-start: '$tool' is not installed in this cloud environment." >&2
    echo "Add it to the environment's setup script (see docs/SETUP.md)." >&2
    exit 1
  fi
done

composer install --no-interaction --prefer-dist --no-progress
npm ci --no-audit --no-fund

[ -f .env ] || cp .env.example .env
grep -q '^APP_KEY=base64:' .env || php artisan key:generate --no-interaction
[ -f database/database.sqlite ] || touch database/database.sqlite
php artisan migrate --no-interaction --force

# Feature tests render views that need the Vite manifest.
npm run build

echo "session-start: dependencies installed, database migrated, assets built."
