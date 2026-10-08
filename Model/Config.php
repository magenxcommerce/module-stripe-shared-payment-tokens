<?php

declare(strict_types=1);

namespace Magenx\StripeSharedPaymentTokens\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Typed reader over payment/stripe_shared_payment_tokens/*.
 */
class Config
{
    public const XML_PATH_ENABLED = 'payment/stripe_shared_payment_tokens/enabled';
    public const XML_PATH_API_VERSION = 'payment/stripe_shared_payment_tokens/api_version';

    /** Token prefix Stripe gives a SharedPaymentToken granted to this account. */
    public const TOKEN_PREFIX = 'spt_';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getApiVersion(?int $storeId = null): string
    {
        return trim((string)$this->scopeConfig->getValue(self::XML_PATH_API_VERSION, ScopeInterface::SCOPE_STORE, $storeId));
    }

    public static function isSharedPaymentToken(mixed $token): bool
    {
        return is_string($token) && str_starts_with($token, self::TOKEN_PREFIX);
    }
}
