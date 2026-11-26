# Architecture

Invoice Bridge est un service PHP (Laravel) à côté d'Invoice Ninja. Il ne modifie pas le code d'Invoice Ninja.

- **Connecteur Invoice Ninja** (`app/Services/InvoiceNinja`) : lecture des factures, avoirs et dépenses, écriture des champs, pièces jointes, notes.
- **Fournisseur SUPER PDP** (`app/Services/SuperPdp`) : OAuth, dépôt, statuts, annuaire, conversion et validation Factur-X.
- **Moteur** (`OutboundService`, `EnInvoiceMapper`, `PdfCoherence`) : données EN16931, contrôles PDF / XML, verrou d'émission.
- **Réception** (`InboundService`) : archive de l'original, fournisseur, dépense, anti-doublon.
- **Synchronisation** (`SyncService`, planificateur) : lecture périodique. Les lectures ne sont pas le fait générateur du tarif plateforme (facturation au document, selon la grille du fournisseur).
- **Alertes** (`Notifier`, `Supervisor`) : journal, SMTP, webhook optionnel.
- **Archive** : fichiers sous `storage`, hors Git, jamais purgés automatiquement.

Les identifiants, le SIREN, le régime fiscal et les adresses sont lus dans la configuration ou chez la plateforme. Ils ne sont pas compilés dans l'image.
