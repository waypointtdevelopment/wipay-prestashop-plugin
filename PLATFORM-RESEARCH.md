# Platform research: PrestaShop

## Status
**Implemented as a clean-room module.** `wipaywaypoint/` targets PrestaShop 8.x and 9.x and implements the full hosted-redirect flow. No pre-existing WiPay PrestaShop package was located or derived from, so the license/reuse question that still blocks the Magento and OpenCart repositories does not apply here. Sandbox verification remains outstanding — see TESTING.md.

## Feasibility
Resolved. PrestaShop payment modules add a checkout option and handle the provider return, which maps cleanly onto WiPay hosted checkout: the module creates the request server-side, redirects the shopper, and verifies the response hash against a server-pinned amount before changing order state.

## Decisions taken
- **Supported versions:** PrestaShop 8.x and 9.x, PHP 7.4+.
- **Order lifecycle:** the order is created up front in a custom *Awaiting WiPay payment* state so abandoned attempts stay merchant-visible, then moved to *Payment accepted* only after verification.
- **Duplicate returns:** the attempt ledger records the transaction id; a replay of a completed payment is idempotent, and a replay after the order has been moved out of the awaiting state is rejected.
- **Hash rules:** `md5(transaction_id + original_total + api_key)` with the pinned amount at two decimals, per the Transaction Response docs.
- **Live secret:** read from a `WIPAYWAYPOINT_API_KEY` PHP constant, never from module settings.

## Remaining questions
- Re-confirm the JM/BB/GY/GD hostnames against the current Platforms and Environments page; only the TT pair is published there.
- Confirm the TTD 5.00 minimum, which was carried over from the sandbox-tested WordPress gateway rather than re-read from current docs.
- Voucher/credit methods and webhooks are out of scope for 1.0.0.

## References
- [WiPay Payments API](https://docs.wipayfinancial.com/payments-api)
- [PrestaShop payment module docs](https://devdocs.prestashop-project.org/9/modules/payment/)
- [WiPay Plugin Integration Guides](https://wipaycaribbean.com/solutions/plugins)
