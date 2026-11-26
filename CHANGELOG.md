# Changelog

## Unreleased

- Install `libzstd1` from Debian security without an exact revision, so a later security update is included in the next image build. The published `v0.1.0-beta.2` image stays on `1.5.7+dfsg-1+deb13u1`. The image scan still fails when a high or critical vulnerability has a fix.

## 0.1.0-beta.2 — 2026-11-26

Security update of the container. No new product behaviour. Real emission stays locked by default. Not a claim of regulatory compliance.

- Upgrade `libzstd1` from `1.5.7+dfsg-1` to `1.5.7+dfsg-1+deb13u1` (CVE-2026-124851). The pinned `php:8.4-apache` image of 24 November 2026 still contains the vulnerable package. Debian security supplies the corrected revision of the same upstream version. The `v0.1.0-beta.1` tag and its image are left as published.
- The image scan fails when a high or critical vulnerability has a published fix. Findings that have no fix are reported and are not silenced.
- A weekly GitHub Actions workflow audits Composer dependencies and the Docker image.

## 0.1.0-beta.1 — 2026-11-25

First installable beta. Real emission stays locked by default. Not a claim of regulatory compliance.

- Public documentation uses fictitious illustrations. It does not include captures from an account.

- Receive supplier invoices into Invoice Ninja (vendor, expense, original file, duplicate guard).
- Prepare Factur-X locally, including a preview that does not upload.
- Sandbox emission of invoices, credit notes, deposit invoices, B2C e-reporting, and the collected status.
- Invoice Ninja custom fields for the action, the regulatory status, the platform id, and the rejection reason.
- Retry after a technical failure, including recovery when the platform answers that the invoice already exists.
- Alerts in the journal and over standard SMTP. Mailtrap is optional.
- Emission is locked unless `EINVOICING_SEND_ENABLED=true`.
- VAT regime must be set explicitly (`EINVOICING_VAT_FRANCHISE`).
- A Factur-X or supplier original is attached once: a repeated sync, a retry, or a lost upload response does not add a second copy.
- A locked Invoice Ninja invoice is left unchanged. The regulatory status is written to its activity history.
- A line amount missing from the readable PDF blocks the transmission. A different spelling of the same amount does not. An amount printed only as a total, an amount due, or the 40 € recovery indemnity does not confirm a line. An unreadable PDF blocks the transmission and the reason is shown to the user.
- A locked Invoice Ninja invoice cannot receive custom fields: the API answers HTTP 422 `locked_invoice` for every update except marking it paid. The connector does not unlock the invoice and does not rewrite its amounts. It records the regulatory status as an activity note and tries the custom fields again on a later sync.
- The web process and the scheduler take a lock before migrating, so the first boot on SQLite does not collide.
- Static analysis runs at PHPStan level 5.
