# Mise à jour

Déployez un tag, pas la branche `main` en continu. Le tag publié est `v0.1.0-beta.2`.

1. Noter le tag actuel (`VERSION`).
2. Sauvegarder le volume (base SQLite et `storage/app`).
3. Conserver le `.env` (ne pas l'écraser).
4. `git fetch && git checkout vX.Y.Z`.
5. `docker compose build && docker compose up -d`.
6. Les migrations partent au démarrage si `EINVOICING_RUN_MIGRATIONS=true`.
7. `einvoicing:check`. Vérifier qu'une facture déjà reçue n'est pas réimportée.
8. Retour arrière : `git checkout` du tag précédent, reconstruire, redémarrer. Ne pas restaurer une base plus récente sur un code plus ancien si une migration a été appliquée : restaurer aussi la sauvegarde de la base.

`v0.1.0-beta.2` remplace `libzstd1` 1.5.7+dfsg-1 par 1.5.7+dfsg-1+deb13u1 (CVE-2026-124851). `v0.1.0-beta.1` et son image restent publiés sous leur propre tag.
