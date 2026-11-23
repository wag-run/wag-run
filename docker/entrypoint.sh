#!/bin/sh
set -eu

cd /var/www/html

if [ "$(id -u)" = "0" ]; then
    echo "wag.run does not run as root. Remove any 'user: root' override (the image runs as 82:82)." >&2
    exit 1
fi

# A database server must have a password. SQLite is a file and has none.
if [ "${DB_CONNECTION:-sqlite}" != "sqlite" ] && [ -z "${DB_PASSWORD:-}" ] && [ -z "${DB_URL:-}" ]; then
    echo "DB_PASSWORD is not set. Generate one and add it to your environment:" >&2
    echo "  echo \"DB_PASSWORD=\$(openssl rand -hex 24)\"" >&2
    exit 1
fi

# A new or empty volume needs Laravel's storage layout.
mkdir -p \
    storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    /tmp/cache \
    /tmp/nginx

# Without an APP_KEY in the environment, generate one on first run and keep it
# in the storage volume so later starts reuse it.
KEY_FILE=storage/app.key
if [ -z "${APP_KEY:-}" ]; then
    if [ ! -s "$KEY_FILE" ]; then
        (umask 077 && php -r 'echo "base64:".base64_encode(random_bytes(32));' > "$KEY_FILE")
        echo "Generated an APP_KEY and saved it in the storage volume ($KEY_FILE). Back it up." >&2
    fi
    APP_KEY="$(cat "$KEY_FILE")"
    export APP_KEY
fi

if [ "${DB_CONNECTION:-}" = "sqlite" ] && [ ! -e "$DB_DATABASE" ]; then
    mkdir -p "$(dirname "$DB_DATABASE")"
    touch "$DB_DATABASE"
fi

# One-off commands, such as `docker compose run --rm app php artisan tinker`,
# skip the migrations and caches below.
if [ "${1:-}" != "supervisord" ]; then
    exec "$@"
fi

echo "Starting wag.run ${VERSION:-dev}"

attempts=30
until php artisan migrate --force --no-interaction; do
    attempts=$((attempts - 1))
    if [ "$attempts" -le 0 ]; then
        echo "Migrations still failing after a minute. Is the database reachable?" >&2
        exit 1
    fi
    echo "Database not ready, retrying in 2 seconds..." >&2
    sleep 2
done

php artisan optimize --no-interaction

exec "$@"
