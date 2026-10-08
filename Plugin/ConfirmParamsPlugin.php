<?php

declare(strict_types=1);

namespace Magenx\StripeSharedPaymentTokens\Plugin;

use StripeIntegration\Payments\Helper\PaymentIntent;
use Magenx\StripeSharedPaymentTokens\Model\ConfirmScope;

/**
 * Builds the PaymentIntent confirm params for a shared payment token.
 *
 * The stock getConfirmParams() treats the payment's `token` as a PaymentMethod
 * id and loads it from Stripe (to work out mandate data), which fails for an
 * `spt_…` value. So the token is hidden for the duration of the call — the
 * stock method then builds everything else exactly as it would (return_url,
 * payment_method_options, installments) — and the credential goes back in the
 * one form Stripe accepts for it:
 * `payment_method_data[shared_payment_granted_token]`.
 */
class ConfirmParamsPlugin
{
    public function __construct(
        private readonly ConfirmScope $scope
    ) {
    }

    /**
     * @param \Magento\Sales\Model\Order $order
     * @param \Stripe\PaymentIntent $paymentIntent
     */
    public function aroundGetConfirmParams(PaymentIntent $subject, callable $proceed, $order, $paymentIntent)
    {
        $token = $this->scope->getToken();
        if ($token === null) {
            return $proceed($order, $paymentIntent);
        }

        $payment = $order->getPayment();
        $payment->setAdditionalInformation('token', null);
        try {
            $params = $proceed($order, $paymentIntent);
        } finally {
            $payment->setAdditionalInformation('token', $token);
        }

        unset($params['payment_method'], $params['confirmation_token'], $params['mandate_data']);
        $params['payment_method_data'] = ['shared_payment_granted_token' => $token];

        // A granted token is single-purpose (amount/expiry limits set by the
        // agent); it cannot be set up for future use.
        unset($params['setup_future_usage']);
        if (isset($params['payment_method_options']) && is_array($params['payment_method_options'])) {
            foreach ($params['payment_method_options'] as $type => $options) {
                if (is_array($options)) {
                    unset($params['payment_method_options'][$type]['setup_future_usage']);
                }
            }
        }

        return $params;
    }
}
