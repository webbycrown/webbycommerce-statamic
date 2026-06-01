<?php

namespace WebbyCrown\WebbyCommerceStatamic\Shipping;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Statamic\Facades\GlobalSet;

/**
 * Class ShippingManager
 *
 * Handles:
 * - Loading shipping rates from the webbycommerce_settings global set
 * - Matching customer shipping addresses against shipping zones
 * - Calculating shipping costs
 * - Resolving fallback/default shipping methods
 * - Session-based shipping retrieval
 *
 * Shipping rules are based on:
 * - Country
 * - State
 * - City
 * - ZIP/Postal code
 * - Min/max order amount
 *
 * Matching priority:
 * ZIP > City > State > Country
 */

class ShippingManager
{
   /**
     * Loaded shipping rate items (from globals replicator).
     *
     * @var Collection
     */
    protected Collection $shippingRates;

    /**
     * Prevent duplicate loading of shipping rates.
     *
     * @var bool
     */
    protected bool $loaded = false;

    /**
     * Load all shipping rate items from the
     * "webbycommerce_settings" global set.
     *
     * Uses the current shipping_locations global grid only.
     * Legacy shipping_rates collection data is ignored.
     *
     * This method runs only once unless reload() is called.
     *
     * @return void
     */
    protected function loadShippingRates(): void
    {
        if ($this->loaded) {
            return;
        }

        try {
            $globalSet = GlobalSet::findByHandle('webbycommerce_settings');
            $variables = $globalSet?->inDefaultSite();
            $locations = $variables?->get('shipping_locations') ?? [];

            if (! empty($locations)) {
                $rates = [
                    [
                        'id' => 'shipping_locations',
                        'name' => $variables?->get('shipping_fallback_name') ?? 'Shipping',
                        'description' => $variables?->get('shipping_fallback_description') ?? 'Shipping charges by location',
                        'method_type' => 'flat_rate',
                        'shipping_locations' => $locations,
                        'is_default' => true,
                        'sort_order' => 0,
                        'enabled' => true,
                    ],
                ];
            } else {
                $rates = [];
            }

            // Convert each row into a simple object with value() support
            $this->shippingRates = collect($rates)
                ->filter(fn($row) => ($row['enabled'] ?? true) && (!isset($row['type']) || ($row['type'] ?? '') === 'shipping_method'))
                ->map(fn($row) => new ShippingRateItem($row))
                ->values();
        } catch (\Exception $e) {
            Log::error('ShippingManager: Failed to load shipping rates - ' . $e->getMessage());
            $this->shippingRates = collect();
        }

        $this->loaded = true;
    }

    /**
     * Force-reload shipping rate entries (useful after session/address changes).
     */
    public function reload(): void
    {
        $this->loaded = false;
        $this->loadShippingRates();
    }

    /**
     * Get all shipping rate entries.
     */
    public function allRates(): Collection
    {
        $this->loadShippingRates();
        return $this->shippingRates;
    }

    /**
     * Get available shipping methods for a given address and cart subtotal.
     *
     * Each returned method includes:
     *   - id (entry ID)
     *   - name
     *   - description
     *   - method_type (flat_rate, free_shipping, local_pickup, custom_rate)
     *   - cost (float)
     *   - is_default (bool)
     *   - sort_order (int)
     *
     * @param  array  $address  ['country'=>..., 'state'=>..., 'city'=>..., 'postal_code'=>...]
     * @param  float  $subtotal Cart subtotal
     * @return array  List of available shipping methods sorted by sort_order
     */
    public function getAvailableMethods(array $address, float $subtotal): array
    {

        $this->loadShippingRates();

        $methods = [];

        foreach ($this->shippingRates as $entry) {
            $locations = $entry->value('shipping_locations') ?? [];
            $methodType = $entry->value('method_type') ?? 'flat_rate';

            $matchResult = $this->matchLocation($locations, $address, $subtotal);
            if ($matchResult !== null) {
                $cost = $this->resolveCost($methodType, $matchResult['shipping_charge'], $subtotal);

                $methods[] = [
                    'id' => $entry->id(),
                    'slug' => $entry->slug(),
                    'name' => $entry->value('name') ?? $entry->value('title') ?? 'Shipping',
                    'description' => $entry->value('description') ?? '',
                    'method_type' => $methodType,
                    'cost' => round($cost, 2),
                    'is_default' => (bool) ($entry->value('is_default') ?? false),
                    'sort_order' => (int) ($entry->value('sort_order') ?? 99),
                ];
            }
        }

        // If no methods matched, return no shipping methods when configured shipping locations exist.
        // Otherwise, fall back to legacy fallback methods.
        if (empty($methods)) {
            if ($this->hasConfiguredShippingLocations()) {
                return [];
            }

            $methods = $this->getFallbackMethods($subtotal, $address);
        }

        // Sort by sort_order ascending
        usort($methods, fn($a, $b) => $a['sort_order'] <=> $b['sort_order']);

        return $methods;
    }

