<?php

namespace WebbyCrown\WebbyCommerceStatamic\Tax;

use WebbyCrown\WebbyCommerceStatamic\Products\Product;

interface TaxEngine
{
    /**
     * Calculate tax for a product.
     */
    public function calculateForProduct(Product $product, int $quantity = 1): float;

    /**
     * Calculate tax for a line item (product + quantity).
     */
    public function calculateForLineItem(Product $product, int $quantity, float $price): float;

    /**
     * Calculate tax for shipping.
     */
    public function calculateForShipping(float $shippingAmount): float;

    /**
     * Get the tax rate for a product.
     */
    public function getRateForProduct(Product $product): float;

    /**
     * Get the tax rate for shipping.
     */
    public function getRateForShipping(): float;

    /**
     * Check if tax is included in product prices.
     */
    public function isTaxIncludedInPrices(): bool;

    /**
     * Get the tax breakdown for an order.
     */
    public function getTaxBreakdown(array $lineItems, float $shippingAmount = 0): array;
}
