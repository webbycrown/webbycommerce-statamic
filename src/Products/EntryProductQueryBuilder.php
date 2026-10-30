<?php

namespace WebbyCrown\WebbyCommerceStatamic\Products;

use Statamic\Facades\Entry;
use Illuminate\Support\Collection;

class EntryProductQueryBuilder
{
    protected string $collection;
    protected $query;
    protected ?string $storeStatus = null;

    public function __construct(string $collection)
    {
        $this->collection = $collection;
        $this->query = Entry::query()->where('collection', $collection);
    }

    public function whereStatus(string $status): self
    {
        // Blueprint field, not Statamic publishing status.
        $this->storeStatus = $status;
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
        $this->storeStatus = 'active';
        $this->query->where(function ($query) {
            $query->where('track_stock', false)
                ->orWhere('track_inventory', false)
                ->orWhere('stock', '>', 0)
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
        return $this->entries()
            ->map(fn ($entry) => Product::fromEntry($entry));
    }

    public function first(): ?Product
    {
        $entry = $this->entries()->first();
        return $entry ? Product::fromEntry($entry) : null;
    }

    public function paginate(int $perPage = 50)
    {
        return $this->query->paginate($perPage);
    }

    public function count(): int
    {
        return $this->entries()->count();
    }

    public function exists(): bool
    {
        return $this->entries()->isNotEmpty();
    }

    protected function entries(): Collection
    {
        $entries = $this->query->get();

        if ($this->storeStatus !== null) {
            $entries = $entries
                ->filter(fn ($entry) => $entry->get('status') === $this->storeStatus)
                ->values();
        }

        return $entries;
    }
}
