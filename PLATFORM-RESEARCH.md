# Platform research: PrestaShop

## Status
Research workspace only. No current WiPay PrestaShop package or support matrix has been verified.

## Feasibility
PrestaShop payment modules add a checkout option and handle the provider return. WiPay hosted checkout is a plausible fit if requests and response verification stay server-side and the verified amount is matched to the order.

## Next decisions
- Select supported PrestaShop major versions and PHP versions.
- Ask WiPay whether a maintained package exists and confirm license/reuse rights.
- Confirm sandbox fields, territory endpoints, hash rules, voucher availability, return handling, status mapping, and duplicate-return behavior.

## References
- [WiPay Payments API](https://docs.wipayfinancial.com/payments-api)
- [PrestaShop payment module docs](https://devdocs.prestashop-project.org/9/modules/payment/)
- [WiPay Plugin Integration Guides](https://wipaycaribbean.com/solutions/plugins)
