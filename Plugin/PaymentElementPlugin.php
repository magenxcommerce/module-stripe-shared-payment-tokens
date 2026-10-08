<?php

declare(strict_types=1);

namespace Magenx\StripeSharedPaymentTokens\Plugin;

use Magento\Framework\Exception\LocalizedException;
use StripeIntegration\Payments\Model\PaymentElement;
use Magenx\StripeSharedPaymentTokens\Model\Config;
use Magenx\StripeSharedPaymentTokens\Model\ConfirmScope;

/**
 * Brackets PaymentElement::confirm() for an order paid with a shared payment
 * token (the `spt_…` value the agent platform sent, stored by the Stripe
 * module's own assignPaymentData() as the payment's `token`).
 *
 * Before: refuses the token when the feature is off for the order's store —
 * nothing has been sent to Stripe yet, so the order fails cleanly.
 * During: opens ConfirmScope so the confirm params and the API version are
 * rewritten (ConfirmParamsPlugin, StripeClientPlugin).
 * After: confirming with an SPT makes Stripe clone the buyer's payment method
 * into a real `pm_…` on the PaymentIntent. That id replaces the token, so every
 * later reader of `token` (order payment info, saved-method bookkeeping, admin
 * views) sees a normal PaymentMethod; the SPT is kept as `shared_payment_token`.
 */
class PaymentElementPlugin
{
    public function __construct(
        private readonly Config $config,
        private readonly ConfirmScope $scope
    ) {
    }

    /**
     * @param \Magento\Sales\Model\Order $order
     */
    public function aroundConfirm(PaymentElement $subject, callable $proceed, $order)
    {
        $payment = $order->getPayment();
        $token = $payment ? $payment->getAdditionalInformation('token') : null;
        if (!Config::isSharedPaymentToken($token)) {
            return $proceed($order);
        }

        $storeId = (int)$order->getStoreId();
        $apiVersion = $this->config->getApiVersion($storeId);
        if (!$this->config->isEnabled($storeId) || $apiVersion === '') {
            throw new LocalizedException(__('This store does not accept shared payment tokens.'));
        }

        $this->scope->open($token, $apiVersion);
        try {
            $result = $proceed($order);
        } finally {
            $this->scope->close();
        }

        $paymentMethod = $result->payment_method ?? null;
        $paymentMethodId = is_object($paymentMethod) ? ($paymentMethod->id ?? null) : $paymentMethod;
        $payment->setAdditionalInformation('shared_payment_token', $token);
        if (is_string($paymentMethodId) && str_starts_with($paymentMethodId, 'pm_')) {
            $payment->setAdditionalInformation('token', $paymentMethodId);
        }

        return $result;
    }
}
