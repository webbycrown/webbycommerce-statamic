<?php

namespace WebbyCrown\WebbyCommerceStatamic\Wishlist;

use Illuminate\Support\Facades\Session;
use WebbyCrown\WebbyCommerceStatamic\Products\EntryProductRepository;

class Wishlist
{
    protected EntryProductRepository $productRepository;

    protected string $sessionKey = 'wishlist';

    public function __construct(EntryProductRepository $productRepository)
    {
        $this->productRepository = $productRepository;
    }

    /**
     * Return the raw wishlist array from session.
     */
    public function get(): array
    {
        return Session::get($this->sessionKey, []);
    }

    /**
     * Return wishlist items as an indexed array with full product data.
     */
    public function items(): array
    {
        return array_values($this->get());
    }

    /**
     * Number of unique products in the wishlist.
     */
    public function count(): int
    {
        return count($this->get());
    }

    /**
     * Check whether a product is already in the wishlist.
     */
    public function has(string $productId): bool
    {
        return isset($this->get()[$productId]);
    }

    /**
     * Add a product to the wishlist.
     * Returns false if the product doesn't exist or is inactive.
     */
    public function add(string $productId): bool
    {
        if ($this->has($productId)) {
            return true; // already in wishlist — idempotent
        }

        $product = $this->productRepository->find($productId);
        if (!$product) {
            return false;
        }

        if (!$product->isActive()) {
            return false;
        }

        $wishlist = $this->get();
        $wishlist[$productId] = [
            'product_id' => $productId,
            'name'       => $product->title(),
            'slug'       => $product->slug(),
            'sku'        => $product->sku(),
            'price'      => (float) $product->finalPrice(),
            'image'      => is_array($product->images())
                                ? ($product->images()[0] ?? null)
                                : $product->images(),
        ];

        Session::put($this->sessionKey, $wishlist);
        return true;
    }

    /**
     * Remove a product from the wishlist.
     */
    public function remove(string $productId): bool
    {
        $wishlist = $this->get();

        if (!isset($wishlist[$productId])) {
            return false;
        }

        unset($wishlist[$productId]);
        Session::put($this->sessionKey, $wishlist);
        Session::save();
        return true;
    }

    /**
     * Toggle a product in/out of the wishlist.
     * Returns 'added' or 'removed'.
     */
    public function toggle(string $productId): string
    {
        if ($this->has($productId)) {
            $this->remove($productId);
            return 'removed';
        }

        $this->add($productId);
        return 'added';
    }

    public function clear(): void
    {
        Session::forget($this->sessionKey);
        Session::save();
    }

    /**
     * Serialise the wishlist for API/view consumption.
     */
    public function toArray(): array
    {
        return [
            'items' => $this->items(),
            'count' => $this->count(),
        ];
    }
}
