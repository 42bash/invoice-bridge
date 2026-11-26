# Déployer une version identifiée

La clé de déploiement est en lecture seule. Elle peut cloner et récupérer. Elle ne peut pas pousser.
La production est mise à jour depuis un commit ou un tag déjà testé, jamais en suivant `main` automatiquement.
Ce dépôt ne se déploie pas tout seul.

## Préparation du serveur

```sh
# La clé privée reste sur le serveur, mode 600. La clé publique est une deploy key GitHub en lecture seule.
git clone git@github-invoice-bridge:42bash/invoice-bridge.git
cd invoice-bridge
git checkout <commit>        # un commit dont l'intégration continue est verte. Ne pas suivre main.
cp .env.example .env
chmod 600 .env
```

Renseigner `.env` avec le [guide de configuration](configuration.md). Puis :

```sh
docker compose build
docker compose up -d
docker compose exec invoice-bridge php artisan einvoicing:check
docker compose exec invoice-bridge php artisan einvoicing:setup-fields --apply
```

Laisser `EINVOICING_SEND_ENABLED=false` tant que l'opérateur n'a pas décidé d'émettre réellement.

## Mise à jour

```sh
git fetch --tags origin
git checkout <tag-ou-commit>
docker compose build
docker compose up -d
docker compose exec invoice-bridge php artisan einvoicing:check
```

Le volume nommé conserve la base et les archives. En prendre une copie avant une mise à jour qui contient une migration. Pour revenir en arrière, reposer le commit précédent et restaurer la copie du volume si une migration a tourné.

## Ce que cette procédure ne fait pas

Aucun commit sur `main` ne déclenche un déploiement.
La clé en lecture seule n'autorise pas à pousser.
L'installation déjà en service chez un opérateur n'est pas basculée par ces commandes.
