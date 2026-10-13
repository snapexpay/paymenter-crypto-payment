# Paymenter Crypto Payment Gateway: USDT TRC20 via SnapEX Pay

[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![Paymenter](https://img.shields.io/badge/Paymenter-v1.x-blue.svg)](https://github.com/Paymenter/Paymenter)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777bb4.svg)](https://www.php.net/)
[![USDT](https://img.shields.io/badge/USDT-TRC20%20%7C%20ERC20%20%7C%20BEP20-26a17b.svg)](https://pay.snapex.pro)

[Русская версия](README.ru.md)

A gateway extension for [Paymenter](https://github.com/Paymenter/Paymenter) that lets clients pay invoices in **USDT (TRC20, ERC20, BEP20)** and other coins through [SnapEX Pay](https://snapex.pro). When a client clicks *Pay*, the extension creates a SnapEX Pay invoice and sends the client to the hosted payment page. Once the payment is confirmed, a signed webhook marks the Paymenter invoice as paid. You don't have to run a wallet, a node or a blockchain watcher. Funds go to your SnapEX Pay merchant balance.

> **Connecting to SnapEX Pay?** Create a merchant account, check the pricing and see every ready-made module on **[pay.snapex.pro](https://pay.snapex.pro)**.

## Features

- A native Paymenter v1 gateway extension (`Paymenter\Extensions\Gateways\SnapexPay`) that shows up under **Admin → Gateways**.
- A hosted SnapEX Pay checkout for USDT on TRC20, ERC20 and BEP20, plus other coins offered on the payment page.
- Webhooks are verified with HMAC-SHA256 (`Webhook-Signature`), compared in constant time, and rejected if the timestamp is more than 5 minutes off.
- The webhook body is never trusted on its own. The invoice is read back from the SnapEX API with your key before any payment is recorded.
- Payments are recorded once. A repeated or retried webhook doesn't add a second transaction or change the first one.
- The amount credited in Paymenter is the exact amount that was billed, in the invoice currency.
- Several Paymenter sites can share one SnapEX account. Each site only settles its own invoices.
- Test mode creates test invoices you can confirm without real money. Test payments are ignored while the gateway is set to live.
- Fixed USD conversion rate for shops that don't bill in USD.

## How it works

1. A client opens an unpaid invoice and picks **SnapEX Pay**.
2. The extension calls `POST /api/v1/merchant/invoices` with the amount in USD, an `Idempotency-Key` and an `external_id` that identifies this site and invoice. Then it redirects the client to the returned `pay_url`.
3. The client pays on the SnapEX Pay page and comes back to the invoice page. Paymenter checks the payment status automatically (`?checkPayment=true`).
4. SnapEX Pay sends `invoice.paid` to `https://<your-paymenter>/extensions/snapexpay/webhook`.
5. The extension checks the signature, fetches `GET /api/v1/merchant/invoices/{id}`, and only calls `ExtensionHelper::addPayment()` if the status is `paid`. The SnapEX invoice id (`INV-…`) is stored as the transaction id.

A double click within the same minute reuses the same SnapEX invoice. If the client tries again later, for example after the payment window has expired, a new SnapEX invoice is created.

## Requirements

- Paymenter v1.x (PHP 8.3+)
- A [SnapEX Pay](https://pay.snapex.pro) merchant account
- A Paymenter site reachable over HTTPS, so the webhook can be delivered

## Installation

1. Copy the contents of the archive into your Paymenter installation so that the class file ends up at:

   ```
   extensions/Gateways/SnapexPay/SnapexPay.php
   extensions/Gateways/SnapexPay/routes.php
   ```

   The folder must be named `SnapexPay`, exactly matching the class name. For example:

   ```bash
   cd /var/www/paymenter
   curl -fsSLO https://pay.snapex.pro/downloads/paymenter/snapexpay-paymenter-1.1.0.zip && unzip snapexpay-paymenter-1.1.0.zip
   mv snapexpay-paymenter-1.1.0 extensions/Gateways/SnapexPay
   chown -R www-data:www-data extensions/Gateways/SnapexPay   # your web server user
   ```

   With the Docker image, copy the folder to `/app/extensions/Gateways/SnapexPay` (the `./extensions` volume).

2. If the gateway doesn't show up, run `composer dump-autoload -o` and `php artisan optimize:clear`.
3. In Paymenter go to **Admin → Gateways → New**, pick **SnapEX Pay** and fill in the settings below.

## Configuration

| Field (key) | Default | Description |
|---|---|---|
| API key (`api_key`) | — | Required. From the SnapEX Pay cabinet → API keys, with scopes `invoices.read` and `invoices.write`. |
| Webhook secret (`webhook_secret`) | — | Required. The `whsec_…` secret shown when you create the webhook endpoint. Unsigned or wrongly signed webhooks are rejected. |
| Network fee paid by (`fee_on`) | `merchant` | `merchant`: the client pays the invoice amount. `customer`: the network fee is added on top. |
| Payment window, minutes (`expires_minutes`) | `60` | 1–1440. How long the SnapEX invoice stays payable. |
| USD per 1 invoice currency (`usd_rate`) | `1` | Leave `1` if Paymenter bills in USD. For EUR at 1.08 USD, enter `1.08`. SnapEX invoices are always in USD. |
| Environment (`environment`) | `live` | `live` or `test`. Test invoices can be confirmed without real money. |
| Partner ID (`partner_id`) | empty | Optional. The Partner ID of the integrator who set this up (SnapEX Pay referral program). Leave empty if you set it up yourself. |
| API URL (`api_url`) | `https://snapex.pro/api/v1/merchant` | Leave as is. |

## Webhook setup

In the SnapEX Pay cabinet, add a webhook endpoint:

```
https://your-paymenter.example/extensions/snapexpay/webhook
```

Subscribe it to the `invoice.*` events (at least `invoice.paid`) and paste the `whsec_…` secret into the gateway settings. The route is a server-to-server POST, so it doesn't use CSRF or sessions. The signature authenticates it.

## Testing

- **Without money:** set *Environment* to `test`, pay an invoice and confirm the test payment on the SnapEX Pay page. The invoice should switch to *Paid*, with one transaction whose id is `INV-…`.
- **Unit checks:** `php tests/run.php` (PHP 8.3, no dependencies). This covers the Paymenter extension contract, signature verification and replay window, the shape of the API request, idempotent webhooks, test/live separation and multi-site isolation, using a mocked SnapEX API.

## FAQ / Troubleshooting

**The gateway isn't listed in Admin → Gateways.**
Check that the path is `extensions/Gateways/SnapexPay/SnapexPay.php` (case-sensitive) and that the files are readable by the web server. Then run `composer dump-autoload -o && php artisan optimize:clear`.

**The client paid but the invoice is still unpaid.**
Check the webhook deliveries in the SnapEX Pay cabinet. A `400 invalid signature` response means the secret doesn't match, or the server clock is more than 5 minutes off. A `not_paid` response means SnapEX hasn't confirmed the payment yet, and the webhook will arrive again when it does. `ignored_test_payment` means a test invoice was paid while the gateway is set to `live`.

**The client paid less than the invoice amount.**
`partially_paid` invoices aren't credited automatically. Check the payment in the SnapEX cabinet and add the transaction in Paymenter by hand.

**"Amount is below the … USD minimum".**
SnapEX Pay sets a minimum invoice amount. The error comes straight from the API.

**I changed `APP_URL`.**
Each site's invoices are tagged with a hash of `APP_URL`. Invoices created before the change won't settle automatically, so add those payments by hand.

**Do I need my own wallet or node?**
No. SnapEX Pay watches the blockchain and credits your merchant balance.

## Other SnapEX Pay integrations

All modules, pricing and sign-up: **[pay.snapex.pro](https://pay.snapex.pro)**.

- [Marzban](https://pay.snapex.pro/integrations/marzban)
- [3x-ui](https://pay.snapex.pro/integrations/3x-ui)
- [Xboard / V2Board](https://pay.snapex.pro/integrations/xboard)
- [Remnawave](https://pay.snapex.pro/integrations/remnawave)
- [Telegram VPN bot](https://pay.snapex.pro/integrations/telegram-vpn-bot)
- [FOSSBilling](https://pay.snapex.pro/integrations/fossbilling)
- [n8n](https://pay.snapex.pro/integrations/n8n)

## Support

- Website, cabinet and API docs: https://snapex.pro
- Bugs and feature requests: write to us in the chat on [pay.snapex.pro](https://pay.snapex.pro).

## License

[MIT](LICENSE)
