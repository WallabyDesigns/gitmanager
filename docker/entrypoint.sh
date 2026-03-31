#!/bin/sh
set -e

cd /var/www/html

if [ ! -f .env ]; then
  cp .env.example .env
fi

get_env_from_file() {
  key="$1"
  if [ -f .env ]; then
    value=$(grep -m 1 "^${key}=" .env | cut -d= -f2-)
    value="${value#\"}"
    value="${value%\"}"
    echo "$value"
  fi
}

# Ensure required directories exist
mkdir -p storage/app storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

# Ensure sqlite database exists when using sqlite
if [ -z "${DB_CONNECTION}" ]; then
  DB_CONNECTION=$(get_env_from_file "DB_CONNECTION")
fi
if [ -z "${DB_DATABASE}" ]; then
  DB_DATABASE=$(get_env_from_file "DB_DATABASE")
fi

if [ "${DB_CONNECTION}" = "sqlite" ] || [ -z "${DB_CONNECTION}" ]; then
  if [ -z "${DB_DATABASE}" ]; then
    DB_PATH="/var/www/html/database/database.sqlite"
  else
    DB_PATH="${DB_DATABASE}"
  fi
  mkdir -p "$(dirname "$DB_PATH")"
  if [ ! -f "$DB_PATH" ]; then
    touch "$DB_PATH"
  fi
fi

# Fix permissions for writable paths
chown -R www-data:www-data storage bootstrap/cache database || true

# Generate or reuse a shared app key if missing and not provided via env
KEY_FILE="storage/app/.app_key"
LOCK_DIR="storage/app/.app_key.lock"
if [ -z "${APP_KEY}" ]; then
  APP_KEY_VALUE=$(grep -m 1 "^APP_KEY=" .env | cut -d= -f2-)
  if [ -n "$APP_KEY_VALUE" ]; then
    APP_KEY="$APP_KEY_VALUE"
    echo "$APP_KEY" > "$KEY_FILE"
    export APP_KEY
  else
    if [ ! -s "$KEY_FILE" ]; then
      if mkdir "$LOCK_DIR" 2>/dev/null; then
        if [ ! -s "$KEY_FILE" ]; then
          APP_KEY=$(php artisan key:generate --show)
          echo "$APP_KEY" > "$KEY_FILE"
        fi
        rmdir "$LOCK_DIR"
      else
        while [ ! -s "$KEY_FILE" ]; do
          sleep 0.2
        done
      fi
    fi
    APP_KEY=$(cat "$KEY_FILE")
    export APP_KEY
  fi
fi

# Optionally run migrations (only for app role)
ROLE=${CONTAINER_ROLE:-app}
if [ "$ROLE" = "app" ] && { [ "$RUN_MIGRATIONS" = "true" ] || [ "$RUN_MIGRATIONS" = "1" ]; }; then
  php artisan migrate --force
fi

# Ensure public storage link exists
if [ ! -L public/storage ]; then
  php artisan storage:link || true
fi

exec "$@"
