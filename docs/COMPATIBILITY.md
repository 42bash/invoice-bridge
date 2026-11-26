# Invoice Ninja compatibility

| Invoice Ninja | API | Result |
| --- | --- | --- |
| 5.14.0 | `/api/v1` | Tested (sandbox end-to-end and the reference install) |
| 5.13.x | `/api/v1` | Not certified for this beta. The same API paths exist; re-run `einvoicing:check` and the sandbox suite before relying on it. |
| 5.12 and older | — | Not supported |
| Hosted Invoice Ninja e-invoice (Peppol / Storecove) | — | Leave it disabled. This bridge replaces that path for SUPER PDP. |

The admin UI may be Flutter or React. The connector talks to the HTTP API only.

SUPER PDP API: `v1.beta` as published on `https://api.superpdp.tech` in November 2026. The beta prefix can change; pin an upgrade to a new Invoice Bridge tag when it does.
