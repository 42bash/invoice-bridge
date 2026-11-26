# Configuration

Everything that identifies an installation lives in `.env`. The repository contains no real values.

| Topic | Variables |
| --- | --- |
| Invoice Ninja | `INVOICE_NINJA_URL`, `INVOICE_NINJA_API_TOKEN` |
| SUPER PDP | `SUPERPDP_API_URL`, `SUPERPDP_CLIENT_ID`, `SUPERPDP_CLIENT_SECRET`, `SUPERPDP_ENVIRONMENT` |
| Company | `EINVOICING_SELLER_*` (empty: identity verified by SUPER PDP, then Invoice Ninja) |
| VAT regime | `EINVOICING_VAT_FRANCHISE` (`true` or `false`, required) |
| Legal notes | `EINVOICING_NOTE_PMT`, `EINVOICING_NOTE_PMD`, `EINVOICING_NOTE_AAB` |
| Invoice Ninja actions | `EINVOICING_ACTION_SEND` and the deposit, skip, and retry variants |
| Alerts | `EINVOICING_NOTIFY_MAIL_TO` plus `MAIL_MAILER=smtp` and the usual `MAIL_*` variables |
| Switches | `EINVOICING_ENABLED`, `EINVOICING_SEND_ENABLED`, `EINVOICING_AUTO_SEND`, `EINVOICING_AUTO_RECEIVE`, `EINVOICING_REPORT_PAYMENTS` |

Standard SMTP is the supported mail channel. An optional HTTP API such as Mailtrap (`EINVOICING_NOTIFY_MAILTRAP_*`) is not required.

`einvoicing:setup-fields --apply` writes the Invoice Ninja labels through the API. Without an administrator token, copy the printed labels under Settings › Custom fields.

Do not set Invoice Ninja `lock_invoices` to “when sent”: the connector could no longer write the status. `einvoicing:check` warns about it.
