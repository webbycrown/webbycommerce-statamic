<?php

namespace WebbyCrown\WebbyCommerceStatamic\Orders;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

class EntryOrderRepository
{
    protected string $collection = 'orders';

    public function all(): Collection
    {
        return Entry::query()
            ->where('collection', $this->collection)
            ->get()
            ->map(fn ($entry) => Order::fromEntry($entry));
    }

    public function find($id): ?Order
    {
        $entry = Entry::find($id);

        if (! $entry || $entry->collection()->handle() !== $this->collection) {
            return null;
        }

        return Order::fromEntry($entry);
    }

    public function findByOrderNumber(string $orderNumber): ?Order
    {
        $entry = Entry::query()
            ->where('collection', $this->collection)
            ->where('order_number', $orderNumber)
            ->first();

        return $entry ? Order::fromEntry($entry) : null;
    }

    public function findByCustomer(string $customerId): Collection
    {
        return Entry::query()
            ->where('collection', $this->collection)
            ->where('customer', $customerId)
            ->get()
            ->map(fn ($entry) => Order::fromEntry($entry));
    }

    public function query()
    {
        return new EntryOrderQueryBuilder($this->collection);
    }

    public function whereStatus(string $status): Collection
    {
        return Entry::query()
            ->where('collection', $this->collection)
            ->where('status', $status)
            ->get()
            ->map(fn ($entry) => Order::fromEntry($entry));
    }

    public function wherePaymentStatus(string $paymentStatus): Collection
    {
        return Entry::query()
            ->where('collection', $this->collection)
            ->where('payment_status', $paymentStatus)
            ->get()
            ->map(fn ($entry) => Order::fromEntry($entry));
    }

    public function whereIsPaid(bool $paid = true): Collection
    {
        return Entry::query()
            ->where('collection', $this->collection)
            ->where('is_paid', $paid)
            ->get()
            ->map(fn ($entry) => Order::fromEntry($entry));
    }

    public function search(string $term): Collection
    {
        return Entry::query()
            ->where('collection', $this->collection)
            ->where(function ($query) use ($term) {
                $query->where('order_number', 'like', '%'.$term.'%')
                    ->orWhere('customer_name', 'like', '%'.$term.'%')
                    ->orWhere('customer_email', 'like', '%'.$term.'%');
            })
            ->get()
            ->map(fn ($entry) => Order::fromEntry($entry));
    }

    public function make(): StatamicEntry
    {
        $entry = Entry::make()->collection($this->collection);

        $site = Site::current() ?? Site::default();
        if ($site) {
            $entry->locale($site);
        }

        return $entry;
    }

    public function save(Order $order): Order
    {
        $order->entry()->save();

        return $order;
    }

    public function delete(Order $order): bool
    {
        return $order->entry()->delete();
    }

    public function paginate(int $perPage = 50, $page = null)
    {
        return Entry::query()
            ->where('collection', $this->collection)
            ->orderBy('date', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    public function generateOrderNumber(): string
    {
        $prefix = config('webbycommerce.orders.number_prefix', 'ORD');
        $prefix = trim($prefix);
        $prefix = $prefix === '' ? 'ORD' : rtrim($prefix, '-');

        do {
            $number = $prefix.'-'.date('Y').'-'.strtoupper(Str::random(8));
        } while ($this->findByOrderNumber($number));

        return $number;
    }
}
