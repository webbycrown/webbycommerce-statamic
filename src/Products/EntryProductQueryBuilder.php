<?php

namespace WebbyCrown\WebbyCommerceStatamic\Products;

use Statamic\Facades\Entry;
use Illuminate\Support\Collection;

class EntryProductQueryBuilder
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

    public function whereFeatured(bool $featured = true): self
    {
        $this->query->where('featured', $featured);
        return $this;
    }

    public function whereCategory(string $category): self
    {
        $this->query->where('categories', 'like', '%' . $category . '%');
        return $this;
    }

    public function whereInStock(): self
    {
        $this->query->where('status', 'active')
            ->where(function ($query) {
                $query->where('track_inventory', false)
                    ->orWhere('quantity', '>', 0);
            });
        return $this;
    }

    public function search(string $term): self
    {
        $this->query->where(function ($query) use ($term) {
            $query->where('title', 'like', '%' . $term . '%')
                ->orWhere('sku', 'like', '%' . $term . '%')
                ->orWhere('description', 'like', '%' . $term . '%');
        });
        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->query->orderBy($column, $direction);
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
            ->map(fn ($entry) => Product::fromEntry($entry));
    }

    public function first(): ?Product
    {
        $entry = $this->query->first();
        return $entry ? Product::fromEntry($entry) : null;
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
