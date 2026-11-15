#!/bin/sh
set -e

# Rebuild the cache on every start so stale caches never survive a rebuild.
php bin/console cache:clear --env=prod --no-debug 2>/dev/null || true
php bin/console cache:warmup --env=prod --no-debug 2>/dev/null || true

exec apache2-foreground