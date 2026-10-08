# Magenx_StripeSharedPaymentTokens

Companion module for **Stripe Payments** (`stripe/module-payments`) that lets an
agentic-commerce platform (ACP — e.g. ChatGPT) pay for a Magento order with a
**Stripe Shared Payment Token** (`spt_…`), through the stock Magento
`placeOrder` flow. The order, invoice, refunds and webhooks stay entirely in
Magento and the Stripe module; only the PaymentIntent confirmation is changed.

## Why it is needed

An agent never runs Stripe.js, so it cannot produce the `pm_…` or `ctoken_…`
the Stripe module expects. It hands the seller a Shared Payment Token instead,
and Stripe charges that **only** as
`payment_method_data[shared_payment_granted_token]` on a PaymentIntent
([Stripe docs](https://docs.stripe.com/agentic-commerce/concepts/shared-payment-tokens?agent-seller=seller)).
The Stripe module (checked against 4.6.7; 4.7.0's changelog adds nothing here)
never sends that parameter: it treats the payment `token` as a PaymentMethod
id and loads it from Stripe, which fails for an `spt_…`.

## How it works

No GraphQL or database change. The storefront's ACP surface sets the token as
the Stripe payment method with the stock mutation
(`setPaymentMethodOnCart(stripe_payments: { payment_method: "spt_…" })`), which
the Stripe module stores as the payment's `token`. Then, during `placeOrder`:

| Plugin | On | Does |
| --- | --- | --- |
| `PaymentElementPlugin` | `Model\PaymentElement::confirm()` (around) | For an `spt_…` token: refuses it if the feature is off for the store (before anything reaches Stripe), otherwise opens `ConfirmScope` for the call. Afterwards replaces `token` with the `pm_…` Stripe cloned from the SPT, keeping the SPT as `shared_payment_token`. |
| `ConfirmParamsPlugin` | `Helper\PaymentIntent::getConfirmParams()` (around) | Hides the token while the stock method builds the params (return URL, payment method options, installments), then sends `payment_method_data[shared_payment_granted_token]` and drops `setup_future_usage` (a granted token cannot be saved). |
| `StripeClientPlugin` | `Model\Config::getStripeClient()` (after) | While the scope is open only, returns a client with the same key pinned to the configured API version (SPTs are a preview API). |

Outside that one confirmation every plugin is a pass-through, so normal
checkout, Express Checkout, subscriptions and the admin are unaffected.

## Configuration

**Stores > Configuration > Sales > Payment Methods > Stripe Shared Payment Tokens**

| Path | Default | Meaning |
| --- | --- | --- |
| `payment/stripe_shared_payment_tokens/enabled` | `0` | Accept `spt_…` tokens. Requires Agentic Commerce to be enabled on the Stripe account. |
| `payment/stripe_shared_payment_tokens/api_version` | `2026-09-30.preview` | `Stripe-Version` used for that confirmation only. |

Storefront: set `ACP_SHARED_PAYMENT_TOKENS=1` once this module is enabled —
until then the ACP surface refuses `spt_…` tokens itself, before placing
anything.

## Install

```bash
composer require magenx/module-stripe-shared-payment-tokens
bin/magento module:enable Magenx_StripeSharedPaymentTokens
bin/magento setup:upgrade
bin/magento cache:flush
```

## Migrating from `StripeIntegration_SharedPaymentTokens`

This module used to ship from the storefront repository as `StripeIntegration_SharedPaymentTokens`
(PHP namespace `StripeIntegration\SharedPaymentTokens`). It was renamed so our code no
longer sits in Stripe's vendor namespace. The config paths
(`payment/stripe_shared_payment_tokens/*`) are unchanged, so saved settings carry over. On an existing install:

```bash
rm -rf app/code/StripeIntegration/SharedPaymentTokens     # or: composer remove <old package>
composer require magenx/module-stripe-shared-payment-tokens
# drop any leftover 'StripeIntegration_SharedPaymentTokens' line from app/etc/config.php
bin/magento module:enable Magenx_StripeSharedPaymentTokens
bin/magento setup:upgrade
bin/magento cache:flush
```

## Caveats / verify before going live

- Written and unit-exercised against the **4.6.7** source (the latest public
  release); the store runs **4.7.0**. Before enabling, check the three plugged
  methods still exist with the same signatures in
  `vendor/stripe/module-payments` (`PaymentElement::confirm($order)`,
  `Helper\PaymentIntent::getConfirmParams($order, $paymentIntent)`,
  `Model\Config::getStripeClient()`).
- Keep **Adaptive Pricing** off (`payment/stripe_payments/adaptive_pricing`):
  in 4.7.0 it switches the storefront to the Elements-with-Checkout-Sessions
  flow, which the headless storefront and this path do not use.
- End-to-end test in a sandbox: grant a token with
  `POST /v1/test_helpers/shared_payment/granted_tokens`, complete an ACP
  checkout with it, then confirm the order is invoiced, the PaymentIntent
  shows the cloned PaymentMethod, and a refund from Magento reaches Stripe.
- 3D Secure: the confirmation uses the module's normal `use_stripe_sdk` +
  return URL; a challenge leaves the order pending exactly like a browser
  checkout, and the ACP surface reports it as `complete_in_progress`.
