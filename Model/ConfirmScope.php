<?php

declare(strict_types=1);

namespace Magenx\StripeSharedPaymentTokens\Model;

use Stripe\StripeClient;

/**
 * Request-scoped state for the one PaymentIntent confirmation that charges a
 * shared payment token.
 *
 * PaymentElementPlugin opens it around PaymentElement::confirm(); while it is
 * open, ConfirmParamsPlugin rewrites the confirm params and StripeClientPlugin
 * hands out a client on the SPT-capable API version. Outside that window both
 * plugins are pass-throughs, so no other Stripe call is affected.
 */
class ConfirmScope
{
    private ?string $token = null;
    private ?string $apiVersion = null;

    /** @var array<string, StripeClient> */
    private array $clients = [];

    public function open(string $token, string $apiVersion): void
    {
        $this->token = $token;
        $this->apiVersion = $apiVersion;
    }

    public function close(): void
    {
        $this->token = null;
        $this->apiVersion = null;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function getApiVersion(): ?string
    {
        return $this->apiVersion;
    }

    /**
     * A client with the same credentials as `$base`, pinned to `$apiVersion`.
     * Cached per key + version for the life of the request.
     */
    public function clientFor(StripeClient $base, string $apiVersion): StripeClient
    {
        $apiKey = (string)$base->getApiKey();
        $cacheKey = hash('sha256', $apiKey) . '|' . $apiVersion;
        if (!isset($this->clients[$cacheKey])) {
            $this->clients[$cacheKey] = new StripeClient([
                'api_key' => $apiKey,
                'stripe_version' => $apiVersion,
                'max_network_retries' => 2,
            ]);
        }
        return $this->clients[$cacheKey];
    }
}
