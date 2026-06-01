<?php

namespace WebbyCrown\WebbyCommerceStatamic\Customers;

use Statamic\Facades\Entry;
use Illuminate\Support\Carbon;

class Customer
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

    public function title()
    {
        return $this->entry->value('title');
    }

    public function firstName()
    {
        return $this->entry->value('first_name');
    }

    public function lastName()
    {
        return $this->entry->value('last_name');
    }

    public function fullName()
    {
        return trim($this->firstName() . ' ' . $this->lastName());
    }

    public function email()
    {
        return $this->entry->value('email');
    }

    public function phone()
    {
        return $this->entry->value('phone');
    }

    public function status()
    {
        return $this->entry->value('status', 'active');
    }

    public function isActive()
    {
        return $this->status() === 'active';
    }

    public function notes()
    {
        return $this->entry->value('notes');
    }

    public function totalOrders()
    {
        return (int) $this->entry->value('total_orders', 0);
    }

    public function totalSpent()
    {
        return (float) $this->entry->value('total_spent', 0);
    }

    public function averageOrderValue()
    {
        return $this->totalOrders() > 0 ? $this->totalSpent() / $this->totalOrders() : 0;
    }

    public function lastOrderDate()
    {
        $date = $this->entry->value('last_order_date');
        return $date ? Carbon::parse($date) : null;
    }

    public function billingAddress()
    {
        return $this->entry->value('billing_address');
    }

    public function shippingAddress()
    {
        return $this->entry->value('shipping_address');
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
            'title' => $this->title(),
            'first_name' => $this->firstName(),
            'last_name' => $this->lastName(),
            'full_name' => $this->fullName(),
            'email' => $this->email(),
            'phone' => $this->phone(),
            'status' => $this->status(),
            'is_active' => $this->isActive(),
            'notes' => $this->notes(),
            'total_orders' => $this->totalOrders(),
            'total_spent' => $this->totalSpent(),
            'average_order_value' => $this->averageOrderValue(),
            'last_order_date' => $this->lastOrderDate()?->toIso8601String(),
            'billing_address' => $this->billingAddress(),
            'shipping_address' => $this->shippingAddress(),
            'created_at' => $this->createdAt()->toIso8601String(),
            'updated_at' => $this->updatedAt()->toIso8601String(),
        ];
    }
}
