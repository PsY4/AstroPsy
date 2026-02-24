#!/bin/bash
set -e

# Warm up cache on first start (empty volume mount)
if [ ! -d /app/var/cache/prod ]; then
    echo "First start detected — warming up Symfony cache..."
    php bin/console cache:warmup --env=prod
fi

exec "$@"
