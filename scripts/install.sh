#!/bin/sh
# Install Invoice Bridge from a checkout of this repository.
# Does not read any file outside the checkout.
set -eu
cd "$(dirname "$0")/.."

if ! command -v docker >/dev/null 2>&1; then
    echo "Docker is required." >&2
    exit 1
fi

if [ ! -f .env ]; then
    cp .env.example .env
    chmod 600 .env
    echo "Created .env from .env.example. Edit it, then run this script again."
    exit 0
fi

docker compose build
docker compose up -d
echo "Waiting for the application..."
sleep 5
docker compose exec -T invoice-bridge php artisan einvoicing:check || true
echo
echo "Next: fill Invoice Ninja and SUPER PDP credentials in .env, then"
echo "  docker compose exec invoice-bridge php artisan einvoicing:setup-fields --apply"
echo "  docker compose exec invoice-bridge php artisan einvoicing:check"
echo "Reception stays off until EINVOICING_ENABLED=true and EINVOICING_AUTO_RECEIVE=true."
echo "Emission stays off until you set EINVOICING_SEND_ENABLED=true yourself."
