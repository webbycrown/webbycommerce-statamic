<?php

namespace WebbyCrown\WebbyCommerceStatamic\Tax\Standard;

use WebbyCrown\WebbyCommerceStatamic\Tax\TaxEngine;
use WebbyCrown\WebbyCommerceStatamic\Products\Product;
use Statamic\Facades\Entry;
use Illuminate\Support\Collection;
use WebbyCrown\WebbyCommerceStatamic\Tax\Standard\TaxZone;

class StandardTaxEngine implements TaxEngine
{
    protected array $config;
    protected Collection $taxRates;

    protected ?Collection $taxZones = null;
    protected ?TaxZone $resolvedZone = null;
    protected ?array $resolvedAddress = null;


    public function __construct(array $config)
    {
        $this->config = $config;
        $this->loadTaxData();
    }

    protected function loadTaxData(): void
    {
        $this->taxRates = Entry::query()
            ->where('collection', 'tax_rates')
            ->where('status', 'published')
            ->get();
    }

    public function calculateForProduct(Product $product, int $quantity = 1): float
    {
        $price = $product->finalPrice();
        return $this->calculateForLineItem($product, $quantity, $price);
    }

    public function calculateForLineItem(Product $product, int $quantity, float $price): float
    {
        if ($this->isProductTaxExempt($product)) {
            return 0;
        }

        $rate = $this->getRateForProduct($product);
        $subtotal = $price * $quantity;

        return $this->calculateTaxAmount($subtotal, $rate);
    }

    public function calculateForShipping(float $shippingAmount): float
    {
        if (!($this->config['shipping_taxes'] ?? false)) {
            return 0;
        }

        $rate = $this->getRateForShipping();

        if ($rate === 0) {
            return 0;
        }

        return $this->calculateTaxAmount($shippingAmount, $rate);
    }

    public function getRateForProduct(Product $product): float
    {
        if ($this->isProductTaxExempt($product)) {
            return 0;
        }

        $address = $this->getTaxAddress();
        $standardRate = $this->matchStandardTaxRate($product, $address ?? []);

        if ($standardRate) {
            return ((float) $standardRate->value('rate')) / 100;
        }

        // Backwards compatibility for the older direct product tax rate setup.
        $productTaxRateId = $this->normalizeTaxRateId($product->entry()->value('tax_rate'));

        if ($productTaxRateId) {
            $productTaxRate = $this->taxRates->first(fn ($rate) => $rate->id() === $productTaxRateId);

            if ($productTaxRate) {
                $baseRate = $productTaxRate->value('rate');
                if ($baseRate !== null) {
                    return ((float) $baseRate) / 100;
                }
            }
        }

        return 0;
    }

    protected function normalizeTaxRateId($taxRate): ?string
    {
        if ($taxRate instanceof \Statamic\Entries\Entry) {
            return $taxRate->id();
        }

        if ($taxRate instanceof \Illuminate\Support\Collection) {
            $taxRate = $taxRate->first();
        }

        if (is_array($taxRate)) {
            $taxRate = reset($taxRate);
        }

        if ($taxRate instanceof \Statamic\Entries\Entry) {

            return $taxRate->id();
        }

        if ($taxRate === null || $taxRate === '') {
            return null;
        }

        return (string) $taxRate;
    }

    public function getRateForShipping(): float
    {
        if (!($this->config['shipping_taxes'] ?? false)) {
            return 0;
        }

        $address = $this->getTaxAddress();
        $standardRate = $this->matchStandardShippingTaxRate($address ?? []);

        if ($standardRate) {
            return ((float) $standardRate->value('rate')) / 100;
        }

        if (!$address) {
            return 0;
        }

        $rate = $this->matchUnscopedTaxRate();

        if ($rate) {
            return ((float) $rate->value('rate')) / 100;
        }

        return $this->getDefaultRate();
    }

    public function isTaxIncludedInPrices(): bool
    {
        return (bool) ($this->config['included_in_prices']
            ?? config('webbycommerce.tax.included_in_prices', false));
    }


