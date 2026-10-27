<?php

namespace WebbyCrown\WebbyCommerceStatamic\Helpers;

use Illuminate\Support\Facades\Config;
use Statamic\Facades\GlobalSet;

class CurrencyHelper
{
    /**
     * The store currency. The Control Panel setting wins when it is filled in.
     * Config is the fallback for a site that has not saved that field yet.
     */
    public static function code(): string
    {
        try {
            $value = GlobalSet::findByHandle('webbycommerce_settings')
                ?->inDefaultSite()
                ?->get('currency');

            if (is_string($value) && trim($value) !== '') {
                return strtoupper(trim($value));
            }
        } catch (\Throwable $e) {
            // A missing global falls through to config.
        }

        $config = Config::get('webbycommerce.currency', 'USD');

        return strtoupper(trim((string) $config)) ?: 'USD';
    }

    public static function stripeCurrency(): string
    {
        return strtolower(self::code());
    }

    /**
     * Convert a major-unit total into the integer Stripe expects.
     * JPY is charged as yen. USD is charged as cents.
     */
    public static function stripeAmount(float $major): int
    {
        $code = self::code();

        if (in_array($code, self::zeroDecimalCurrencies(), true)) {
            return (int) round($major);
        }

        if (in_array($code, self::threeDecimalCurrencies(), true)) {
            return (int) round(round($major, 2) * 1000);
        }

        return (int) round($major * 100);
    }

    public static function format(float $amount, ?string $currency = null): string
    {
        $currency = $currency ?? self::code();

        return number_format($amount, 2) . ' ' . $currency;
    }

    public static function formatSymbol(float $amount, ?string $currency = null): string
    {
        $currency = $currency ?? self::code();
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

    /**
     * @return list<string>
     */
    protected static function zeroDecimalCurrencies(): array
    {
        return [
            'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
            'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
        ];
    }

    /**
     * @return list<string>
     */
    protected static function threeDecimalCurrencies(): array
    {
        return ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];
    }
}
