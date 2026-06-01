<?php

namespace WebbyCrown\WebbyCommerceStatamic\Coupons;

use Statamic\Facades\Entry;
use Illuminate\Support\Collection;

class EntryCouponQueryBuilder
{
    protected string $collection;
    protected $query;

    public function __construct(string $collection)
    {
        $this->collection = $collection;
        $this->query = Entry::query()->where('collection', $collection);
    }

    public function whereCode(string $code): self
    {
        $this->query->where('code', strtoupper($code));
        return $this;
    }

    public function whereDiscountType(string $type): self
    {
        $this->query->where('discount_type', $type);
        return $this;
    }

    public function search(string $term): self
    {
        $this->query->where(function ($query) use ($term) {
            $query->where('title', 'like', '%' . $term . '%')
                ->orWhere('code', 'like', '%' . $term . '%')
                ->orWhere('description', 'like', '%' . $term . '%');
        });
        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->query->orderBy($column, $direction);
        return $this;
    }

    public function orderByDiscountValue(string $direction = 'desc'): self
    {
        $this->query->orderBy('discount_value', $direction);
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
            ->map(fn ($entry) => Coupon::fromEntry($entry));
    }

    public function first(): ?Coupon
    {
        $entry = $this->query->first();
        return $entry ? Coupon::fromEntry($entry) : null;
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
