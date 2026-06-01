<?php

namespace WebbyCrown\WebbyCommerceStatamic\Customers;

use Statamic\Facades\Entry;
use Illuminate\Support\Collection;

class EntryCustomerQueryBuilder
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

    public function search(string $term): self
    {
        $this->query->where(function ($query) use ($term) {
            $query->where('first_name', 'like', '%' . $term . '%')
                ->orWhere('last_name', 'like', '%' . $term . '%')
                ->orWhere('email', 'like', '%' . $term . '%')
                ->orWhere('title', 'like', '%' . $term . '%');
        });
        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->query->orderBy($column, $direction);
        return $this;
    }

    public function orderByTotalSpent(string $direction = 'desc'): self
    {
        $this->query->orderBy('total_spent', $direction);
        return $this;
    }

    public function orderByTotalOrders(string $direction = 'desc'): self
    {
        $this->query->orderBy('total_orders', $direction);
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
            ->map(fn ($entry) => Customer::fromEntry($entry));
    }

    public function first(): ?Customer
    {
        $entry = $this->query->first();
        return $entry ? Customer::fromEntry($entry) : null;
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
