<?php

namespace WebbyCrown\WebbyCommerceStatamic\Services;

use Statamic\Facades\Entry;
use Statamic\Facades\Collection;
use Illuminate\Support\Str;

class WebbycommerceServiceFixed
{
    public function getProducts($filters = [])
    {
        $entries = Entry::query()->where('collection', 'products');

        if (isset($filters['search'])) {
            $entries->where('title', 'like', '%' . $filters['search'] . '%');
        }

        if (isset($filters['status'])) {
            $entries->where('status', $filters['status']);
        }

        if (isset($filters['featured']) && $filters['featured']) {
            $entries->where('featured', true);
        }

        return $entries->get();
    }

    public function getProductBySlug($slug)
    {
        return Entry::query()
            ->where('collection', 'products')
            ->where('slug', $slug)
            ->first();
    }

    public function createProduct($data)
    {
        $entryData = [
            'title' => $data['name'],
            'slug' => $data['slug'] ?? Str::slug($data['name']),
            'published' => $data['status'] === 'active',
            'name' => $data['name'],
            'sku' => $data['sku'],
            'price' => $data['price'],
            'sale_price' => $data['sale_price'] ?? null,
            'description' => $data['description'] ?? '',
            'short_description' => $data['short_description'] ?? '',
            'status' => $data['status'],
            'featured' => $data['featured'] ?? false,
            'quantity' => $data['quantity'] ?? 0,
        ];

        return Entry::make()
            ->collection('products')
            ->data($entryData)
            ->save();
    }

    public function getOrders($filters = [])
    {
        $entries = Entry::query()->where('collection', 'orders');

        if (isset($filters['search'])) {
            $entries->where('order_number', 'like', '%' . $filters['search'] . '%');
        }

        if (isset($filters['status'])) {
            $entries->where('status', $filters['status']);
        }

        return $entries->get();
    }

    public function createOrder($data)
    {
        $entryData = [
            'title' => $this->generateOrderNumber(),
            'order_number' => $this->generateOrderNumber(),
            'published' => true,
            'status' => 'pending',
            'customer_id' => $data['customer_id'] ?? null,
            'total' => $data['total'] ?? 0,
            'subtotal' => $data['subtotal'] ?? 0,
            'tax_amount' => $data['tax_amount'] ?? 0,
            'shipping_amount' => $data['shipping_amount'] ?? 0,
            'discount_amount' => $data['discount_amount'] ?? 0,
            'currency' => $data['currency'] ?? 'USD',
            'billing_address' => $data['billing_address'] ?? [],
            'shipping_address' => $data['shipping_address'] ?? [],
            'payment_method' => $data['payment_method'] ?? '',
            'payment_status' => 'pending',
        ];

        return Entry::make()
            ->collection('orders')
            ->data($entryData)
            ->save();
    }

    public function getCustomers($filters = [])
    {
        $entries = Entry::query()->where('collection', 'customers');

        if (isset($filters['search'])) {
            $entries->where('first_name', 'like', '%' . $filters['search'] . '%')
                   ->orWhere('last_name', 'like', '%' . $filters['search'] . '%')
                   ->orWhere('email', 'like', '%' . $filters['search'] . '%');
        }

        if (isset($filters['status'])) {
            $entries->where('status', $filters['status']);
        }

        return $entries->get();
    }

    public function createCustomer($data)
    {
        $entryData = [
            'title' => $data['first_name'] . ' ' . $data['last_name'],
            'published' => $data['status'] === 'active',
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? '',
            'company' => $data['company'] ?? '',
            'status' => $data['status'] ?? 'active',
            'billing_address' => $data['billing_address'] ?? [],
            'shipping_address' => $data['shipping_address'] ?? [],
        ];

        return Entry::make()
            ->collection('customers')
            ->data($entryData)
            ->save();
    }

    public function getCoupons($filters = [])
    {
        $entries = Entry::query()->where('collection', 'coupons');

        if (isset($filters['search'])) {
            $entries->where('name', 'like', '%' . $filters['search'] . '%')
                   ->orWhere('code', 'like', '%' . $filters['search'] . '%');
        }

        if (isset($filters['status'])) {
            if ($filters['status'] === 'active') {
                $entries->where('is_active', true);
            } elseif ($filters['status'] === 'expired') {
                $entries->where('expires_at', '<', now());
            }
        }

        return $entries->get();
    }

    public function createCoupon($data)
    {
        $entryData = [
            'title' => $data['name'],
            'published' => $data['is_active'] ?? true,
            'name' => $data['name'],
            'code' => strtoupper($data['code']),
            'discount_type' => $data['discount_type'] ?? 'fixed',
            'discount_value' => $data['discount_value'] ?? 0,
            'minimum_amount' => $data['minimum_amount'] ?? 0,
            'maximum_discount' => $data['maximum_discount'] ?? null,
            'usage_limit' => $data['usage_limit'] ?? null,
            'usage_limit_per_customer' => $data['usage_limit_per_customer'] ?? null,
            'starts_at' => $data['starts_at'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'free_shipping' => $data['free_shipping'] ?? false,
            'first_time_only' => $data['first_time_only'] ?? false,
        ];

        return Entry::make()
            ->collection('coupons')
            ->data($entryData)
            ->save();
    }

    protected function generateOrderNumber()
    {
        do {
            $number = 'ORD-' . date('Y') . '-' . strtoupper(Str::random(8));
        } while (Entry::query()
            ->where('collection', 'orders')
            ->where('order_number', $number)
            ->exists());

        return $number;
    }

    public function validateCoupon($code, $subtotal = 0)
    {
        $coupon = Entry::query()
            ->where('collection', 'coupons')
            ->where('code', strtoupper($code))
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                      ->orWhere('expires_at', '>', now());
            })
            ->first();

        if (!$coupon) {
            return ['valid' => false, 'message' => 'Coupon not found or expired.'];
        }

        $discountType = $coupon->get('discount_type', 'fixed');
        $discountValue = $coupon->get('discount_value', 0);
        $minimumAmount = $coupon->get('minimum_amount', 0);

        if ($minimumAmount && $subtotal < $minimumAmount) {
            return ['valid' => false, 'message' => 'Minimum order amount required.'];
        }

        $discount = $discountType === 'percentage' 
            ? $subtotal * ($discountValue / 100)
            : $discountValue;

        return [
            'valid' => true,
            'coupon' => $coupon,
            'discount' => $discount,
            'message' => 'Coupon applied successfully!'
        ];
    }
}