    protected function hasConfiguredShippingLocations(): bool
    {
        return $this->shippingRates->isNotEmpty()
            && ! empty($this->shippingRates->first()->value('shipping_locations'));
    }

    /**
     * Calculate the shipping cost for a specific shipping rate entry ID and address.
     *
     * @param  string  $rateId   The shipping rate item ID
     * @param  array   $address  Customer address
     * @param  float   $subtotal Cart subtotal
     * @return float|null  Cost, or null if not available
     */
    public function calculateForRate(string $rateId, array $address, float $subtotal): ?float
    {
        $this->loadShippingRates();

        $entry = $this->shippingRates->first(fn($e) => $e->id() === $rateId);

        if (!$entry) {
            return null;
        }

        $locations = $entry->value('shipping_locations') ?? [];
        $methodType = $entry->value('method_type') ?? 'flat_rate';

        $matchResult = $this->matchLocation($locations, $address, $subtotal);

        if ($matchResult === null) {
            // If this rate item is based on configured shipping locations and no location matched,
            // do not apply a shipping charge.
            if (! empty($locations) && $entry->value('shipping_locations')) {
                return 0.0;
            }

            // Check if this is a default/fallback rate when no configured locations exist.
            if ($entry->value('is_default')) {
                $fallbackCharge = $this->getFirstActiveCharge($locations, $subtotal);
                if ($fallbackCharge !== null) {
                    return round($this->resolveCost($methodType, $fallbackCharge, $subtotal), 2);
                }
            }

            return null;
        }

        return round($this->resolveCost($methodType, $matchResult['shipping_charge'], $subtotal), 2);
    }

    /**
     * Get shipping cost from the checkout session or calculate it.
     */
    public function getSessionShippingCost(): float
    {
        $checkout = Session::get('checkout');
        if ($checkout && isset($checkout['shipping']['shipping_cost'])) {
            return (float) $checkout['shipping']['shipping_cost'];
        }

        return 0.0;
    }

    /**
     * Resolve the shipping address from the checkout session.
     */
    public function getShippingAddress(): array
    {
        $session = Session::get('checkout');

        if (!$session || empty($session['address'])) {
            return [];
        }

        $addressData = $session['address'];
        $shippingSameAsBilling = $addressData['shipping_same_as_billing'] ?? true;

        if ($shippingSameAsBilling) {
            $raw = $addressData['billing_address'] ?? [];
        } else {
            $raw = $addressData['shipping_address'] ?? $addressData['billing_address'] ?? [];
        }

        return [
            'country' => $raw['country'] ?? '',
            'state' => $raw['state'] ?? $raw['region'] ?? '',
            'city' => $raw['city'] ?? '',
            'postal_code' => $raw['postal_code'] ?? $raw['zip_code'] ?? '',
        ];
    }

    /**
     * Match a customer address against a list of shipping locations.
     *
     * Uses a weighted scoring system:
     *   - ZIP/Postal code match  = +1000
     *   - City match             = +100
     *   - State match            = +10
     *   - Country match          = +1
     *
     * All non-wildcard fields must match for a location to qualify.
     * Wildcards (*) always match and add zero score.
     *
     * @param  array  $locations Shipping locations from the entry
     * @param  array  $address   Customer address
     * @param  float  $subtotal  Cart subtotal (for min/max filtering)
     * @return array|null  Best matched location row, or null if none match
     */
    protected function matchLocation(array $locations, array $address, float $subtotal): ?array
    {
        $addrCountry = trim($address['country'] ?? '');
        $addrState = trim($address['state'] ?? '');
        $addrCity = trim($address['city'] ?? '');
        $addrZip = trim($address['postal_code'] ?? '');

        if ($addrState !== '') {
            $resolvedState = $this->getStateNameFromCode($addrState);
            if ($resolvedState !== null) {
                $addrState = trim($resolvedState);
            }
        }

        $exactLocations = array_filter($locations, fn($location) =>
            trim($location['country'] ?? '*') !== '*'
            && trim($location['state'] ?? '*') !== '*'
            && trim($location['city'] ?? '*') !== '*'
            && trim($location['zip_code'] ?? '*') !== '*'
        );

        $bestLocation = $this->findBestLocationMatch($exactLocations, $addrCountry, $addrState, $addrCity, $addrZip, $subtotal);

        if ($bestLocation !== null) {
            return $bestLocation;
        }

        return $this->findBestLocationMatch($locations, $addrCountry, $addrState, $addrCity, $addrZip, $subtotal);
    }

