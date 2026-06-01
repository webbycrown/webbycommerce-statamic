<?php

namespace WebbyCrown\WebbyCommerceStatamic\Orders;

use Statamic\Facades\Entry;
use Illuminate\Support\Collection;

class EntryOrderQueryBuilder
{
    protected string $collection;
    protected $query;

    public function __construct(string $collection)
    {
        $this->collection = $collection;
        $this->query = Entry::query()->where('collection', $collection);
    }

    public function whereStatus(string $status): self
    {
        $this->query->where('status', $status);
        return $this;
    }

    public function wherePaymentStatus(string $paymentStatus): self
    {
        $this->query->where('payment_status', $paymentStatus);
        return $this;
    }

    public function whereIsPaid(bool $paid = true): self
    {
        $this->query->where('is_paid', $paid);
        return $this;
    }

    public function whereCustomer(string $customerId): self
    {
        $this->query->where('customer', $customerId);
        return $this;
    }

    public function search(string $term): self
    {
        $this->query->where(function ($query) use ($term) {
            $query->where('order_number', 'like', '%' . $term . '%')
                ->orWhere('customer_name', 'like', '%' . $term . '%')
                ->orWhere('customer_email', 'like', '%' . $term . '%');
        });
        return $this;
    }

    public function orderByDate(string $direction = 'desc'): self
    {
        $this->query->orderBy('date', $direction);
        return $this;
    }

    public function orderByTotal(string $direction = 'desc'): self
    {
        $this->query->orderBy('total', $direction);
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->query->limit($limit);
        return $this;
    }

    public function get(): Collection
    {
        return $this->query->get()
            ->map(fn ($entry) => Order::fromEntry($entry));
    }

    public function first(): ?Order
    {
        $entry = $this->query->first();
        return $entry ? Order::fromEntry($entry) : null;
    }

    public function paginate(int $perPage = 50)
    {
        return $this->query->paginate($perPage);
    }

    public function count(): int
    {
        return $this->query->count();
    }

    public function exists(): bool
    {
        return $this->query->exists();
    }
}
