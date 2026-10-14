# Staging test plan — WiPay Waypoint for PrestaShop

The automated harness covers the payload, hash, endpoint map and guards. It does **not** exercise PrestaShop's framework integration or WiPay itself. Run every step below on a staging store before release.

**Store setup:** PrestaShop 8.x or 9.x, HTTPS enabled, friendly URLs on and off (test both), one physical product with stock, guest checkout enabled for one pass and a customer account for another.

**Keep Environment set to Sandbox.** Do not test live until this checklist passes in full.

## 1. Installation

| # | Step | Expected |
|---|---|---|
| 1.1 | Upload `wipaywaypoint/` and enable the module | No errors; module card shows WiPay Waypoint |
| 1.2 | Check `Order > Statuses` | A new **Awaiting WiPay payment** state exists |
| 1.3 | Check the database | Table `wipaywaypoint_attempt` exists with the documented columns |
| 1.4 | Disable then re-enable the module | No duplicate order state, no table errors |

## 2. Configuration

| # | Step | Expected |
|---|---|---|
| 2.1 | Save settings with Environment=Sandbox, platform=TT | "Settings updated." |
| 2.2 | Set an obviously invalid platform value and save | Falls back to TT; never persists a bogus code |
| 2.3 | Leave Diagnostics off, complete an order | No rows written to the PrestaShop logger |
| 2.4 | Turn Diagnostics on | Events appear; **no** API keys, card data, customer emails or full callback URLs |

## 3. Checkout — sandbox happy path

| # | Step | Expected |
|---|---|---|
| 3.1 | Add product to cart, reach payment step | **Credit card (WiPay)** is listed with the redirect description |
| 3.2 | Select it and confirm | Order created as *Awaiting WiPay*; browser lands on a `wipayfinancial.com` checkout page over **https** |
| 3.3 | Check `wipaywaypoint_attempt` | One row: correct pinned total to 2dp, `status=started`, environment `sandbox` |
| 3.4 | Pay with `4242424242424242`, future expiry, CVV `123` | Redirect returns to the store's order-confirmation page |
| 3.5 | Check the order | State **Payment accepted**, transaction id recorded, totals unchanged |
| 3.6 | Check the attempt row | `status=paid`, `hash_rejected=0` |
| 3.7 | Reload the confirmation URL | Still shows a completed order — never a second completion |

## 4. Checkout — declined

| # | Step | Expected |
|---|---|---|
| 4.1 | New cart, pay with `4000000000000002` | A clear "payment did not complete" page, **not** a success page |
| 4.2 | Check the order | Still **Awaiting WiPay**, never marked paid |
| 4.3 | Check the attempt row | `status=failed` |
| 4.4 | Retry payment from checkout | A new attempt is possible; the previous attempt row is untouched |

Use the TTD-specific test cards from WiPay's testing docs when the platform is TT in TTD — the USD card numbers above are for USD/other currencies.

## 5. Verification guards — these must fail closed

| # | Step | Expected |
|---|---|---|
| 5.1 | Return with `hash` replaced by a valid-format wrong value (e.g. 32 `a`s) | Order stays unpaid; attempt `status=rejected`, `hash_rejected=1` |
| 5.2 | Return with `hash` removed | Order stays unpaid |
| 5.3 | Return with `total` altered but a correct hash for the original total | Still verifies (the *pinned* total is authoritative) — confirm order total never changed |
| 5.4 | Return with a `transaction_id` that has no attempt row | Refused; no order touched |
| 5.5 | Return with the right transaction id but a mismatched `order_id` | Refused |
| 5.6 | Manually move the order to Shipped, then replay a valid success return | Refused — state is no longer *Awaiting WiPay* |
| 5.7 | Replay an already-completed success return repeatedly | Idempotent; order remains paid exactly once |
| 5.8 | Return over plain `http://` instead of https | Rejected |

To craft these, take a real success return URL from step 3.4 and edit one parameter at a time.

## 6. Availability guards

| # | Step | Expected |
|---|---|---|
| 6.1 | Cart total below TTD 5.00 on TT/TTD | Checkout blocked with the minimum-amount message |
| 6.2 | Same cart in USD | Not blocked by that rule |
| 6.3 | Switch to Live with no `WIPAYWAYPOINT_API_KEY` constant | Checkout refused: "Live checkout is not configured yet." |
| 6.4 | Live + constant but empty account number | Still refused |
| 6.5 | Checkout with an account email that is not a valid address | Refused |

## 7. Session and routing

| # | Step | Expected |
|---|---|---|
| 7.1 | Complete a payment in a private window | Confirmation renders — the redirect must not depend on a live session |
| 7.2 | Test with friendly URLs **off** (`?fc=module&module=wipaywaypoint&controller=response`) | The `response_url` WiPay returns still resolves; confirm WiPay appends parameters with `&` |
| 7.3 | Test with friendly URLs **on** | Same result |
| 7.4 | Guest checkout order | Works end to end |

## 8. Failure paths

| # | Step | Expected |
|---|---|---|
| 8.1 | Point the platform at an unreachable host (or block the network) | "WiPay could not start checkout" page; order still awaiting, cart recoverable |
| 8.2 | Return an invalid checkout URL from a mocked response | Refused before redirect; shopper is never sent off-domain |
| 8.3 | Empty cart → hit the submit URL directly | Clear error, no order created |

## 9. Regression

| # | Step | Expected |
|---|---|---|
| 9.1 | A non-WiPay payment method on the same store | Still works |
| 9.2 | Uninstall the module with historical WiPay orders present | Configuration removed; **order states and attempt rows are retained** so history stays readable |
| 9.3 | Reinstall after 9.2 | No duplicate order state; attempt table intact |

## Sign-off

Release only when every row passes and the host mapping note in [README.md](README.md) has been re-confirmed against WiPay's Platforms and Environments page.
