# CreatorFeed payment gateway for PNLCS (Panelica)

> Private repository. Ready-to-install package: [`dist/CreatorFeed-pnlcs-module.zip`](dist/CreatorFeed-pnlcs-module.zip).
> With the [Module Manager](https://github.com/shiwang-biz/pnlcs-module-manager) addon, upload that zip at
> **Extensions → Module Manager**; no file manager or `php artisan migrate` needed.

Customers pay invoices through CreatorFeed (UPI, cards, netbanking). An invoice
is only marked paid after **CreatorFeed's own API** reports the order as `paid`.
A webhook or a return URL can't mark it paid on its own.

## Install

**With the Module Manager:** upload `dist/CreatorFeed-pnlcs-module.zip`, review, confirm. Its tables are created for you. Then continue at step 3.

**By hand:**

1. Copy the `CreatorFeed/` folder to `modules/Gateways/CreatorFeed/` in your PNLCS installation.
2. Create the module's two tables:
   ```bash
   php artisan migrate --force
   php artisan optimize      # only if you cache routes
   ```
3. **Setup → Modules**: CreatorFeed appears marked *Third-party*. Activate it.
4. **Setup → Payment Gateways → CreatorFeed**: fill in the settings below and switch it on.
5. **CreatorFeed dashboard → API keys & webhooks**: add a webhook:
   - URL: `https://YOUR-PANEL/gateway/creatorfeed/callback`
   - Events: `payment.success`, `payment.failed`
   - Copy its signing secret (`whsec_…`) into the gateway settings.

## Settings

| Setting | Notes |
|---|---|
| API Base URL | Staging: `https://api-staging.creatorfeed.in/v1`. Use the live base URL CreatorFeed gives you for production. Include `/v1`. |
| Secret API Key | `cf_test_…` = practice checkout with Pay / Fail buttons. `cf_live_…` = real money (KYC must be approved). |
| Send API key as | `Authorization: Bearer` (default) or `X-Api-Key`. |
| Webhook Signing Secret | `whsec_…` of the webhook above. Required. |
| Order ID Prefix | Order ids are `<prefix>-<invoice id>-<8 random chars>`, which stays inside CreatorFeed's 64-character limit. |
| Description shown to buyer | `{invoice_num}`, `{invoice_id}`, `{company}` placeholders. |
| Fallback mobile number | CreatorFeed requires a 10-digit Indian mobile on every order. Used only when the client has none. If you leave it blank, a client without a valid number is asked to add one. |
| API timeout | Seconds per API call (5 to 60). |

> **Test mode marks invoices paid too.** Practice payments made with a
> `cf_test_` key go through the whole flow, invoice included, so you can test
> it end to end. Switch to a `cf_live_` key before taking real orders. The
> customer's payment box shows a "Test mode" notice while a test key is set.

## How a payment is confirmed

```
Customer ─ Pay ─▶ /creatorfeed/pay/{invoice} ─ POST /v1/orders ─▶ redirect to payment_url
                                                                        │
            ┌──────────────── buyer pays on CreatorFeed ◀───────────────┘
            ▼
 webhook  /gateway/creatorfeed/callback        return  /creatorfeed/return/{invoice}
   1. verify HMAC-SHA256 signature ("t.body")     (query string ignored)
   2. reject if older than 5 minutes
   3. ignore repeated CreatorFeed-Event-Id
            └──────────────┬───────────────┘
                           ▼
          GET /v1/orders/{order_id}   ← the only source of truth
          • order_id and notes.pnlcs_invoice_id must match our record
          • status must be "paid", currency INR
                           ▼
          PaymentService::applyPayment(invoice, 'creatorfeed', CFD-TXN-…, amount)
          (a repeated transaction id is ignored, so nothing is paid twice)
```

If the API can't be reached while a webhook is being handled, the module
answers `503`. CreatorFeed then retries (1 min, 5 min, 30 min, … up to 24 h).

## Check status buttons

| Where | What it does |
|---|---|
| Client invoice page | **Already paid? Check payment status**: appears after a CreatorFeed order exists for the invoice. |
| Admin invoice page | A small **CreatorFeed** panel (bottom right) listing that invoice's orders, with **Check status via API**. |
| `Admin → /admin/creatorfeed/orders` | Every order, with status, UTR, fee/net and a **Check status** button per row. Filter by invoice, status or id. **Check any order on CreatorFeed** looks up any `order_id` / `CFD-TXN-…` id. Orders that aren't linked to an invoice are only shown, never recorded. |

Each check calls `GET /v1/orders/{id}` and records the payment if CreatorFeed reports it paid.
Every API call and webhook outcome is written to **Admin → Logs → Gateway** (gateway `creatorfeed`).

## Not included (yet)

- Refunds: `refund()` returns a "refund from the CreatorFeed dashboard" message. The API supports
  `POST /v1/orders/{id}/refunds` if you want this added later.
- Payouts, balance, disputes and audience managers.
- Saved cards / automatic charging (CreatorFeed has no tokenisation).

## Files

```
pnlcs.json                     manifest (name "creatorfeed", service provider)
CreatorFeedModule.php          gateway interface: settings, payment form, webhook, signature check
CreatorFeedPayments.php        create order / confirm status / record payment
CreatorFeedApi.php             HTTP client (auth header, timeouts, retry on 429/5xx)
CreatorFeedController.php      pay, return, client + admin status checks, admin orders page
CreatorFeedOrder.php           order record (invoice ↔ CreatorFeed order)
CreatorFeedWebhookEvent.php    handled webhook event ids
CreatorFeedServiceProvider.php routes, views, migrations
routes.php  hooks.php  views/  migrations/
tests/CreatorFeedGatewayTest.php   copy to tests/Feature/ to run with the PNLCS suite
```
