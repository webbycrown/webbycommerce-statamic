<?php

namespace WebbyCrown\WebbyCommerceStatamic\Helpers;

use WebbyCrown\WebbyCommerceStatamic\Orders\EntryOrderRepository;
use WebbyCrown\WebbyCommerceStatamic\Customers\EntryCustomerRepository;

class OrderHelper
{
    protected EntryOrderRepository $orderRepository;
    protected EntryCustomerRepository $customerRepository;

    public function __construct(
        EntryOrderRepository $orderRepository,
        EntryCustomerRepository $customerRepository
    ) {
        $this->orderRepository = $orderRepository;
        $this->customerRepository = $customerRepository;
    }

    public function generateOrderNumber(): string
    {
        return $this->orderRepository->generateOrderNumber();
    }

    public function updateCustomerStats(string $customerId): void
    {
        $customer = $this->customerRepository->find($customerId);
        
        if (!$customer) {
            return;
        }

        $orders = $this->orderRepository->findByCustomer($customerId);
        $totalSpent = $orders->sum(fn($order) => $order->total());

        $customerData = $customer->entry()->data()->all();
        $customerData['total_orders'] = $orders->count();
        $customerData['total_spent'] = $totalSpent;
        $customerData['last_order_date'] = now()->toDateTimeString();

        $customer->entry()->data($customerData)->save();
    }

    public function calculateOrderTotals(array $cartItems, float $shippingCost, float $discountAmount = 0): array
    {
        $subtotal = array_sum(array_column($cartItems, 'total')) ?? 
                    array_sum(array_map(fn($item) => $item['price'] * $item['quantity'], $cartItems));
        
        $tax = TaxHelper::calculateWithDiscount($subtotal, $discountAmount);
        $total = $subtotal + $shippingCost + $tax - $discountAmount;

        return [
            'subtotal' => $subtotal,
            'tax' => $tax,
            'shipping' => $shippingCost,
            'discount' => $discountAmount,
            'total' => $total,
        ];
    }

    public function formatAddress(array $address): string
    {
        $lines = [];
        
        if (!empty($address['first_name']) && !empty($address['last_name'])) {
            $lines[] = $address['first_name'] . ' ' . $address['last_name'];
        }
        
        if (!empty($address['street'])) {
            $lines[] = $address['street'];
        }
        
        $cityLine = [];
        if (!empty($address['city'])) {
            $cityLine[] = $address['city'];
        }
        if (!empty($address['state'])) {
            $cityLine[] = $address['state'];
        }
        if (!empty($address['postal_code'])) {
            $cityLine[] = $address['postal_code'];
        }
        
        if (!empty($cityLine)) {
            $lines[] = implode(', ', $cityLine);
        }
        
        if (!empty($address['country'])) {
            $lines[] = $address['country'];
        }

        return implode("\n", $lines);
    }
}
