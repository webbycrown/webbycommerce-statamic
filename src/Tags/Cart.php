<?php

namespace WebbyCrown\WebbyCommerceStatamic\Tags;

use Statamic\Tags\Tags;
use Statamic\Facades\Site;

class Cart extends Tags
{
    /**
     * The {{ cart }} tag.
     */
    public function index()
    {
        return app('cart')->toArray();
    }

    /**
     * The {{ cart:has }} tag.
     */
    public function has()
    {
        return app('cart')->count() > 0;
    }

    public function items()
    {
        return array_values(app('cart')->items());
    }

    /**
     * The {{ cart:count }} tag.
     */
    public function count()
    {
        return app('cart')->count();
    }

    /**
     * The {{ cart:total_quantity }} tag.
     */
    public function totalQuantity()
    {
        return app('cart')->totalQuantity();
    }

    /**
     * The {{ cart:subtotal }} tag.
     */
    public function subtotal()
    {
        return app('cart')->subtotal();
    }

    /**
     * The {{ cart:total }} tag.
     */
    public function total()
    {
        return app('cart')->total();
    }

    /**
     * The {{ cart:tax }} tag.
     */
    public function tax()
    {
        return app('cart')->tax();
    }

    /**
     * The {{ cart:shipping }} tag.
     */
    public function shipping()
    {
        return app('cart')->shipping();
    }

    /**
     * The {{ cart:shipping_breakdown }} tag.
     */
    public function shippingBreakdown()
    {
        return app('cart')->shippingBreakdown();
    }

    /**
     * The {{ cart:discount }} tag.
     */
    public function discount()
    {
        return app('cart')->discount();
    }

    /**
     * The {{ cart:tax_breakdown }} tag.
     */
    public function taxBreakdown()
    {
        return app('cart')->getTaxBreakdown();
    }

    /**
     * The {{ cart:tax_rate }} tag.
     */
    public function taxRate()
    {
        return app('cart')->taxRate();
    }

    /**
     * The {{ cart:is_tax_included }} tag.
     */
    public function isTaxIncluded()
    {
        return app('cart')->isTaxIncluded();
    }
}
