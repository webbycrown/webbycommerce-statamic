<?php

namespace WebbyCrown\WebbyCommerceStatamic\Tax;

use WebbyCrown\WebbyCommerceStatamic\Products\Product;

class BasicTaxEngine implements TaxEngine
{
    protected float $rate;
    protected bool $includedInPrices;
    protected bool $taxShipping;

    public function __construct(array $config)
    {
        $this->rate = $this->normalizeRate($config['rate'] ?? 0);
        $this->includedInPrices = $config['included_in_prices'] ?? false;
        $this->taxShipping = $config['shipping_taxes'] ?? false;
    }

    protected function normalizeRate(float|string $rate): float
    {
        if (is_string($rate)) {
            $rate = trim($rate);
            if (str_ends_with($rate, '%')) {
                $rate = rtrim($rate, '%');
            }
            $rate = (float) $rate;
        }

        if ($rate > 0 && $rate <= 1) {
            $rate *= 100;
        }

        return $rate / 100;
    }

    public function calculateForProduct(Product $product, int $quantity = 1): float
    {
        if ($this->isProductTaxExempt($product)) {
            return 0;
        }

        $price = $product->finalPrice();
        return $this->calculateForLineItem($product, $quantity, $price);
    }

    public function calculateForLineItem(Product $product, int $quantity, float $price): float
    {
        if ($this->isProductTaxExempt($product)) {
            return 0;
        }

        $subtotal = $price * $quantity;

        if ($this->includedInPrices) {
            return $subtotal * ($this->rate / (1 + $this->rate));
        }

        return $subtotal * $this->rate;
    }

    public function calculateForShipping(float $shippingAmount): float
    {
        if (!$this->taxShipping) {
            return 0;
        }

        if ($this->includedInPrices) {
            return $shippingAmount * ($this->rate / (1 + $this->rate));
        }

        return $shippingAmount * $this->rate;
    }

    public function getRateForProduct(Product $product): float
    {
        if ($this->isProductTaxExempt($product)) {
            return 0;
        }

        return $this->rate;
    }

    public function getRateForShipping(): float
    {
        if (!$this->taxShipping) {
            return 0;
        }

        return $this->rate;
    }

    public function isTaxIncludedInPrices(): bool
    {
        return $this->includedInPrices;
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
}
