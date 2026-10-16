# Waypoint WiPay for PrestaShop

An independent PrestaShop payment module that redirects shoppers to WiPay's hosted card checkout. It is maintained by Waypoint and is not affiliated with, sponsored by, or endorsed by WiPay.

## Project status

**Developed, not yet sandbox-tested.** Version 1.0.0 implements the full hosted-checkout flow for PrestaShop 8.x and 9.x. The request payload, response hash, endpoint map and availability guards are covered by an automated harness (91 assertions, all passing). The end-to-end checkout has **not** been run against WiPay sandbox or a live PrestaShop store — see [TESTING.md](TESTING.md) before treating this as releasable.

No legacy WiPay PrestaShop package was used. This module is a clean-room implementation written from WiPay's published Payments API documentation, so the license/provenance question raised by the legacy Magento and OpenCart packages never applied here.

## Safety design

- The amount is read from the cart on the server and pinned in the attempt ledger before the payment request is sent.
- The order is created up front in an **Awaiting WiPay payment** state, so every started attempt leaves a merchant-visible record even if the shopper abandons checkout.
- Payment completes only when all of these hold: the transaction exists in this module's attempt ledger, the order it belongs to is still awaiting WiPay, the order id matches the one that was sent, the pinned total still matches `total_paid`, the order is still in the awaiting state, and the documented hash verifies server-side.
- The hash is `md5(transaction_id + original_total + api_key)` where `original_total` is the pinned amount formatted to exactly two decimals. The customer-facing total returned on redirect is never used.
- Missing or malformed hashes leave the order unpaid. Repeated callbacks never complete an already-paid order twice.
- The checkout URL returned by WiPay must be `https` on a `wipayfinancial.com` host before the shopper is redirected to it.
- The live API key is never stored in module settings and never reaches browser code. It is read from a PHP constant defined server-side.
- Diagnostics are disabled by default and never record request bodies, customer details, API keys or full callback URLs.

## Installation

1. Install PrestaShop 8.x or 9.x with PHP 7.4+.
2. Copy `wipaywaypoint/` into `modules/` and enable it in the back office. The module creates an **Awaiting WiPay payment** order state and its attempt table on install.
3. Open Modules → WiPay Waypoint → Configure. Set Environment to **Sandbox**, choose the WiPay platform for your merchant account, and save.
4. Place sandbox orders covering an approved payment and a declined payment, following [TESTING.md](TESTING.md).

Sandbox uses WiPay's published test account `1234567890` and response-verification key `123`. Keep this release in Sandbox until your own live review is complete.

### Live key

When you are ready to test live, define the key server-side in `config/defines.inc.php`:

```php
define( 'WIPAYWAYPOINT_API_KEY', 'your-live-key' );
```

Never commit that file or disclose the key. Live mode is refused at checkout until both the constant and the live account number are present.

## Limits

- No automated refunds, chargeback webhooks, or transaction-retrieval reconciliation.
- Declined, cancelled and abandoned attempts remain in the awaiting order state; the merchant reconciles them against the attempt ledger and WiPay settlement.
- Stock is reserved when the order is created at checkout start, not when payment confirms.
- The TT/BB/JM/GY/GD host mapping is documented in `wipaywaypoint/wipaywaypoint.php`. All five live/sandbox pairs were re-confirmed against WiPay's Platforms and Environments page on 15 October 2026, and all ten hostnames were probed and returned HTTP 200 over HTTPS.

## Development

```bash
php -l wipaywaypoint/*.php
php tests/run-tests.php
```

The harness loads the module against small stand-ins for the PrestaShop APIs it touches, so it runs without a PrestaShop install. It proves the payload rules, hash verification, checkout-URL validation, endpoint map and availability guards — not the framework integration itself.

## License and disclaimer

GPL-2.0-or-later. Waypoint accepts no liability for use of this software. It is supplied without warranty. WiPay does not endorse this module.

## References

- [WiPay Payments API](https://docs.wipayfinancial.com/payments-api)
- [Payment Request](https://docs.wipayfinancial.com/payments-api/payment-request)
- [Transaction Response and hash verification](https://docs.wipayfinancial.com/payments-api/transaction-response)
- [Platforms and Environments](https://docs.wipayfinancial.com/platforms-and-environments)
- [PrestaShop payment module docs](https://devdocs.prestashop-project.org/9/modules/payment/)
