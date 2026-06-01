<?php

namespace WebbyCrown\WebbyCommerceStatamic\Helpers;

use Illuminate\Support\Facades\Config;

class ShippingHelper
{
    public static function getMethods(): array
    {
        return Config::get('webbycommerce.shipping.methods', []);
    }

    public static function getMethod(string $key): ?array
    {
        $methods = self::getMethods();
        
        return $methods[$key] ?? null;
    }

    public static function calculateCost(string $method, float $cartTotal): float
    {
        $methodData = self::getMethod($method);
        
        if (!$methodData) {
            return 0;
        }

        $freeThreshold = $methodData['free_threshold'] ?? null;
        
        if ($freeThreshold !== null && $cartTotal >= $freeThreshold) {
            return 0;
        }

        return $methodData['cost'] ?? 0;
    }

    public static function getDefaultMethod(): string
    {
        return Config::get('webbycommerce.shipping.default_method', 'standard');
    }

    public static function isFreeShipping(float $cartTotal, ?string $method = null): bool
    {
        $method = $method ?? self::getDefaultMethod();
        $methodData = self::getMethod($method);
        
        if (!$methodData) {
            return false;
        }

        $freeThreshold = $methodData['free_threshold'] ?? null;
        
        return $freeThreshold !== null && $cartTotal >= $freeThreshold;
    }
}
