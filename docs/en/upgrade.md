# Upgrade

Deploy a tag. Do not track `main` automatically.

1. Note the current tag (`VERSION`).
2. Back up the volume (SQLite database and `storage/app`).
3. Keep `.env` (do not overwrite it).
4. `git fetch && git checkout vX.Y.Z`.
5. `docker compose build && docker compose up -d`.
6. Migrations run on boot when `EINVOICING_RUN_MIGRATIONS=true`.
7. `einvoicing:check`. Confirm an already imported invoice is not imported again.
8. Rollback: check out the previous tag, rebuild, restart. If a migration ran, restore the database backup as well.
