# Cybersource REST (Microform)

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
**merchant id / keyId / shared secret in a private file** outside the docroot,
resolved at runtime by mode. The REST SDK is wrapped behind a small, mockable
service so the gateway is unit/kernel-testable.

## Requirements

- Drupal Commerce (`commerce_payment`, `commerce_order`, `commerce_price`,
  `commerce_log`).
- `cybersource/rest-client-php` (pulled in via Composer).
- A configured **private filesystem** (`$settings['file_private_path']`).

## Setup

1. **Generate REST credentials** in the Cybersource Business Center:
   *Payment Configuration » Key Management » REST APIs » Generate Key »
   "Shared Secret"*. You get a **keyId (Serial Number)** and a **Shared Secret**.
   Note your **merchant id**.

2. **Create the credentials file** at `private://keys/cybersource_rest.yml`
   (i.e. `<file_private_path>/keys/cybersource_rest.yml`), readable by the web
   user only (e.g. `chmod 640`). Copy `cybersource_rest.credentials.example.yml` as a
   starting point:

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
   `api.cybersource.com`) and the transaction type (authorize, or sale).

4. Check *Reports » Status report* for the module's runtime checks.

Optionally, if you rotate your REST API keys on a schedule, add a `key_expiry`
(a `YYYY-MM-DD` date) to each block in the credentials file. The status report
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

## Scope / roadmap

This first version mirrors the Secure Acceptance gateway: a single payment per
checkout (authorize, or authorize + capture), plus capture/void/refund of the
resulting transaction.

**Not yet implemented:**

- **3-D Secure / Payer Authentication (SCA)** — the priority next addition.
  Until it is available and approved by your acquirer, this gateway should not be
  used for live UK/EEA card payments.
- **Saving cards** via Token Management (TMS) — each checkout uses a single-use
  transient token only.
- **Timeout reconciliation / idempotency** — if a payment request gets no
  response (network timeout), the charge *may* still have been created at
  Cybersource. The single-use token prevents a same-token double charge, but the
  uncertain outcome is logged to the order's audit log for manual reconciliation
  in the Business Center rather than auto-recovered (a timeout-void / status
  query flow is a planned hardening item).
