# Mise à jour

Déployez un tag, pas la branche `main` en continu.

1. Noter le tag actuel (`VERSION`).
2. Sauvegarder le volume (base SQLite et `storage/app`).
3. Conserver le `.env` (ne pas l'écraser).
4. `git fetch && git checkout vX.Y.Z`.
5. `docker compose build && docker compose up -d`.
6. Les migrations partent au démarrage si `EINVOICING_RUN_MIGRATIONS=true`.
7. `einvoicing:check`. Vérifier qu'une facture déjà reçue n'est pas réimportée.
8. Retour arrière : `git checkout` du tag précédent, reconstruire, redémarrer. Ne pas restaurer une base plus récente sur un code plus ancien si une migration a été appliquée : restaurer aussi la sauvegarde de la base.