    protected function findBestLocationMatch(array $locations, string $addrCountry, string $addrState, string $addrCity, string $addrZip, float $subtotal): ?array
    {
        $bestScore = -1;
        $bestLocation = null;

        foreach ($locations as $location) {
            // Skip inactive locations
            if (isset($location['is_active']) && !$location['is_active']) {
                continue;
            }

            // Check min/max order amount
            $min = $location['min_order_amount'] ?? null;
            $max = $location['max_order_amount'] ?? null;

            if ($min !== null && $min !== '' && $subtotal < (float) $min) {
                continue;
            }

            if ($max !== null && $max !== '' && $subtotal > (float) $max) {
                continue;
            }

            $locCountry = trim($location['country'] ?? '*');
            $locState = trim($location['state'] ?? '*');
            $locCity = trim($location['city'] ?? '*');
            $locZip = trim($location['zip_code'] ?? '*');

            // Check each field — a non-wildcard field must match (case-insensitive)
            $countryMatch = ($locCountry === '*' || $this->countryMatches($locCountry, $addrCountry));
            $stateMatch   = ($locState === '*' || $this->stateMatches($locState, $addrState, $addrCountry));
            $cityMatch    = ($locCity === '*' || $this->normalizeLocationForComparison($locCity, 'city') === $this->normalizeLocationForComparison($addrCity, 'city'));
            $zipMatch     = ($locZip === '*' || $this->normalizeLocationForComparison($locZip, 'postal_code') === $this->normalizeLocationForComparison($addrZip, 'postal_code'));

            if (!$countryMatch || !$stateMatch || !$cityMatch || !$zipMatch) {
                continue;
            }

            // Calculate specificity score
            $score = 0;
            if ($locZip !== '*')     $score += 1000;
            if ($locCity !== '*')    $score += 100;
            if ($locState !== '*')   $score += 10;
            if ($locCountry !== '*') $score += 1;

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestLocation = $location;
            }
        }

