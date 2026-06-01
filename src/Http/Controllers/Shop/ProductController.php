<?php

namespace WebbyCrown\WebbyCommerceStatamic\Http\Controllers\Shop;

use WebbyCrown\WebbyCommerceStatamic\Products\EntryProductRepository;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ProductController
{
    protected EntryProductRepository $products;

    public function __construct(EntryProductRepository $products)
    {
        $this->products = $products;
    }

    public function index(Request $request): JsonResponse
    {
        $products = $this->products->all()
            ->filter(fn ($product) => $product->isActive())
            ->map(fn ($product) => $product->toArray())
            ->values();

        return response()->json([
            'success' => true,
            'products' => $products,
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $product = $this->products->findBySlug($slug);

        if (! $product || ! $product->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found.',
            ], 404);
        }

        $relatedProducts = $this->products->whereStatus('active')
            ->filter(fn ($relatedProduct) => $relatedProduct->isActive() && $relatedProduct->id() !== $product->id())
            ->take(4)
            ->map(fn ($relatedProduct) => $relatedProduct->toArray())
            ->values();

        return response()->json([
            'success' => true,
            'product' => $product->toArray(),
            'related_products' => $relatedProducts,
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $query = $request->get('q', '');

        $products = $query
            ? $this->products->search($query)->filter(fn ($product) => $product->isActive())
            : collect();

        return response()->json([
            'success' => true,
            'products' => $products->map(fn ($product) => $product->toArray())->values(),
            'query' => $query,
        ]);
    }

    public function quickView(string $id): JsonResponse
    {
        $product = $this->products->find($id);

        if (! $product || ! $product->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'product' => $product->toArray(),
        ]);
    }
}
