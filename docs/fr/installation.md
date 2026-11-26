# Installation

1. Cloner `42bash/invoice-bridge`.
2. `cp .env.example .env && chmod 600 .env`.
3. Renseigner au minimum `APP_KEY` (`php artisan key:generate --show` dans le conteneur, ou avant le premier démarrage), `INVOICE_NINJA_URL`, `INVOICE_NINJA_API_TOKEN`, `SUPERPDP_CLIENT_ID`, `SUPERPDP_CLIENT_SECRET`, `SUPERPDP_ENVIRONMENT=sandbox`, `EINVOICING_DASHBOARD_PASSWORD_HASH`.
4. `./scripts/install.sh` construit l'image et démarre l'application plus le planificateur.
5. La base SQLite est créée dans le volume Docker au premier démarrage (`EINVOICING_RUN_MIGRATIONS=true`).
6. `docker compose exec invoice-bridge php artisan einvoicing:setup-fields --apply` écrit les champs personnalisés si le jeton est administrateur. Sinon, la commande affiche les libellés à copier.
7. `docker compose exec invoice-bridge php artisan einvoicing:check`.

Bac à sable : deux applications SUPER PDP (émetteur et partenaire), `SUPERPDP_ENVIRONMENT=sandbox`, `SUPERPDP_SANDBOX_BUYER_ADDRESS` et `SUPERPDP_PEER_*`. `einvoicing:recette` n'est autorisée que si `EINVOICING_TEST_INSTANCE=true`.

Réception contrôlée : `EINVOICING_ENABLED=true`, `EINVOICING_AUTO_RECEIVE=true`, `EINVOICING_SEND_ENABLED=false`, puis recréer les conteneurs et relancer `einvoicing:check`.

Émission : la préparer avec `einvoicing:preview NUMERO` (aucune transmission). Ne passer `EINVOICING_SEND_ENABLED=true` qu'après votre propre validation.

Le tableau de bord écoute `127.0.0.1:8098` par défaut (`INVOICE_BRIDGE_PORT`). Placez un reverse proxy HTTPS devant lui si vous l'ouvrez au-delà de la machine.