    public function getTaxBreakdown(array $lineItems, float $shippingAmount = 0): array
    {
        $breakdown = [
            'line_items' => [],
            'shipping' => 0,
            'total' => 0,
        ];

        foreach ($lineItems as $item) {
            $product = $item['product'] ?? null;
            $quantity = $item['quantity'] ?? 1;
            $price = $item['price'] ?? 0;

            if ($product instanceof Product) {
                $tax = $this->calculateForLineItem($product, $quantity, $price);
                $breakdown['line_items'][] = [
                    'product_id' => $product->id(),
                    'quantity' => $quantity,
                    'price' => $price,
                    'tax' => $tax,
                    'rate' => $this->getRateForProduct($product),
                ];
                $breakdown['total'] += $tax;
            }
        }

        $breakdown['shipping'] = $this->calculateForShipping($shippingAmount);
        $breakdown['total'] += $breakdown['shipping'];

        return $breakdown;
    }

    protected function isProductTaxExempt(Product $product): bool
    {
        return (bool) $product->entry()->value('exempt_from_tax', false);
    }

    protected function calculateTaxAmount(float $amount, float $rate): float
    {
        if ($amount <= 0 || $rate <= 0) {
            return 0;
        }

        if ($this->isTaxIncludedInPrices()) {
            return round($amount * ($rate / (1 + $rate)), 2);
        }

        return round($amount * $rate, 2);
    }

    protected function getTaxAddress(): ?array
    {
        if ($this->resolvedAddress !== null) {
            return $this->resolvedAddress;
        }

        $session = session()->get('checkout');
        $sessionAddress = $session['address'] ?? null;

        if ($sessionAddress) {
            $addressType = $this->config['address'] ?? 'shipping';
            $shippingSameAsBilling = (bool) ($sessionAddress['shipping_same_as_billing'] ?? false);

            if ($addressType === 'shipping') {
                $addressData = $shippingSameAsBilling
                    ? ($sessionAddress['billing_address'] ?? null)
                    : ($sessionAddress['shipping_address'] ?? $sessionAddress['billing_address'] ?? null);
            } else {
                $addressData = $sessionAddress['billing_address'] ?? $sessionAddress['shipping_address'] ?? null;
            }

            if (!$addressData) {
                $addressData = $this->handleNoAddress();
            }
        } else {
            $addressData = $this->handleNoAddress();
        }

        if (!$addressData) {
            return null; // Return null to prevent tax calculation before address is entered
        }

        // Ensure we actually have meaningful address data before applying tax
        if (empty($addressData['country']) && empty($addressData['state']) && empty($addressData['region']) && empty($addressData['zip_code']) && empty($addressData['postal_code'])) {
            return null;
        }

        $this->resolvedAddress = [
            'country' => $this->normalizeLocationValue($addressData['country'] ?? null, true),
            'region' => $this->normalizeLocationValue($addressData['state'] ?? $addressData['region'] ?? null, true),
            'postal_code' => $this->normalizeLocationValue($addressData['postal_code'] ?? $addressData['zip_code'] ?? null, true),
            'city' => $this->normalizeLocationValue($addressData['city'] ?? null, true),
        ];

        return $this->resolvedAddress;
    }


    protected function handleNoAddress(): ?array
    {
        $behavior = $this->config['behaviour']['no_address_provided'] ?? 'default_address';

        if ($behavior === 'default_address') {
            return $this->config['default_address'] ?? null;
        }

        return null;
    }

    protected function getDefaultRate(): float
    {
        $rate = $this->matchUnscopedTaxRate() ?: $this->taxRates->first();

        if ($rate) {
            return ((float) $rate->value('rate')) / 100;
        }

        return 0;
    }

    protected function matchStandardShippingTaxRate(array $address)
    {
        $zone = $this->matchTaxZone($address);

        if (!$zone) {
            return null;
        }

        // Primary: explicit shipping category.
        $primary = $this->taxRates
            ->filter(function ($rate) {
                return strtoupper((string) $this->normalizeTaxRateId($rate->value('category'))) === 'SHIPPING';
            })
            ->first(function ($rate) use ($zone) {
                return strtoupper((string) $this->normalizeTaxRateId($rate->value('zone'))) === strtoupper((string) $zone->id());
            });

        if ($primary) {
            return $primary;
        }

        // Fallback: some setups omit category and only distinguish by zone.
        return $this->taxRates
            ->filter(function ($rate) {
                $category = $this->normalizeTaxRateId($rate->value('category'));
                return $category === null || $category === '';
            })
            ->first(function ($rate) use ($zone) {
                return strtoupper((string) $this->normalizeTaxRateId($rate->value('zone'))) === strtoupper((string) $zone->id());
            });
    }


