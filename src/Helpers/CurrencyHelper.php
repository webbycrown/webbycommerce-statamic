<?php

namespace WebbyCrown\WebbyCommerceStatamic\Helpers;

use Illuminate\Support\Facades\Config;

class CurrencyHelper
{
    public static function format(float $amount, ?string $currency = null): string
    {
        $currency = $currency ?? Config::get('webbycommerce.currency', 'USD');
        
        return number_format($amount, 2) . ' ' . $currency;
    }

    public static function formatSymbol(float $amount, ?string $currency = null): string
    {
        $currency = $currency ?? Config::get('webbycommerce.currency', 'USD');
        $symbol = self::getSymbol($currency);
        
        return $symbol . number_format($amount, 2);
    }

    public static function getSymbol(string $currency): string
    {
        $symbols = [
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'CAD' => 'C$',
            'AUD' => 'A$',
            'JPY' => '¥',
            'CNY' => '¥',
            'INR' => '₹',
        ];

        return $symbols[$currency] ?? $currency;
    }

    public static function convert(float $amount, string $from, string $to, float $rate = 1.0): float
    {
        if ($from === $to) {
            return $amount;
        }

        return $amount * $rate;
    }
}
