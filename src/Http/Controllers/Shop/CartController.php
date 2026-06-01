<?php
 
namespace WebbyCrown\WebbyCommerceStatamic\Http\Controllers\Shop;
 
use WebbyCrown\WebbyCommerceStatamic\Cart\Cart;
use Statamic\Facades\Entry;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
 
class CartController
{
    protected Cart $cart;
 
    public function __construct(Cart $cart)
    {
        $this->cart = $cart;
    }
 
    public function index(): JsonResponse
    {
        $cartData = $this->cart->toArray();

        return response()->json([
            'success' => true,
            'next_step' => 'shipping',
            'cart' => $cartData,
        ]);
    }

    public function add(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|string',
            'quantity' => 'required|integer|min:1',
            'variant' => 'nullable|array',
        ]);

        $success = $this->cart->add(
            $validated['product_id'],
            $validated['quantity'],
            $validated['variant'] ?? []
        );

        if (! $success) {
            if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product could not be added to cart. It might be out of stock or inactive.',
                ], 400);
            }
            return redirect()->back()->with('error', 'Product could not be added to cart. It might be out of stock or inactive.');
        }

        // Automatically remove from wishlist once successfully added to cart
        app('wishlist')->remove($validated['product_id']);

        if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Product added to cart.',
                'cart_count' => $this->cart->totalQuantity(),
                'cart' => $this->cart->toArray(),
            ]);
        }

        return redirect()->back()->with('success', 'Product added to cart.');
    }
 
    public function update(Request $request)
    {
        $validated = $request->validate([
            'key' => 'required|string',
            'quantity' => 'required|integer',
        ]);
 
        $success = $this->cart->update($validated['key'], $validated['quantity']);
 
        if (!$success) {
            if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Item not found in cart.',
                ], 404);
            }
            return redirect()->back()->with('error', 'Item not found in cart.');
        }
 
        if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Cart updated.',
                'cart_total' => $this->cart->total(),
                'cart_count' => $this->cart->totalQuantity(),
            ]);
        }

        return redirect()->back()->with('success', 'Cart updated.');
    }
 
    public function remove(Request $request, $key)
    {
        $success = $this->cart->remove($key);
 
        if (!$success) {
            if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Item not found in cart.',
                ], 404);
            }
            return redirect()->back()->with('error', 'Item not found in cart.');
        }
 
        if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Item removed from cart.',
                'cart_total' => $this->cart->total(),
                'cart_count' => $this->cart->totalQuantity(),
            ]);
        }

        return redirect()->back()->with('success', 'Item removed from cart.');
    }

    public function count(): JsonResponse
    {
        return response()->json([
            'count' => $this->cart->totalQuantity(),
        ]);
    }
 
    public function getCartJson(): JsonResponse
    {
        $cartData = $this->cart->toArray();
        
        return response()->json([
            'cart' => array_values($cartData['items']),
            'count' => $cartData['total_quantity'],
            'total' => $cartData['total'],
        ]);
    }
}