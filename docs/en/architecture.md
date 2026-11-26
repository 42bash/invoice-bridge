# Architecture

Invoice Bridge is a PHP (Laravel) service next to Invoice Ninja. It does not modify Invoice Ninja’s source code.

- **Invoice Ninja connector** (`app/Services/InvoiceNinja`): read invoices, credits, and expenses; write fields, attachments, and notes.
- **SUPER PDP provider** (`app/Services/SuperPdp`): OAuth, upload, statuses, directory, Factur-X conversion and validation.
- **Engine** (`OutboundService`, `EnInvoiceMapper`, `PdfCoherence`): EN16931 data, PDF / XML checks, emission lock.
- **Reception** (`InboundService`): original archive, vendor, expense, duplicate prevention.
- **Synchronisation** (`SyncService`, scheduler): periodic reads. Reads are not the billable event (the platform prices per document).
- **Alerts** (`Notifier`, `Supervisor`): journal, SMTP, optional webhook.
- **Archive**: files under `storage`, outside Git, never purged automatically.

Credentials, the SIREN, the tax regime, and addresses come from configuration or from the platform. They are not compiled into the image.
