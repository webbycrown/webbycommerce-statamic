<?php

namespace WebbyCrown\WebbyCommerceStatamic\Helpers;

use WebbyCrown\WebbyCommerceStatamic\Products\EntryProductRepository;

class InventoryHelper
{
    protected EntryProductRepository $productRepository;

    public function __construct(EntryProductRepository $productRepository)
    {
        $this->productRepository = $productRepository;
    }

    public function checkStock(string $productId, int $quantity): bool
    {
        $product = $this->productRepository->find($productId);
        
        if (!$product) {
            return false;
        }

        if (!$product->trackInventory()) {
            return true;
        }

        return $product->quantity() >= $quantity;
    }

    public function updateStock(string $productId, int $quantity, string $operation = 'subtract'): bool
    {
        $product = $this->productRepository->find($productId);
        
        if (!$product) {
            return false;
        }

        if (!$product->trackInventory()) {
            return true;
        }

        $currentQuantity = $product->quantity();
        
        if ($operation === 'subtract') {
            $newQuantity = max(0, $currentQuantity - $quantity);
        } elseif ($operation === 'add') {
            $newQuantity = $currentQuantity + $quantity;
        } else {
            $newQuantity = $quantity;
        }

        $data = $product->entry()->data()->all();
        $data['quantity'] = $newQuantity;
        
        $product->entry()->data($data)->save();

        return true;
    }

    public function getLowStockProducts(int $threshold = 5): array
    {
        $products = $this->productRepository->all();
        
        return $products->filter(fn($product) => $product->isLowStock($threshold))->toArray();
    }

    public function getOutOfStockProducts(): array
    {
        $products = $this->productRepository->all();
        
        return $products->filter(fn($product) => $product->isOutOfStock())->toArray();
    }

    public function getStockStatus(string $productId): string
    {
        $product = $this->productRepository->find($productId);
        
        if (!$product) {
            return 'unknown';
        }

        if (!$product->trackInventory()) {
            return 'unlimited';
        }

        if ($product->isOutOfStock()) {
            return 'out_of_stock';
        }

        if ($product->isLowStock()) {
            return 'low_stock';
        }

        return 'in_stock';
    }
}
