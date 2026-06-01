<?php

namespace WebbyCrown\WebbyCommerceStatamic\Orders;

use Statamic\Facades\Entry;
use Illuminate\Support\Carbon;

class Order
{
    protected $entry;

    public function __construct($entry)
    {
        $this->entry = $entry;
    }

    public static function fromEntry($entry)
    {
        return new self($entry);
    }

    public function id()
    {
        return $this->entry->id();
    }

    public function orderNumber()
    {
        return $this->entry->value('order_number');
    }

    public function title()
    {
        return $this->entry->value('title');
    }

    public function customer()
    {
        return $this->entry->value('customer');
    }

    public function customerEmail()
    {
        return $this->entry->value('customer_email');
    }

    public function customerName()
    {
        return $this->entry->value('customer_name');
    }

    public function items()
    {
        return $this->entry->value('items') ?? [];
    }

    public function subtotal()
    {
        return (float) $this->entry->value('items_total', $this->entry->value('subtotal', 0));
    }

    public function tax()
    {
        return (float) $this->entry->value('tax_total', $this->entry->value('tax', 0));
    }

    public function shipping()
    {
        return (float) $this->entry->value('shipping_total', $this->entry->value('shipping', 0));
    }

    public function discount()
    {
        return (float) $this->entry->value('coupon_total', $this->entry->value('discount', 0));
    }

    public function total()
    {
        return (float) $this->entry->value('grand_total', $this->entry->value('total', 0));
    }

    public function status()
    {
        return $this->entry->value('status', 'pending');
    }

    public function isPending()
    {
        return $this->status() === 'pending';
    }

    public function isProcessing()
    {
        return $this->status() === 'processing';
    }

    public function isShipped()
    {
        return $this->status() === 'shipped';
    }

    public function isDelivered()
    {
        return $this->status() === 'delivered';
    }

    public function isCancelled()
    {
        return $this->status() === 'cancelled';
    }

    public function isPaid()
    {
        return (bool) $this->entry->value('is_paid', false);
    }

    public function paymentMethod()
    {
        return $this->entry->value('payment_method');
    }

    public function paymentStatus()
    {
        return $this->entry->value('payment_status', 'pending');
    }

    public function shippingAddress()
    {
        return $this->entry->value('shipping_address');
    }

    public function billingAddress()
    {
        return $this->entry->value('billing_address');
    }

    public function notes()
    {
        return $this->entry->value('notes');
    }

    public function coupon()
    {
        return $this->entry->value('coupon');
    }

    public function createdAt()
    {
        return $this->entry->date() ? Carbon::parse($this->entry->date()) : Carbon::now();
    }

    public function updatedAt()
    {
        return $this->entry->lastModified() ? Carbon::parse($this->entry->lastModified()) : Carbon::now();
    }

    public function entry()
    {
        return $this->entry;
    }

    public function toArray()
    {
        return [
            'id' => $this->id(),
            'order_number' => $this->orderNumber(),
            'title' => $this->title(),
            'customer' => $this->customer(),
            'customer_email' => $this->customerEmail(),
            'customer_name' => $this->customerName(),
            'items' => $this->items(),
            'subtotal' => $this->subtotal(),
            'tax' => $this->tax(),
            'shipping' => $this->shipping(),
            'discount' => $this->discount(),
            'total' => $this->total(),
            'status' => $this->status(),
            'is_paid' => $this->isPaid(),
            'payment_method' => $this->paymentMethod(),
            'payment_status' => $this->paymentStatus(),
            'shipping_address' => $this->shippingAddress(),
            'billing_address' => $this->billingAddress(),
            'notes' => $this->notes(),
            'coupon' => $this->coupon(),
            'created_at' => $this->createdAt()->toIso8601String(),
            'updated_at' => $this->updatedAt()->toIso8601String(),
        ];
    }
}
