# CreatorFeed Payment Gateway for PNLCS

Accept **UPI, cards and netbanking** on your [PNLCS (Panelica)](https://github.com/Panelica/pnlcs) invoices through
[CreatorFeed](https://creatorfeed.in). Every payment is confirmed with CreatorFeed's own API before an invoice is
marked paid.

> ### 🧩 Install modules from your admin, with no SSH: [PNLCS Module Manager](https://github.com/shiwang-biz/pnlcs-module-manager)
> Our free, open-source addon for PNLCS lets you **install, update, back up, restore and delete** any module
> (gateways, server modules, registrars, SSL, addons) by uploading a `.zip` in the admin area.
> It checks every package before installing, backs up the old version, runs the module's database migrations for you
> and rolls back automatically if something fails.
> **[⬇ Download Module Manager](https://github.com/shiwang-biz/pnlcs-module-manager/raw/main/dist/ModuleManager-pnlcs-addon.zip)** · [Read more](https://github.com/shiwang-biz/pnlcs-module-manager)

**[⬇ Download CreatorFeed-pnlcs-module.zip](https://github.com/shiwang-biz/pnlcs-creatorfeed-gateway/raw/main/dist/CreatorFeed-pnlcs-module.zip)**

---

## Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
  - [Option 1: Module Manager (recommended)](#option-1-module-manager-recommended)
  - [Option 2: Manual](#option-2-manual)
- [Configuration](#configuration)
- [Test it](#test-it)
- [Going live](#going-live)
- [Settings reference](#settings-reference)
- [How a payment is confirmed](#how-a-payment-is-confirmed)
- [Check status buttons](#check-status-buttons)
- [Updating](#updating)
- [Troubleshooting](#troubleshooting)
- [Not included yet](#not-included-yet)
- [Files](#files)

---

## Features

- **Pay with CreatorFeed** button on unpaid INR invoices: UPI, cards and netbanking on CreatorFeed's checkout.
- **Signed webhooks.** Every webhook's HMAC-SHA256 signature is verified, webhooks older than 5 minutes are
  rejected, and repeated deliveries are ignored.
- **100% confirmation.** Whatever a webhook or return link says, the module asks the CreatorFeed API
  (`GET /v1/orders/{id}`) and marks the invoice paid only if CreatorFeed reports it paid, for that invoice and in INR.
- **Paid instantly on return.** When the customer comes back from checkout, the same API check runs, so the
  invoice usually shows paid before the webhook even arrives.
- **Check status buttons** for customers (invoice page) and staff (invoice page panel and an orders page).
- **CreatorFeed Orders page** for staff: every order with status, UTR, fee and net amount, with filters and a lookup.
- **Never paid twice.** Retries, repeated webhooks and simultaneous checks record one payment.
- **Self-healing.** If CreatorFeed's API can't be reached during a webhook, the module asks CreatorFeed to retry later.
- **Test mode** with CreatorFeed `cf_test_` keys, and a "Test mode" notice for customers while one is set.
- Everything is logged to **Admin → Logs → Gateway**.

## Requirements

- PNLCS (Panelica) 1.27 or newer
- A CreatorFeed account with API access: a `cf_test_…` key to start, then a `cf_live_…` key after KYC
- Invoices in **INR** (CreatorFeed accepts rupees only)
- Clients need a 10-digit Indian mobile number on their profile, or set a fallback number in the settings

---

## Installation

Pick **one** of the two options, then continue with [Configuration](#configuration).

### Option 1: Module Manager (recommended)

No file manager, no SSH, no `php artisan`: the database tables are created for you.

1. **Install the Module Manager** (one time only, skip if you already have it).
   Download [ModuleManager-pnlcs-addon.zip](https://github.com/shiwang-biz/pnlcs-module-manager/raw/main/dist/ModuleManager-pnlcs-addon.zip),
   extract it into `modules/Addons/` on your server and activate it under **Extensions**.
   [Full instructions](https://github.com/shiwang-biz/pnlcs-module-manager#one-time-install-the-only-manual-step)
2. Download **[CreatorFeed-pnlcs-module.zip](https://github.com/shiwang-biz/pnlcs-creatorfeed-gateway/raw/main/dist/CreatorFeed-pnlcs-module.zip)**.
3. In the admin, open **Extensions → Module Manager**.
4. Choose the zip and click **Upload & review**.
5. Check the review screen: it should say *Payment gateway · CreatorFeed · installs to `modules/Gateways/CreatorFeed`
   · Has migrations*. Enter your admin password and click **Install**.
6. Done. You should see *"Installed CreatorFeed 1.0.0"*. Continue with [Configuration](#configuration).

### Option 2: Manual

#### a) Upload the files

Using your server's **file manager** or **SFTP** (FileZilla, WinSCP):

1. Download **[CreatorFeed-pnlcs-module.zip](https://github.com/shiwang-biz/pnlcs-creatorfeed-gateway/raw/main/dist/CreatorFeed-pnlcs-module.zip)**.
2. Open `modules/Gateways/` in your PNLCS folder (usually `/var/www/pnlcs/modules/Gateways/`).
3. Upload the zip there and **Extract** it.
4. Check the result is exactly `modules/Gateways/CreatorFeed/pnlcs.json`, with no extra folder in between.

Or over **SSH**:

```bash
cd /var/www/pnlcs
wget -O /tmp/creatorfeed.zip https://github.com/shiwang-biz/pnlcs-creatorfeed-gateway/raw/main/dist/CreatorFeed-pnlcs-module.zip
unzip /tmp/creatorfeed.zip -d modules/Gateways/
chown -R www-data:www-data modules/Gateways/CreatorFeed
```

#### b) Create the database tables

This needs SSH, once:

```bash
cd /var/www/pnlcs
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan optimize:clear
```

You should see `create_creatorfeed_tables ... DONE`.

#### c) Activate the module

**Setup → Modules**: **CreatorFeed** is listed as *Third-party*. Activate it. Then continue with [Configuration](#configuration).

---

## Configuration

Both installation options end here.

### 1. Gateway settings in PNLCS

**Setup → Payment Gateways → CreatorFeed**:

| Field | What to enter |
|---|---|
| API Base URL | `https://api-staging.creatorfeed.in/v1` for testing |
| Secret API Key | Your `cf_test_…` key from CreatorFeed → **API keys & webhooks** |
| Webhook Signing Secret | Add after step 2 below |

Leave the other fields as they are for now, tick **Active** and save.

### 2. Webhook in CreatorFeed

In the **CreatorFeed dashboard → API keys & webhooks → Add webhook**:

- **URL:** `https://YOUR-PANEL/gateway/creatorfeed/callback`
- **Events:** `payment.success` and `payment.failed`

Copy the webhook's signing secret (`whsec_…`) into **Webhook Signing Secret** in PNLCS and save again.

> CreatorFeed only appears on invoices once **every required field** (base URL, API key, webhook secret) is filled in.

## Test it

1. Make sure a test client has a 10-digit Indian mobile number on their profile.
2. Create an **INR** invoice for that client and open it from the client area. You'll see
   **Pay ₹… with CreatorFeed** and a *Test mode* notice.
3. Click it. On CreatorFeed's practice checkout, click **Pay**.
4. You return to the invoice, which shows **Paid**.
5. Staff can see the order at `/admin/creatorfeed/orders`. Every step is in **Admin → Logs → Gateway**.

> Payments made with a `cf_test_` key **do mark invoices paid** (that's how you test the whole flow).
> Switch to a live key before taking real orders.

## Going live

1. Once your CreatorFeed KYC is approved, create a `cf_live_…` key.
2. In **Setup → Payment Gateways → CreatorFeed**, set the **live API base URL** CreatorFeed gives you
   and the live key.
3. Make sure the CreatorFeed webhook is set up for live mode, and update the signing secret if it differs.
4. Make one small real payment to confirm.

---

## Settings reference

| Setting | Notes |
|---|---|
| API Base URL | Staging: `https://api-staging.creatorfeed.in/v1`. Use the live base URL CreatorFeed gives you for production. Include `/v1`. |
| Secret API Key | `cf_test_…` = practice checkout with Pay / Fail buttons. `cf_live_…` = real money (KYC must be approved). |
| Send API key as | `Authorization: Bearer` (default) or `X-Api-Key`. |
| Webhook Signing Secret | `whsec_…` of the CreatorFeed webhook. Required. |
| Order ID Prefix | Order ids are `<prefix>-<invoice id>-<8 random chars>`, within CreatorFeed's 64-character limit. Default `PNL`. |
| Description shown to buyer | Placeholders: `{invoice_num}`, `{invoice_id}`, `{company}`. Default `Invoice #{invoice_num}`. |
| Fallback mobile number | Used only when the client has no valid Indian mobile. If you leave it blank, the client is asked to add one. |
| API timeout | Seconds per API call (5 to 60). Default 15. |

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

If the API can't be reached while a webhook is being handled, the module answers `503`, and CreatorFeed retries
(1 min, 5 min, 30 min, … up to 24 h). An unpaid order's payment link is reused for 30 minutes, so clicking Pay twice
doesn't create duplicate orders.

## Check status buttons

| Where | What it does |
|---|---|
| **Client invoice page** | **Already paid? Check payment status**: appears once a CreatorFeed order exists for the invoice. |
| **Admin invoice page** | A small **CreatorFeed** panel (bottom right) listing that invoice's orders, with **Check status via API**. |
| **`/admin/creatorfeed/orders`** | Every order, with status, UTR, fee and net amount, and a **Check status** button per row. Filter by invoice, status or id. **Check any order on CreatorFeed** looks up any `order_id` or `CFD-TXN-…` id. Orders that aren't linked to an invoice are only shown, never recorded. |

Each check calls `GET /v1/orders/{id}` and records the payment if CreatorFeed reports it paid.

## Updating

- **Module Manager:** upload the new zip. The review screen shows `1.0.0 → 1.x.x`. The current version is backed up
  first and put back automatically if anything fails.
- **Manual:** replace the `modules/Gateways/CreatorFeed/` folder with the new one, then run
  `php artisan migrate --force` and `php artisan optimize:clear`.

Your settings and order history are kept either way.

## Troubleshooting

| Problem | Fix |
|---|---|
| CreatorFeed isn't under **Setup → Modules** | The folder must be exactly `modules/Gateways/CreatorFeed` (capital C and F). Run `php artisan optimize:clear`. |
| CreatorFeed doesn't appear on invoices | Fill in every required setting (base URL, API key, webhook secret), tick **Active**, and make sure the invoice is in INR. |
| "A 10-digit Indian mobile number is needed" | Add the number to the client's profile, or set **Fallback mobile number**. |
| Paid on CreatorFeed but the invoice is still unpaid | Click **Check status** (invoice page or orders page). Check **Admin → Logs → Gateway** and CreatorFeed's webhook delivery log. |
| Webhook rejected: invalid signature | The **Webhook Signing Secret** must be the `whsec_…` of *this* webhook URL. Also check your server's clock is correct. |
| `Table 'creatorfeed_orders' doesn't exist` | Manual install: run `php artisan migrate --force`. |

## Not included yet

- **Refunds.** Refund from the CreatorFeed dashboard for now. The API supports refunds
  (`POST /v1/orders/{id}/refunds`) if you want them added later.
- Payouts, balance, disputes and audience managers.
- Saved cards and automatic charging (CreatorFeed has no card saving).

## Files

```
CreatorFeed/
├── pnlcs.json                      manifest (name "creatorfeed", service provider)
├── CreatorFeedModule.php           gateway: settings, payment form, webhook, signature check
├── CreatorFeedPayments.php         create order / confirm status / record payment
├── CreatorFeedApi.php              HTTP client (auth header, timeouts, retry on 429/5xx)
├── CreatorFeedController.php       pay, return, client + admin status checks, orders page
├── CreatorFeedOrder.php            order record (invoice ↔ CreatorFeed order)
├── CreatorFeedWebhookEvent.php     handled webhook event ids
├── CreatorFeedServiceProvider.php  routes, views, migrations
├── routes.php  hooks.php
├── views/admin/                    orders page, invoice panel
├── migrations/                     creatorfeed_orders, creatorfeed_webhook_events
└── tests/CreatorFeedGatewayTest.php  copy to tests/Feature/ in a PNLCS checkout to run
dist/CreatorFeed-pnlcs-module.zip   ready-to-install package
```

---

Not affiliated with Panelica or CreatorFeed.
