#!/bin/bash

set -e

if [ ! -f .env ]; then
    echo "[start] .env not found — copying from .env.example"
    cp .env.example .env
fi

# Load .env (strip CRLF for Windows compatibility)
set -a
# shellcheck source=.env
source <(sed 's/\r//' .env)
set +a

docker compose up -d

echo "[start] waiting for Meilisearch to be ready..."
until curl -s "${MEILISEARCH_HOST:-http://localhost:7700}/health" | grep -q '"status":"available"' 2>/dev/null; do
    sleep 1
done
echo "[start] Meilisearch is ready."

docker compose exec app bash
