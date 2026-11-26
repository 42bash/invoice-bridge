# Known limitations (0.1.0-beta.2)

Public list. No operator-specific data.

| # | Limitation | Severity for a beta | Blocking? |
| --- | --- | --- | --- |
| 1 | Real emission stays off until the operator sets `EINVOICING_SEND_ENABLED=true`. Sandbox emission is tested. | By design | No |
| 2 | One VAT exemption regime per installation. A PDF that quotes a different article is refused. | P1 to configure correctly; not a defect | No, if documented |
| 3 | E-reporting of purchases from abroad is not implemented. | P2 | No |
| 4 | Recurring invoices have no special path: each generated invoice is a normal invoice. | P3 | No |
| 5 | Mixed exemption codes on one invoice are not supported. | P2 | No |
| 6 | The dashboard binds to localhost. Publishing it is the operator's reverse proxy. | P3 | No |
| 7 | Alerts are e-mail (SMTP) and an optional webhook. No SMS. | P3 | No |
| 8 | A B2B customer absent from the directory blocks the invoice, with an explanation. | By design | No |
| 9 | Invoice Ninja's API refuses any update of a locked invoice except marking it paid (HTTP 422, `locked_invoice`). Custom fields cannot carry the regulatory status. Uploading a file and adding an activity note still work, so the connector writes the status there, leaves every amount untouched, and retries the fields on a later sync. It never unlocks the invoice. | P2, handled | No |
| 10 | SUPER PDP identity checks (KYC) and the API contract are contracted with SUPER PDP, not with this software. | Outside the product | No |
| 11 | A line amount that is only formatted differently (130,00 €, 130 €, 1 250.00) is accepted. Each line must have its own occurrence: a total, an amount due, or the 40 € recovery indemnity does not validate a line. If the PDF is unreadable or the comparison cannot be made, transmission is blocked and the reason is shown. | P3, handled | No |
| 12 | There is no custom Invoice Ninja button. Actions are native custom fields, so Invoice Ninja itself is not patched. | By design | No |

Historical invoices that do not match the configured VAT regime are never rewritten. They are not transmitted.
