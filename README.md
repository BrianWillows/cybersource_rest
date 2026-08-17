# Cybersource REST (Microform)

> **Canonical home: [drupal.org/project/cybersource_rest](https://www.drupal.org/project/cybersource_rest).**
> The GitHub repository is a read-only mirror. Please file bugs and feature
> requests in the [drupal.org issue queue](https://www.drupal.org/project/issues/cybersource_rest)
> and send merge requests to the drupal.org GitLab repository, not to GitHub.

A Drupal Commerce payment gateway for Cybersource using the **REST API** and
**Flex Microform v2**. The card number and CVV are entered into
Cybersource-hosted Microform iframes — they never touch this server — and the
resulting single-use *transient token* is charged server-side via the
Cybersource REST API.

It is the REST sibling of the Secure Acceptance (SOP) gateway and shares its
polished card UI (live brand detection, accepted-brand enforcement, tick/cross
validation).

## Why this module

Compared with putting Cybersource credentials in the gateway configuration
(where they end up in config exports and git), this module keeps the
**merchant id / keyId / shared secret out of Drupal config entirely**,
resolved at runtime by mode from `settings.php`. The REST SDK is wrapped
behind a small, mockable service so the gateway is unit/kernel-testable.

## Requirements

- Drupal Commerce (`commerce_payment`, `commerce_order`, `commerce_price`,
  `commerce_log`).
- `cybersource/rest-client-php` (pulled in via Composer).

## Why credentials come from settings.php

Cybersource credentials are **never** stored in Drupal configuration (and so
are never written to the database or exported to `config`/git), and there is
**no admin UI** for them: an administrator who could read the shared secret
could sign their own API calls, and one who could replace it could redirect
funds. Reading or changing the credentials always requires deployment
(filesystem) access.

Instead, the credentials are provided by `settings.php`, checked in this
order:

1. `$settings['cybersource_rest.credentials']` — the credentials array
   itself. Because `settings.php` is PHP, you can populate this from
   whatever secret store your host provides: a platform secrets API (e.g.
   Pantheon Customer Secrets), `getenv()`, or an `include` of a file kept
   out of version control. The secrets need never touch the site's
   filesystem. **Never write literal key values into a settings.php that is
   committed to version control.**
2. `$settings['cybersource_rest.credentials_file']` — the absolute path of a
   YAML file (see `cybersource_rest.credentials.example.yml`). Put it
   **outside** the web root and outside the Drupal public/private files
   directories, so it is not reachable through the web server, Drupal's file
   APIs, stream wrappers, or file download hooks; make it readable by the
   web server user only (e.g. `chmod 640`).

Whichever source is used, one block per mode: a `test` gateway uses the
`test` block (`apitest.cybersource.com`); a `live` gateway uses the `live`
block (`api.cybersource.com`). One merchant account serves every currency,
so — unlike Secure Acceptance — there is no per-currency profile.

## Setup

1. **Generate REST credentials** in the Cybersource Business Center:
   *Payment Configuration » Key Management » REST APIs » Generate Key »
   "Shared Secret"*. You get a **keyId (Serial Number)** and a **Shared Secret**.
   Note your **merchant id**.

2. Copy `cybersource_rest.credentials.example.yml` to a directory **outside**
   the web root (and outside the Drupal public/private files directories),
   fill in real values, and make it readable by the web server user only. In
   `settings.php`, point the module at it:

   ```php
   $settings['cybersource_rest.credentials_file'] = '/path/outside/webroot/cybersource_rest.yml';
   ```

   On hosts where you cannot write outside the web root, prefer the inline
   form fed from the host's secret store instead (same structure as the
   example file), e.g.:

   ```php
   // Pantheon Customer Secrets, environment variables, or a non-VCS include.
   $settings['cybersource_rest.credentials'] = json_decode(pantheon_get_secret('cybersource_rest'), TRUE);
   ```

   The YAML/array structure itself is unchanged:

   ```yaml
   test:
     merchant_id: your_test_merchant_id
     key_id: 00000000-0000-0000-0000-000000000000
     shared_secret: base64SharedSecret==
   live:
     merchant_id: your_live_merchant_id
     key_id: 00000000-0000-0000-0000-000000000000
     shared_secret: base64SharedSecret==
   ```

3. **Add the gateway**: *Commerce » Configuration » Payment gateways » Add*,
   choose **Cybersource (REST Microform)**, pick the mode (test → the `test`
   block + `apitest.cybersource.com`; live → the `live` block +
   `api.cybersource.com`) and the transaction type (authorize, or sale). The
   gateway panel reports whether the credentials are present and which modes
   they cover.

4. Check *Reports » Status report* for the module's runtime checks.

Optionally, if you rotate your REST API keys on a schedule, add a `key_expiry`
(a `YYYY-MM-DD` date) to each block in the credentials. The status report
then warns a month before that date and shows an error once it has passed, so a
key rotation never silently breaks payments:

```yaml
test:
  merchant_id: your_test_merchant_id
  key_id: 00000000-0000-0000-0000-000000000000
  shared_secret: base64SharedSecret==
  key_expiry: "2027-01-01"   # optional
```

## PCI scope

Because the PAN/CVV are entered into Cybersource-hosted iframes, this integration
typically qualifies for a **reduced PCI scope (SAQ A / A-EP depending on your
acquirer)**. A checkout **Content-Security-Policy** is still required. Confirm
your exact scope with your acquirer or QSA.

## 3-D Secure / Payer Authentication (SCA)

Tick **Enable 3-D Secure (Payer Authentication)** on the gateway to
authenticate the cardholder before every charge — required for Strong Customer
Authentication (UK/EEA PSD2), and it shifts fraud liability to the issuer for
authenticated transactions.

How it works (EMV 3DS 2.x via Cybersource Payer Authentication, which has
Cardinal Commerce built in — no separate Cardinal account is needed):

1. After the card is tokenised, the checkout JS asks this site to run the
   payer-auth **setup** and then performs Cardinal **device data collection**
   in a hidden iframe.
2. The site runs the **enrollment check** server-side (amount, currency and
   billing come from the order, never from the browser).
   - **Frictionless** outcome: the authentication data (CAVV/ECI) is kept
     server-side and carried straight into the authorization.
   - **Challenge** outcome: the issuer's challenge opens in a modal iframe;
     after the customer completes it, the authorization validates the result
     server-to-server (`VALIDATE_CONSUMER_AUTHENTICATION`).
   - **Unavailable**: the payment proceeds without a liability shift (standard
     scheme behaviour); the outcome is recorded in the order's activity log.
   - **Failed**: the payment is refused.
3. **Fail closed**: with 3-D Secure enabled, a payment request that skipped or
   tampered with the authentication steps is refused before any charge is
   attempted. The authentication result is single-use and session-bound.

**Prerequisites:** Payer Authentication must be enabled ("boarded") on your
Cybersource merchant account (Business Center; raise a ticket with Cybersource
support if `/risk/v1/authentication-setups` returns a configuration error).
Card payments will fail while 3-D Secure is ticked without it — test against
the sandbox first.

### Testing 3-D Secure in the Cybersource sandbox

Test scenarios are selected by **card number** (any CVV; expiry = January of
the current year + 3). With a test-mode gateway and 3-D Secure enabled:

| Scenario | Visa | Mastercard | Expected result |
|---|---|---|---|
| Frictionless success | `4000000000002701` | `5200000000002235` | `AUTHENTICATION_SUCCESSFUL`, `paresStatus=Y`, CAVV issued; payment authorized with liability shift |
| Frictionless failed | `4000000000002925` | `5200000000002276` | `AUTHENTICATION_FAILED`, `paresStatus=N`; checkout shows "your bank could not authenticate", no charge |
| Authentication unavailable | `4000000000002313` | `5200000000002268` | `paresStatus=U`, no CAVV; payment proceeds **without liability shift** (logged) |
| Challenge, then success | `4000000000002503` | `5200000000002151` | `PENDING_AUTHENTICATION`, `paresStatus=C`; the challenge modal opens, complete it, payment authorized |
| Challenge, then failed | `4000000000002370` | `5200000000002490` | Challenge completes but validation fails; no charge |

The enrollment outcome for every attempt is written to the order's activity
log (`3DS enrollment: status=… paresStatus=… veresEnrolled=…`), so each
scenario can be verified from the order page.

Exemption testing (PSD2 TRA / low-value etc.): Cybersource exposes SCA
exemption flags under `consumerAuthenticationInformation.strongAuthentication`
in the authorization API — send at most ONE exemption per transaction or
Cybersource rejects it. This module does not yet request exemptions; see the
Cybersource "Payer Authentication" guide, "Additional Test Cases", if you need
them (e.g. TRA/low-value Visa test card `4000000000002024` with
`challengeCode=05`).

An end-to-end Playwright test for the frictionless flow ships in
`tests/playwright/payer-auth-checkout.spec.ts` (run against a dev site with
sample products, a test-mode gateway with 3-D Secure enabled, and Payer
Authentication boarded on the sandbox account).

## Scope / roadmap

This first version mirrors the Secure Acceptance gateway: a single payment per
checkout (authorize, or authorize + capture), plus capture/void/refund of the
resulting transaction.

**Not yet implemented:**

- **Saving cards** via Token Management (TMS) — each checkout uses a single-use
  transient token only.
- **Timeout reconciliation / idempotency** — if a payment request gets no
  response (network timeout), the charge *may* still have been created at
  Cybersource. The single-use token prevents a same-token double charge, but the
  uncertain outcome is logged to the order's audit log for manual reconciliation
  in the Business Center rather than auto-recovered (a timeout-void / status
  query flow is a planned hardening item).


## Licence

GPL-2.0-or-later, the same licence as Drupal itself.
