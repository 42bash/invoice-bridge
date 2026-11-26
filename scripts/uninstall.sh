#!/bin/sh
# Stop Invoice Bridge. Volumes (archive, SQLite) are kept unless --purge.
set -eu
cd "$(dirname "$0")/.."

if [ "${1:-}" = "--purge" ]; then
    echo "This deletes the database volume and the archived invoices of THIS compose project."
    docker compose down -v
else
    docker compose down
    echo "Containers stopped. Data volume kept. Re-run with --purge to delete it."
fi
