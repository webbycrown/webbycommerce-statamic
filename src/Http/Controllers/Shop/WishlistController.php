<?php

namespace WebbyCrown\WebbyCommerceStatamic\Http\Controllers\Shop;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use WebbyCrown\WebbyCommerceStatamic\Cart\Cart;
use WebbyCrown\WebbyCommerceStatamic\Wishlist\Wishlist;

class WishlistController
{
    protected Wishlist $wishlist;
    protected Cart $cart;

    public function __construct(Wishlist $wishlist, Cart $cart)
    {
        $this->wishlist = $wishlist;
        $this->cart     = $cart;
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'wishlist' => $this->wishlist->toArray(),
        ]);
    }

    /**
     * Add a product to the wishlist.
     * Accepts JSON or form POST.
     */
    public function add(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|string',
        ]);

        $success = $this->wishlist->add($validated['product_id']);

        if (!$success) {
            if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product could not be added to wishlist. It may not exist or is inactive.',
                ], 400);
            }
            return redirect()->back()->with('error', 'Product could not be added to wishlist. It may not exist or is inactive.');
        }

        if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
            return response()->json([
                'success'        => true,
                'message'        => 'Product added to wishlist.',
                'wishlist_count' => $this->wishlist->count(),
            ]);
        }

        return redirect()->back()->with('success', 'Product added to wishlist.');
    }

    /**
     * Remove a product from the wishlist.
     */
    public function remove(Request $request, string $productId)
    {
        $success = $this->wishlist->remove($productId);

        if (!$success) {
            if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product not found in wishlist.',
                ], 404);
            }
            return redirect()->back()->with('error', 'Product not found in wishlist.');
        }

        if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
            return response()->json([
                'success'        => true,
                'message'        => 'Product removed from wishlist.',
                'wishlist_count' => $this->wishlist->count(),
            ]);
        }

        return redirect()->back()->with('success', 'Product removed from wishlist.');
    }

    /**
     * Toggle a product in/out of the wishlist (add if absent, remove if present).
     */
    public function toggle(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|string',
        ]);

        $action = $this->wishlist->toggle($validated['product_id']);

        $message = $action === 'added'
            ? 'Product added to wishlist.'
            : 'Product removed from wishlist.';

        if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
            return response()->json([
                'success'        => true,
                'action'         => $action,
                'message'        => $message,
                'wishlist_count' => $this->wishlist->count(),
                'in_wishlist'    => $this->wishlist->has($validated['product_id']),
            ]);
        }

        return redirect()->back()->with('success', $message);
    }

    /**
     * Move a wishlist item to the cart, then remove it from the wishlist.
     */
    public function moveToCart(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|string',
            'quantity'   => 'sometimes|integer|min:1',
        ]);

        $productId = $validated['product_id'];
        $quantity  = $validated['quantity'] ?? 1;

        if (!$this->wishlist->has($productId)) {
            if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product not found in wishlist.',
                ], 404);
            }
            return redirect()->back()->with('error', 'Product not found in wishlist.');
        }

        $added = $this->cart->add($productId, $quantity);

        if (!$added) {
            if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product could not be added to cart. It may be out of stock or inactive.',
                ], 400);
            }
            return redirect()->back()->with('error', 'Product could not be added to cart. It may be out of stock or inactive.');
        }

        $this->wishlist->remove($productId);

        if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
            return response()->json([
                'success'        => true,
                'message'        => 'Product moved to cart.',
                'cart_count'     => $this->cart->totalQuantity(),
                'wishlist_count' => $this->wishlist->count(),
            ]);
        }

        return redirect()->back()->with('success', 'Product moved to cart.');
    }

    /**
     * Move all wishlist items to the cart, then clear the wishlist.
     */
    public function moveAllToCart(Request $request)
    {
        $items = $this->wishlist->items();

        if (empty($items)) {
            if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Wishlist is empty.',
                ], 400);
            }
            return redirect()->back()->with('error', 'Wishlist is empty.');
        }

        $addedCount = 0;
        foreach ($items as $item) {
            $added = $this->cart->add($item['product_id'], 1);
            if ($added) {
                $addedCount++;
            }
        }

        // Even if some failed, we clear the wishlist for the ones that succeeded.
        // Actually, let's just clear the entire wishlist if the user asked to move all.
        $this->wishlist->clear();

        if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
            return response()->json([
                'success'        => true,
                'message'        => "$addedCount product(s) moved to cart.",
                'cart_count'     => $this->cart->totalQuantity(),
                'wishlist_count' => $this->wishlist->count(),
            ]);
        }

        return redirect()->back()->with('success', "$addedCount product(s) moved to cart.");
    }

    /**
     * Clear all items from the wishlist.
     */
    public function clear(Request $request)
    {
        $this->wishlist->clear();

        if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Wishlist cleared.',
            ]);
        }

        return redirect()->back()->with('success', 'Wishlist cleared.');
    }

    /**
     * Return wishlist data as JSON (for API consumers / mini-widgets).
     */
    public function getWishlistJson(): JsonResponse
    {
        return response()->json($this->wishlist->toArray());
    }
}
