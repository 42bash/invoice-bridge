# Configuration

Tout ce qui identifie une installation est dans `.env`. Le dépôt n'en contient aucune valeur réelle.

| Sujet | Variables |
| --- | --- |
| Invoice Ninja | `INVOICE_NINJA_URL`, `INVOICE_NINJA_API_TOKEN` |
| SUPER PDP | `SUPERPDP_API_URL`, `SUPERPDP_CLIENT_ID`, `SUPERPDP_CLIENT_SECRET`, `SUPERPDP_ENVIRONMENT` |
| Société | `EINVOICING_SELLER_*` (vides : identité vérifiée par SUPER PDP, puis Invoice Ninja) |
| Régime de TVA | `EINVOICING_VAT_FRANCHISE` (`true` ou `false`, obligatoire) |
| Mentions | `EINVOICING_NOTE_PMT`, `EINVOICING_NOTE_PMD`, `EINVOICING_NOTE_AAB` |
| Actions Invoice Ninja | `EINVOICING_ACTION_SEND`, `EINVOICING_ACTION_SEND_DEPOSIT`, `EINVOICING_ACTION_SKIP`, `EINVOICING_ACTION_RETRY` |
| Alertes | `EINVOICING_NOTIFY_MAIL_TO` + `MAIL_MAILER=smtp` et `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS` |
| Interrupteurs | `EINVOICING_ENABLED`, `EINVOICING_SEND_ENABLED`, `EINVOICING_AUTO_SEND`, `EINVOICING_AUTO_RECEIVE`, `EINVOICING_REPORT_PAYMENTS` |

Le SMTP standard est le canal prévu. Une API HTTP de type Mailtrap (`EINVOICING_NOTIFY_MAILTRAP_*`) est facultative et n'est pas requise.

`einvoicing:setup-fields --apply` configure les listes et libellés Invoice Ninja par l'API. Sans droit administrateur, copiez les libellés affichés dans Paramètres › Champs personnalisés.

Ne mettez pas `lock_invoices` sur « when sent » : le connecteur ne pourrait plus écrire le statut. `einvoicing:check` le signale.