    /**
     * Match a tax rate for a product based on:
     * 1. Product's Tax Category
     * 2. Checkout address Tax Zone (Country/State)
     *
     * Matching Logic:
     * - Get the product's selected Tax Category
     * - Get the matching Tax Zone from the shipping address (if zone has country/state, they must match; if zone has no location requirements, it matches any address)
     * - Find a Tax Rate that matches both the category and zone
     *
     * Tax Zone Matching:
     * - If Zone has Country and State configured: both must match the shipping address
     * - If Zone has only Country: must match the shipping address country
     * - Zones with no Country and no State should not match here; use the special
     *   'everywhere' zone for a universal fallback.
     * - Special 'everywhere' zone: matches any address when no other zone matches
     */
    protected function matchStandardTaxRate(Product $product, array $address)
    {
        $categoryId = $this->normalizeTaxRateId($product->entry()->value('tax_category'))
            ?: $this->normalizeTaxRateId($product->entry()->value('tax_class'));

        if (!$categoryId) {
            return null;
        }

        $normalizedCategory = $this->normalizeLocationValue($categoryId, true);

        $categoryRates = $this->taxRates
            ->filter(function ($rate) use ($normalizedCategory) {
                $rateCategory = $this->normalizeTaxRateId($rate->value('category'));
                return $rateCategory !== null
                    && $this->normalizeLocationValue($rateCategory, true) === $normalizedCategory;
            });

        if ($categoryRates->isEmpty()) {
            return null;
        }

        $zone = $this->matchTaxZone($address);

        if ($zone) {
            $matchedRate = $categoryRates->first(function ($rate) use ($zone) {
                return $this->normalizeLocationValue($this->normalizeTaxRateId($rate->value('zone')), true)
                    === $this->normalizeLocationValue($zone->id(), true);
            });

            if ($matchedRate) {
                return $matchedRate;
            }
        }

        return $categoryRates->first(function ($rate) {
            return $this->normalizeLocationValue($this->normalizeTaxRateId($rate->value('zone')), true)
                === $this->normalizeLocationValue('everywhere', true);
        });
    }

    /**
     * Return a TaxZone instance matching the given address, or null.
     */
    protected function matchTaxZone(array $address = []): ?TaxZone
    {
        if ($this->resolvedZone !== null) {
            return $this->resolvedZone;
        }

        if ($this->taxZones === null) {
            $this->taxZones = Entry::query()
                ->where('collection', 'tax_zones')
                ->where('status', 'published')
                ->get();
        }

        $zones = $this->taxZones;

        if ($zones->isEmpty()) {
            return null;
        }

        // Wrap entries as TaxZone helpers for clear matching semantics
        $taxZones = $zones->map(fn ($zoneEntry) => new TaxZone($zoneEntry));

        $matched = $taxZones
            ->filter(fn ($tz) => $tz->entry()->id() !== 'everywhere')
            ->filter(function (TaxZone $tz) use ($address) {
                return $tz->matches($address);
            })
            ->sortByDesc(function (TaxZone $tz) {
                return $tz->entry()->value('region') ? 2 : 1;
            })
            ->first();

        if ($matched) {
            return $matched;
        }

        $everywhere = $zones->first(fn ($zone) => $zone->id() === 'everywhere');

        $this->resolvedZone = $everywhere ? new TaxZone($everywhere) : null;
        return $this->resolvedZone;
    }


    protected function matchUnscopedTaxRate()
    {
        return $this->taxRates->first(function ($rate) {
            return !$rate->value('category') && !$rate->value('zone');
        });
    }

    protected function normalizeLocationValue($value, bool $uppercase = false): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return $uppercase && $value !== '*' ? strtoupper($value) : $value;
    }
}
