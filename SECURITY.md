# Security policy

## Supported version

Security fixes are prepared for the current beta line, `0.1.x`. There is no long-term support commitment yet.

## Reporting a vulnerability

Write to contact@42bash.com. Please do not open a public issue containing tokens, invoices, tax identifiers, or unsanitised logs. You will receive an acknowledgement. If the report is confirmed, a fix is prepared before any public description.

## Operator expectations

- Keep `.env` out of Git and mode 600.
- Use HTTPS for Invoice Ninja and SUPER PDP.
- Set `EINVOICING_DASHBOARD_PASSWORD_HASH`. The dashboard returns HTTP 503 when it is empty and throttles failed logins.
- Do not enable `EINVOICING_SEND_ENABLED` until you have reviewed a local preview.
- The API token should be limited to what the connector needs. Writing custom fields requires an administrator token only for `setup-fields --apply`.
- Webhooks require the `X-Einvoicing-Secret` header.

## What this repository must never contain

Real names used as customer data, SIREN, addresses, invoice files, API secrets, SMTP passwords, and infrastructure hostnames. Examples and screenshots use fictitious sandbox companies.