        return $bestLocation;
    }

    /**
     * Resolve final shipping cost
     * based on method type.
     *
     * @param string $methodType
     * @param float $charge
     * @param float $subtotal
     * @return float
     */
    protected function resolveCost(string $methodType, float $charge, float $subtotal): float
    {
        return match ($methodType) {
            'free_shipping' => 0.0,
            'local_pickup'  => 0.0,
            'flat_rate'     => $charge,
            'custom_rate'   => $charge,
            default         => $charge,
        };
    }

    /**
     * Return first active shipping charge
     * from location rows.
     *
     * Used for fallback/default handling.
     *
     * @param array $locations
     * @param float $subtotal
     * @return float|null
     */
    protected function getFirstActiveCharge(array $locations, float $subtotal): ?float
    {
        foreach ($locations as $location) {
            if (isset($location['is_active']) && !$location['is_active']) {
                continue;
            }

            return (float) ($location['shipping_charge'] ?? 0);
        }

        return null;
    }

    /**
     * Resolve fallback shipping methods.
     *
     * Priority:
     * 1. is_default entries
     * 2. Wildcard location entries
     * 3. Hardcoded config fallback
     *
     * @param float $subtotal
     * @param array $address
     * @return array
     */
    protected function getFallbackMethods(float $subtotal, array $address = []): array
    {
        $this->loadShippingRates();
        $methods = [];

        // 1. Look for entries marked as default
        $defaultEntries = $this->shippingRates->filter(fn($e) => $e->value('is_default'));

        if ($defaultEntries->isNotEmpty()) {
            foreach ($defaultEntries as $entry) {
                $locations = $entry->value('shipping_locations') ?? [];
                $methodType = $entry->value('method_type') ?? 'flat_rate';
                $charge = $this->getFirstActiveCharge($locations, $subtotal) ?? 0;
                $cost = $this->resolveCost($methodType, $charge, $subtotal);

                $methods[] = [
                    'id' => $entry->id(),
                    'slug' => $entry->slug(),
                    'name' => $entry->value('name') ?? $entry->value('title') ?? 'Default Shipping',
                    'description' => $entry->value('description') ?? 'Default shipping rate',
                    'method_type' => $methodType,
                    'cost' => round($cost, 2),
                    'is_default' => true,
                    'sort_order' => (int) ($entry->value('sort_order') ?? 99),
                ];
            }

            return $methods;
        }

        // 2. Look for entries with all-wildcard first location
        foreach ($this->shippingRates as $entry) {
            $locations = $entry->value('shipping_locations') ?? [];
            if (empty($locations)) {
                continue;
            }

            $first = $locations[0];
            $isWildcard = (
                ($first['country'] ?? '*') === '*' &&
                ($first['state'] ?? '*') === '*' &&
                ($first['city'] ?? '*') === '*' &&
                ($first['zip_code'] ?? '*') === '*'
            );

            if ($isWildcard) {
                $methodType = $entry->value('method_type') ?? 'flat_rate';
                $charge = (float) ($first['shipping_charge'] ?? 0);
                $cost = $this->resolveCost($methodType, $charge, $subtotal);

                // Check min/max
                $min = $first['min_order_amount'] ?? null;
                $max = $first['max_order_amount'] ?? null;

                if ($min !== null && $min !== '' && $subtotal < (float) $min) {
                    continue;
                }
                if ($max !== null && $max !== '' && $subtotal > (float) $max) {
                    continue;
                }

                if (!(isset($first['is_active']) && !$first['is_active'])) {
                    $methods[] = [
                        'id' => $entry->id(),
                        'slug' => $entry->slug(),
                        'name' => $entry->value('name') ?? $entry->value('title') ?? 'Shipping',
                        'description' => $entry->value('description') ?? '',
                        'method_type' => $methodType,
                        'cost' => round($cost, 2),
                        'is_default' => false,
                        'sort_order' => (int) ($entry->value('sort_order') ?? 99),
                    ];
                }
            }
        }

        if (!empty($methods)) {
            return $methods;
        }

        // 3. Hardcoded fallback — ensures checkout never breaks
        $fallbackCost = (float) config('webbycommerce.shipping.fallback_cost', 0);
        $methods[] = [
            'id' => 'fallback',
            'name' => config('webbycommerce.shipping.fallback_name', 'Standard Shipping'),
            'description' => config('webbycommerce.shipping.fallback_description', 'Default shipping rate'),
            'method_type' => 'flat_rate',
            'cost' => $fallbackCost,
            'is_default' => true,
            'sort_order' => 0,
        ];

        return $methods;
    }

    /**
     * Normalize location value before comparison.
     *
     * Handles:
     * - Trimming
     * - Uppercasing
     * - Country code extraction
     * - Country name conversion
     *
     * Example:
     * "India" => "IN"
     * "United States" => "US"
     *
     * @param string $value
     * @param string $field
     * @return string
     */


    protected function countryMatches(string $locationCountry, string $addressCountry): bool
    {
        $locationCountry = trim($locationCountry);
        $addressCountry = trim($addressCountry);

        if ($locationCountry === '' || $locationCountry === '*') {
            return true;
        }

        if ($addressCountry === '') {
            return false;
        }

        $addressCountry = $this->normalizeLocationForComparison($addressCountry, 'country');

        foreach (preg_split('/\s*,\s*/', $locationCountry, -1, PREG_SPLIT_NO_EMPTY) as $countryOption) {
            $countryOption = trim($countryOption);
            if ($countryOption === '' || $countryOption === '*') {
                return true;
            }

            if ($this->normalizeLocationForComparison($countryOption, 'country') === $addressCountry) {
                return true;
            }
        }

        return false;
    }

    protected function normalizeLocationForComparison(string $value, string $field): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (preg_match('/\(([A-Z]{2,3})\)\s*$/i', $value, $matches)) {
            return strtoupper($matches[1]);
        }

        if ($field === 'country') {
            return $this->normalizeCountryForComparison($value);
        }

        if ($field === 'state') {
            return $this->normalizeStateForComparison($value);
        }

        return $this->normalizeGenericLocationValue($value);
    }

    public function normalizeStateForAddress(string $state, string $country = ''): string
    {
        return $this->normalizeStateForComparison($state, $country);
    }

    protected function stateMatches(string $locationState, string $addressState, string $country): bool
    {
        $locationState = trim($locationState);
        $addressState = trim($addressState);

        if ($locationState === '' || $locationState === '*') {
            return true;
        }

        if ($addressState === '') {
            return false;
        }

        $countryCode = $this->normalizeLocationForComparison($country, 'country');
        $addressKey = $this->normalizeStateForComparison($addressState, $countryCode);

        foreach (preg_split('/\s*,\s*/', $locationState, -1, PREG_SPLIT_NO_EMPTY) as $stateOption) {
            $optionKey = $this->normalizeStateForComparison(trim($stateOption), $countryCode);
            if ($optionKey !== '' && $optionKey === $addressKey) {
                return true;
            }
        }

        return false;
    }

    protected function normalizeStateForComparison(string $value, string $country = ''): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $normalized = $this->normalizeGenericLocationValue($value);
        $country = trim(strtoupper($country));

        if ($country === '') {
            return $normalized;
        }

        $lookup = $this->stateCodeLookup($country);

        return $lookup[$normalized] ?? $normalized;
    }

    /**
     * Normalize country names into ISO codes.
     *
     * @param string $value
     * @return string
     */

    protected function normalizeCountryForComparison(string $value): string
    {
        $normalized = $this->normalizeGenericLocationValue($value);

        if (preg_match('/^[A-Z]{2}$/', $normalized)) {
            return $normalized;
        }

        return $this->countryCodeLookup()[$normalized] ?? $normalized;
    }

    protected function stateCodeLookup(string $countryIso): array
    {
        static $lookup = [];

        if (isset($lookup[$countryIso])) {
            return $lookup[$countryIso];
        }

        $lookup[$countryIso] = [];
        $file = realpath(__DIR__ . '/../../resources/json/regions.json');

        if (! $file || ! file_exists($file)) {
            return $lookup[$countryIso];
        }

        $data = json_decode(file_get_contents($file), true);
        if (! is_array($data)) {
            return $lookup[$countryIso];
        }

        foreach ($data as $row) {
            if (! isset($row['country_iso'], $row['id'], $row['name'])) {
                continue;
            }

            if (strtoupper(trim($row['country_iso'])) !== $countryIso) {
                continue;
            }

            $regionCode = strtoupper(trim((string) substr(strrchr($row['id'], '-'), 1)));
            $regionName = $this->normalizeGenericLocationValue($row['name']);

            if ($regionCode !== '') {
                $lookup[$countryIso][$regionCode] = $regionName;
            }

            if ($regionName !== '') {
                $lookup[$countryIso][$regionName] = $regionName;
            }
        }

        return $lookup[$countryIso];
    }

    public function getStateNameFromCode(string $stateCode = ''): ?string
    {

        $file = realpath(__DIR__ . '/../../resources/json/regions.json');

        if (! $file || ! file_exists($file)) {
            return null;
        }

        $data = json_decode(file_get_contents($file), true);
        if (! is_array($data)) {
            return null;
        }


        $stateCode = $stateCode ? strtoupper(str_replace(' ', '-', trim($stateCode))) : '  ';

        foreach ($data as $row) {
            $regionCode = strtoupper(trim(($row['id'])));
        
            if ($regionCode === $stateCode) {
                
                return trim($row['name']);
            }
        }

        return null;
    }

    /**
     * Build country lookup map.
     *
     * Example:
     * "INDIA" => "IN"
     * "UNITED STATES" => "US"
     *
     * Uses ICU ResourceBundle.
     *
     * @return array
     */

    protected function countryCodeLookup(): array
    {
        static $lookup = null;

        if ($lookup !== null) {
            return $lookup;
        }

        $lookup = [];

        if (! class_exists(\ResourceBundle::class)) {
            return $lookup;
        }

        try {
            $bundle = \ResourceBundle::create('en', 'ICUDATA-region');
            $countrySets = array_filter([
                $bundle?->get('Countries'),
                $bundle?->get('Countries%short'),
                $bundle?->get('Countries%variant'),
            ]);

            foreach ($countrySets as $countries) {
                foreach ($countries as $code => $name) {
                    if (! is_string($code) || ! is_string($name) || ! preg_match('/^[A-Z]{2}$/', $code)) {
                        continue;
                    }

                    $lookup[$this->normalizeGenericLocationValue($code)] = $code;
                    $lookup[$this->normalizeGenericLocationValue($name)] = $code;
                }
            }
        } catch (\Throwable $e) {
            return [];
        }

        return $lookup;
    }

    /**
     * Generic normalization helper.
     *
     * Converts:
     * - Uppercase
     * - Remove special chars
     * - Normalize spaces
     *
     * Example:
     * "New-York" => "NEW YORK"
     *
     * @param string $value
     * @return string
     */

    protected function normalizeGenericLocationValue(string $value): string
    {
        $value = strtoupper(trim($value));
        $value = preg_replace('/[^A-Z0-9]+/', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }
}
