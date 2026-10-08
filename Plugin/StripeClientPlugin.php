<?php

declare(strict_types=1);

namespace Magenx\StripeSharedPaymentTokens\Plugin;

use StripeIntegration\Payments\Model\Config;
use Magenx\StripeSharedPaymentTokens\Model\ConfirmScope;
use Stripe\StripeClient;

/**
 * While a shared-payment-token confirmation is in progress, hands the Stripe
 * module a client pinned to the SPT-capable API version (shared payment tokens
 * are a preview API; the module pins its own GA version). Every other call
 * gets the module's client untouched.
 */
class StripeClientPlugin
{
    public function __construct(
        private readonly ConfirmScope $scope
    ) {
    }

    public function afterGetStripeClient(Config $subject, $client)
    {
        $apiVersion = $this->scope->getApiVersion();
        if ($apiVersion === null || !$client instanceof StripeClient) {
            return $client;
        }
        return $this->scope->clientFor($client, $apiVersion);
    }
}
