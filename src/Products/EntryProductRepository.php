<?php

namespace WebbyCrown\WebbyCommerceStatamic\Products;

use Illuminate\Support\Collection;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

class EntryProductRepository
{
    protected string $collection = 'products';

    public function all(): Collection
    {
        return Entry::query()
            ->where('collection', $this->collection)
            ->get()
            ->map(fn ($entry) => Product::fromEntry($entry));
    }

    public function find($id): ?Product
    {
        $entry = Entry::find($id);

        if (! $entry || $entry->collection()->handle() !== $this->collection) {
            return null;
        }

        return Product::fromEntry($entry);
    }

    public function findBySlug(string $slug): ?Product
    {
        $entry = Entry::query()
            ->where('collection', $this->collection)
            ->where('slug', $slug)
            ->first();

        return $entry ? Product::fromEntry($entry) : null;
    }

    public function findBySku(string $sku): ?Product
    {
        $entry = Entry::query()
            ->where('collection', $this->collection)
            ->where('sku', $sku)
            ->first();

        return $entry ? Product::fromEntry($entry) : null;
    }

    public function query()
    {
        return new EntryProductQueryBuilder($this->collection);
    }

    public function whereStatus(string $status): Collection
    {
        return Entry::query()
            ->where('collection', $this->collection)
            ->where('status', $status)
            ->get()
            ->map(fn ($entry) => Product::fromEntry($entry));
    }

    public function whereFeatured(bool $featured = true): Collection
    {
        return Entry::query()
            ->where('collection', $this->collection)
            ->where('featured', $featured)
            ->get()
            ->map(fn ($entry) => Product::fromEntry($entry));
    }

    public function whereCategory(string $category): Collection
    {
        return Entry::query()
            ->where('collection', $this->collection)
            ->where('categories', 'like', '%'.$category.'%')
            ->get()
            ->map(fn ($entry) => Product::fromEntry($entry));
    }

    public function whereInStock(): Collection
    {
        return Entry::query()
            ->where('collection', $this->collection)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->where('track_inventory', false)
                    ->orWhere('quantity', '>', 0);
            })
            ->get()
            ->map(fn ($entry) => Product::fromEntry($entry));
    }

    public function search(string $term): Collection
    {
        return Entry::query()
            ->where('collection', $this->collection)
            ->where(function ($query) use ($term) {
                $query->where('title', 'like', '%'.$term.'%')
                    ->orWhere('sku', 'like', '%'.$term.'%')
                    ->orWhere('description', 'like', '%'.$term.'%');
            })
            ->get()
            ->map(fn ($entry) => Product::fromEntry($entry));
    }

    public function make(): StatamicEntry
    {
        return Entry::make()
            ->collection($this->collection)
            ->locale(Site::current() ?? Site::default());
    }

    public function save(Product $product): Product
    {
        $product->entry()->save();

        return $product;
    }

    public function delete(Product $product): bool
    {
        return $product->entry()->delete();
    }

    public function paginate(int $perPage = 50, $page = null)
    {
        return Entry::query()
            ->where('collection', $this->collection)
            ->orderBy('title', 'asc')
            ->paginate($perPage, ['*'], 'page', $page);
    }
}
