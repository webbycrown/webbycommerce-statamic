<?php

namespace WebbyCrown\WebbyCommerceStatamic\Shipping;

use Illuminate\Support\Str;

/**
 * Simple value-object wrapper for a shipping rate replicator row.
 *
 * Mimics the Entry API used by ShippingManager (id, slug, value).
 */
class ShippingRateItem
{
    protected array $data;
    protected string $id;

    public function __construct(array $data)
    {
        $this->data = $data;
        $this->id = $data['_id'] ?? Str::slug($data['name'] ?? 'shipping-' . Str::random(6));
    }

    /**
     * Get a field value by handle.
     */
    public function value(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    /**
     * Get the unique identifier.
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Get a URL-friendly slug.
     */
    public function slug(): string
    {
        return Str::slug($this->data['name'] ?? $this->id);
    }

    /**
     * Get raw data array.
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
