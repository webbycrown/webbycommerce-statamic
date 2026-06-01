<?php

namespace WebbyCrown\WebbyCommerceStatamic\Helpers;

use Illuminate\Support\Facades\Config;

class TaxHelper
{
    public static function calculate(float $subtotal, ?float $rate = null): float
    {
        $rate = $rate ?? Config::get('webbycommerce.tax.rate', 0.1);
        
        return $subtotal * $rate;
    }

    public static function calculateWithDiscount(float $subtotal, float $discount, ?float $rate = null): float
    {
        $taxableAmount = max(0, $subtotal - $discount);
        
        return self::calculate($taxableAmount, $rate);
    }

    public static function getRate(): float
    {
        return Config::get('webbycommerce.tax.rate', 0.1);
    }

    public static function isIncludedInPrices(): bool
    {
        return Config::get('webbycommerce.tax.included_in_prices', false);
    }

    public static function formatTaxRate(float $rate): string
    {
        return ($rate * 100) . '%';
    }
}
