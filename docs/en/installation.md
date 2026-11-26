# Installation

1. Clone `42bash/invoice-bridge`.
2. `cp .env.example .env && chmod 600 .env`.
3. Set at least `APP_KEY`, `INVOICE_NINJA_URL`, `INVOICE_NINJA_API_TOKEN`, `SUPERPDP_CLIENT_ID`, `SUPERPDP_CLIENT_SECRET`, `SUPERPDP_ENVIRONMENT=sandbox`, and `EINVOICING_DASHBOARD_PASSWORD_HASH`.
4. `./scripts/install.sh` builds the image and starts the app and the scheduler.
5. SQLite is created in the Docker volume on first boot (`EINVOICING_RUN_MIGRATIONS=true`).
6. `docker compose exec invoice-bridge php artisan einvoicing:setup-fields --apply` writes Invoice Ninja custom fields when the token is an administrator. Otherwise it prints the labels to copy.
7. `docker compose exec invoice-bridge php artisan einvoicing:check`.

Sandbox: two SUPER PDP apps, `SUPERPDP_ENVIRONMENT=sandbox`, `SUPERPDP_SANDBOX_BUYER_ADDRESS`, and `SUPERPDP_PEER_*`. `einvoicing:recette` runs only when `EINVOICING_TEST_INSTANCE=true`.

Controlled reception: `EINVOICING_ENABLED=true`, `EINVOICING_AUTO_RECEIVE=true`, `EINVOICING_SEND_ENABLED=false`, recreate the containers, run `einvoicing:check` again.

Emission: rehearse with `einvoicing:preview NUMBER` (nothing is sent). Set `EINVOICING_SEND_ENABLED=true` only after your own validation.

The dashboard binds to `127.0.0.1:8098` (`INVOICE_BRIDGE_PORT`). Put an HTTPS reverse proxy in front of it before exposing it.
