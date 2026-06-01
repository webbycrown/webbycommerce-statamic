<?php

namespace WebbyCrown\WebbyCommerceStatamic\Customers;

use Illuminate\Support\Collection;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

class EntryCustomerRepository
{
    protected string $collection = 'customers';

    public function all(): Collection
    {
        return Entry::query()
            ->where('collection', $this->collection)
            ->get()
            ->map(fn ($entry) => Customer::fromEntry($entry));
    }

    public function find($id): ?Customer
    {
        $entry = Entry::find($id);

        if (! $entry || $entry->collection()->handle() !== $this->collection) {
            return null;
        }

        return Customer::fromEntry($entry);
    }

    public function findByEmail(string $email): ?Customer
    {
        $entry = Entry::query()
            ->where('collection', $this->collection)
            ->where('email', $email)
            ->first();

        return $entry ? Customer::fromEntry($entry) : null;
    }

    public function query()
    {
        return new EntryCustomerQueryBuilder($this->collection);
    }

    public function whereStatus(string $status): Collection
    {
        return Entry::query()
            ->where('collection', $this->collection)
            ->where('status', $status)
            ->get()
            ->map(fn ($entry) => Customer::fromEntry($entry));
    }

    public function search(string $term): Collection
    {
        return Entry::query()
            ->where('collection', $this->collection)
            ->where(function ($query) use ($term) {
                $query->where('first_name', 'like', '%'.$term.'%')
                    ->orWhere('last_name', 'like', '%'.$term.'%')
                    ->orWhere('email', 'like', '%'.$term.'%')
                    ->orWhere('title', 'like', '%'.$term.'%');
            })
            ->get()
            ->map(fn ($entry) => Customer::fromEntry($entry));
    }

    public function make(): StatamicEntry
    {
        return Entry::make()
            ->collection($this->collection)
            ->locale(Site::current() ?? Site::default());
    }

    public function save(Customer $customer): Customer
    {
        $customer->entry()->save();

        return $customer;
    }

    public function delete(Customer $customer): bool
    {
        return $customer->entry()->delete();
    }

    public function paginate(int $perPage = 50, $page = null)
    {
        return Entry::query()
            ->where('collection', $this->collection)
            ->orderBy('title', 'asc')
            ->paginate($perPage, ['*'], 'page', $page);
    }
}
