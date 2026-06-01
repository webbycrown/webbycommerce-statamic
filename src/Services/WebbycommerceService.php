<?php

namespace WebbyCrown\WebbyCommerceStatamic\Services;

use WebbyCrown\WebbyCommerceStatamic\Products\EntryProductRepository;
use Statamic\Facades\Entry;
use Statamic\Facades\Collection;
use Illuminate\Support\Str;

class WebbycommerceService
{
    protected EntryProductRepository $productRepository;

    public function __construct(EntryProductRepository $productRepository)
    {
        $this->productRepository = $productRepository;
    }

    public function getProducts($filters = [])
    {
        $query = $this->productRepository->query();

        if (isset($filters['search'])) {
            $query->search($filters['search']);
        }

        if (isset($filters['status'])) {
            $query->whereStatus($filters['status']);
        }

        if (isset($filters['featured']) && $filters['featured']) {
            $query->whereFeatured(true);
        }

        return $query->get();
    }

    public function getProductBySlug($slug)
    {
        return $this->productRepository->findBySlug($slug);
    }

    public function createProduct($data)
    {
        $data['slug'] = $data['slug'] ?? Str::slug($data['name']);
        $data['title'] = $data['name'];
        $data['published'] = $data['status'] === 'active';

        return $this->productRepository->make()
            ->slug($data['slug'])
            ->data($data)
            ->published($data['published'])
            ->save();
    }

    public function updateProduct($entry, $data)
    {
        $data['slug'] = $data['slug'] ?? Str::slug($data['name']);
        $data['title'] = $data['name'];
        $data['published'] = $data['status'] === 'active';

        $entry->slug($data['slug'])->data($data)->published($data['published'])->save();
        return $entry;
    }

    public function getOrders($filters = [])
    {
        $query = Entry::query()->where('collection', 'orders');

        if (isset($filters['search'])) {
            $query->where('order_number', 'like', '%' . $filters['search'] . '%');
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->get();
    }

    public function createOrder($data)
    {
        $data['order_number'] = $this->generateOrderNumber();
        $data['title'] = $data['order_number'];
        $data['published'] = true;

        return Entry::make()
            ->collection('orders')
            ->data($data)
            ->save();
    }

    public function getCustomers($filters = [])
    {
        $query = Entry::query()->where('collection', 'customers');

        if (isset($filters['search'])) {
            $query->where('first_name', 'like', '%' . $filters['search'] . '%')
                  ->orWhere('last_name', 'like', '%' . $filters['search'] . '%')
                  ->orWhere('email', 'like', '%' . $filters['search'] . '%');
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->get();
    }

    public function createCustomer($data)
    {
        $data['title'] = $data['first_name'] . ' ' . $data['last_name'];
        $data['published'] = $data['status'] === 'active';

        return Entry::make()
            ->collection('customers')
            ->data($data)
            ->save();
    }

    public function getCoupons($filters = [])
    {
        $query = Entry::query()->where('collection', 'coupons');

        if (isset($filters['search'])) {
            $query->where('name', 'like', '%' . $filters['search'] . '%')
                  ->orWhere('code', 'like', '%' . $filters['search'] . '%');
        }

        if (isset($filters['status'])) {
            if ($filters['status'] === 'active') {
                $query->where('is_active', true);
            } elseif ($filters['status'] === 'expired') {
                $query->where('expires_at', '<', now());
            }
        }

        return $query->get();
    }

    public function createCoupon($data)
    {
        $data['title'] = $data['name'];
        $data['published'] = $data['is_active'] ?? true;

        return Entry::make()
            ->collection('coupons')
            ->data($data)
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
