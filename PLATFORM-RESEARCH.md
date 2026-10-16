# Platform research: PrestaShop

## Status
**Implemented as a clean-room module.** `wipaywaypoint/` targets PrestaShop 8.x and 9.x and implements the full hosted-redirect flow. No pre-existing WiPay PrestaShop package was located or derived from, so the license/reuse question raised by the legacy Magento and OpenCart packages does not arise here. Sandbox verification remains outstanding — see TESTING.md.

## Feasibility
Resolved. PrestaShop payment modules add a checkout option and handle the provider return, which maps cleanly onto WiPay hosted checkout: the module creates the request server-side, redirects the shopper, and verifies the response hash against a server-pinned amount before changing order state.

## Decisions taken
- **Supported versions:** PrestaShop 8.x and 9.x, PHP 7.4+.
- **Order lifecycle:** the order is created up front in a custom *Awaiting WiPay payment* state so abandoned attempts stay merchant-visible, then moved to *Payment accepted* only after verification.
- **Duplicate returns:** the attempt ledger records the transaction id; a replay of a completed payment is idempotent, and a replay after the order has been moved out of the awaiting state is rejected.
- **Hash rules:** `md5(transaction_id + original_total + api_key)` with the pinned amount at two decimals, per the Transaction Response docs.
- **Live secret:** read from a `WIPAYWAYPOINT_API_KEY` PHP constant, never from module settings.

## Remaining questions
- ~~Re-confirm the JM/BB/GY/GD hostnames against the current Platforms and Environments page; only the TT pair is published there.~~ **Closed 15 October 2026:** all five live/sandbox pairs (BB, GD, GY, JM, TT) match WiPay's Platforms and Environments page, and all ten hostnames returned HTTP 200 when probed over HTTPS.
- ~~Confirm the TTD 5.00 minimum, which was carried over from the sandbox-tested WordPress gateway rather than re-read from current docs.~~ **Closed 15 October 2026:** WiPay's Get Transaction Metadata endpoint returns `totals.TTD` as `{"minimum": 5, "maximum": 20400}` (USD is `{"minimum": 0.74, "maximum": 3000}`). The guard is implemented and asserted by the harness.
- Voucher/credit methods and webhooks are out of scope for 1.0.0.

## References
- [WiPay Payments API](https://docs.wipayfinancial.com/payments-api)
- [PrestaShop payment module docs](https://devdocs.prestashop-project.org/9/modules/payment/)
- [WiPay Plugin Integration Guides](https://wipaycaribbean.com/solutions/plugins)
