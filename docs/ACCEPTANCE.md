# Acceptance summary (anonymised)

This is the public summary. Internal reports that name a real company are not part of this repository.

## Automated tests

84 tests, 289 assertions, PHPUnit, HTTP mocked. No live credential is used.

## Sandbox, fictitious companies only

Fifteen scenarios, all passing on the last run before this beta:

- environment check, B2B send, idempotent re-send;
- linked credit note (type 381) and deposit invoice (type 386);
- invoice blocked when the customer has no SIREN;
- supplier invoice received, original archived, expense created, not marked paid;
- duplicate reception refused;
- customer rejection with a reason copied back to Invoice Ninja;
- network cut: the cursor does not move, and an interrupted upload is found again by its external id;
- single “collected” status even if the response was lost;
- B2C e-reporting of the sale and of the payment;
- retry from Invoice Ninja after the customer identifier is fixed.

The pictures in `docs/img/` are illustrations drawn with fictitious companies. They are not captures of an account, and they are not production documents.

## Production

On the reference installation, reception is switched on and emission is locked. No real invoice was transmitted for this beta. A local preview of one draft was consistent; historical invoices that quote a different VAT article are refused and were not modified. Those documents are not included here.
