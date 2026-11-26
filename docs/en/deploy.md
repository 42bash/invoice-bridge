# Deploy a chosen version

The deployment key is read-only. It can clone and fetch. It cannot push.
Production is updated from a commit or a tag that has already been tested, never by following `main` automatically.
This repository does not deploy itself.

## One-time server setup

```sh
git clone https://github.com/42bash/invoice-bridge.git
cd invoice-bridge
git checkout v0.1.0-beta.2
cp .env.example .env
chmod 600 .env
```

A read-only deploy key is enough to clone this public repository. The private key stays on the server, mode 600. It is not used to push.

Fill `.env` from [configuration.md](configuration.md). Then:

```sh
docker compose build
docker compose up -d
docker compose exec invoice-bridge php artisan einvoicing:check
docker compose exec invoice-bridge php artisan einvoicing:setup-fields --apply
```

Leave `EINVOICING_SEND_ENABLED=false` until the operator decides to emit for real.

## Update

```sh
git fetch --tags origin
git checkout <tag-or-commit>
docker compose build
docker compose up -d
docker compose exec invoice-bridge php artisan einvoicing:check
```

The named volume keeps the database and the archives. Take a copy of that volume before an update that includes a migration. Roll back by checking out the previous commit and restoring the volume copy if a migration ran.

## What this does not do

There is no automatic deployment from a commit on `main`.
The read-only key is not a licence to push.
The current operator installation is not switched over by these commands.
