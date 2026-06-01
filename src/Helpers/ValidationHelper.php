<?php

namespace WebbyCrown\WebbyCommerceStatamic\Helpers;

use Illuminate\Support\Facades\Validator;

class ValidationHelper
{
    public static function validateCartData(array $data): array
    {
        return Validator::make($data, [
            'product_id' => 'required|string',
            'quantity' => 'required|integer|min:1',
            'variant' => 'nullable|array',
        ])->validate();
    }

    public static function validateCheckoutAddress(array $data): array
    {
        return Validator::make($data, [
            'email' => 'required|email',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'billing_address' => 'required|array',
            'billing_address.street' => 'required|string',
            'billing_address.city' => 'required|string',
            'billing_address.state' => 'required|string',
            'billing_address.postal_code' => 'required|string',
            'billing_address.country' => 'required|string',
            'shipping_same_as_billing' => 'boolean',
            'shipping_address' => 'required_if:shipping_same_as_billing,false|array',
        ])->validate();
    }

    public static function validateCheckoutShipping(array $data): array
    {
        return Validator::make($data, [
            'shipping_method' => 'required|string|in:standard,express,overnight',
        ])->validate();
    }

    public static function validateCheckoutPayment(array $data): array
    {
        return Validator::make($data, [
            'payment_method' => 'required|string|in:credit_card,paypal,stripe,bank_transfer',
            'coupon_code' => 'nullable|string',
        ])->validate();
    }

    public static function validateCoupon(array $data): array
    {
        return Validator::make($data, [
            'code' => 'required|string',
            'subtotal' => 'required|numeric|min:0',
        ])->validate();
    }

    public static function validateProduct(array $data): array
    {
        return Validator::make($data, [
            'title' => 'required|string|max:255',
            'sku' => 'required|string|unique:entries,sku',
            'price' => 'required|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'quantity' => 'nullable|integer|min:0',
            'status' => 'required|in:active,inactive,draft',
        ])->validate();
    }
}
