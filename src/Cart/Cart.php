<?php

namespace WebbyCrown\WebbyCommerceStatamic\Cart;

use Illuminate\Support\Facades\Session;
use WebbyCrown\WebbyCommerceStatamic\Products\EntryProductRepository;
use WebbyCrown\WebbyCommerceStatamic\Coupons\EntryCouponRepository;
use WebbyCrown\WebbyCommerceStatamic\Tax\TaxManager;
use WebbyCrown\WebbyCommerceStatamic\Shipping\ShippingManager;
use Statamic\Facades\Entry;

class Cart
{
    protected EntryProductRepository $productRepository;
    protected EntryCouponRepository $couponRepository;
    protected TaxManager $taxManager;
    protected ShippingManager $shippingManager;

    public function __construct(
        EntryProductRepository $productRepository,
        EntryCouponRepository $couponRepository,
        TaxManager $taxManager,
        ShippingManager $shippingManager
    ) {
        $this->productRepository = $productRepository;
        $this->couponRepository = $couponRepository;
        $this->taxManager = $taxManager;
        $this->shippingManager = $shippingManager;
    }

    public function get(): array
    {
        $cart = Session::get($this->sessionKey(), [
            'items' => [],
            'coupon' => null,
        ]);

        // Handle transition from old structure where items were at the root
        if (!isset($cart['items'])) {
            $cart = [
                'items' => $cart,
                'coupon' => null,
            ];
            $this->putCart($cart);
        }

        if ($this->isExpired($cart)) {
            $this->clear();
            return [
                'items' => [],
                'coupon' => null,
            ];
        }

        return $cart;
    }

    public function items(): array
    {
        $cart = $this->get();
        $items = $cart['items'] ?? [];
        $shippingBreakdown = $this->calculateShippingBreakdown($items, $this->shipping());

        foreach ($items as $key => $item) {
            $items[$key]['total'] = $item['price'] * $item['quantity'];
            $items[$key]['key'] = $key;
            $items[$key]['shipping_charge'] = $shippingBreakdown[$key]['shipping_charge'] ?? 0.0;
            $items[$key]['shipping_per_unit'] = $shippingBreakdown[$key]['shipping_per_unit'] ?? 0.0;
            $items[$key]['total_with_shipping'] = round($items[$key]['total'] + $items[$key]['shipping_charge'], 2);
        }

        return array_values($items);
    }

    public function count(): int
    {
        return count($this->items());
    }

    public function totalQuantity(): int
    {
        return array_sum(array_column($this->items(), 'quantity'));
    }

    public function subtotal(): float
    {
        $subtotal = 0;
        foreach ($this->items() as $item) {
            $subtotal += $item['price'] * $item['quantity'];
        }
        return $subtotal;
    }

    public function taxRate(): float
    {
        $items = $this->items();
        if (empty($items)) {
            return $this->taxManager->engine()->getRateForShipping();
        }

        // Calculate effective weighted tax rate across all products
        $totalTax = $this->tax();
        $subtotal = $this->subtotal();

        if ($subtotal <= 0) {
            return 0;
        }

        return round($totalTax / $subtotal, 4);
    }

    public function isTaxIncluded(): bool
    {
        return $this->taxManager->engine()->isTaxIncludedInPrices();
    }

    public function tax(): float
    {
        try {
            $lineItems = [];
            foreach ($this->items() as $item) {
                $product = $this->productRepository->find($item['product_id']);
                if ($product) {
                    $lineItems[] = [
                        'product' => $product,
                        'quantity' => $item['quantity'],
                        'price' => $item['price'],
                    ];
                }
            }

            $breakdown = $this->taxManager->engine()->getTaxBreakdown($lineItems, $this->shipping());
            return $breakdown['total'];
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Tax calculation error: ' . $e->getMessage());
            return 0;
        }
    }

    public function shipping(): float
    {
        return $this->shippingManager->getSessionShippingCost();
    }

    public function shippingBreakdown(): array
    {
        return array_values($this->calculateShippingBreakdown($this->get()['items'] ?? [], $this->shipping()));
    }

    public function discount(): float
    {
        $coupon = $this->coupon();
        if (!$coupon) {
            return 0;
        }

        if (isset($coupon['discount'])) {
            return (float) $coupon['discount'];
        }

        $couponModel = $this->couponRepository->findByCode($coupon['code']);
        if (!$couponModel) {
            return 0;
        }

        return $couponModel->calculateDiscount($this->subtotal());
    }

    public function total(): float
    {
        return $this->chargeableTotal($this->subtotal(), $this->shipping(), $this->tax(), $this->discount());
    }

    /**
     * Amount the customer pays. Included tax is already in the subtotal, so it is not added again.
     */
    public function chargeableTotal(float $subtotal, float $shipping, float $tax, float $discount): float
    {
        $taxToAdd = $this->isTaxIncluded() ? 0.0 : $tax;

        return round($subtotal + $shipping + $taxToAdd - $discount, 2);
    }

    public function coupon(): ?array
    {
        return $this->get()['coupon'] ?? Session::get('checkout.coupon');
    }

    public function add(string $productId, int $quantity = 1, array $options = []): bool
    {
        $product = $this->productRepository->find($productId);
        if (!$product) {
            \Illuminate\Support\Facades\Log::warning("Cart::add - Product not found: {$productId}");
            return false;
        }

        if (!$product->isActive()) {
            \Illuminate\Support\Facades\Log::warning("Cart::add - Product not active: {$productId}");
            return false;
        }

        if ($product->isOutOfStock() && ! ($product->hasVariants() && isset($options['variant_index']))) {
            \Illuminate\Support\Facades\Log::warning("Cart::add - Product out of stock: {$productId}");
            return false;
        }

        $cart = $this->get();
        $itemKey = $this->getItemKey($productId, $options);
        $requested = $quantity + (int) ($cart['items'][$itemKey]['quantity'] ?? 0);
        $available = $product->availableQuantity($options);

        if ($available !== null && $requested > $available) {
            \Illuminate\Support\Facades\Log::warning("Cart::add - Quantity exceeds stock: {$productId}");
            return false;
        }
        $itemName  = $product->title();
        $itemSku   = $product->sku();
        $itemPrice = (float) $product->finalPrice();
        $itemImage = is_array($product->images()) ? ($product->images()[0] ?? null) : $product->images();
        $variantLabel = null;

        if ($product->hasVariants() && isset($options['variant_index'])) {
            $variant = $product->findVariant((int) $options['variant_index']);
            if ($variant) {
                $variantLabel = $variant['name'];
                $itemName     = $product->title() . ' – ' . $variant['name'];
                $itemSku      = $variant['sku'] ?: $itemSku;
                $itemPrice    = (float) $variant['price'];
                if (!empty($variant['image'])) {
                    $itemImage = $variant['image'];
                }
            }
        }

        $cart = $this->get();
        $itemKey = $this->getItemKey($productId, $options);

        if (isset($cart['items'][$itemKey])) {
            $cart['items'][$itemKey]['quantity'] += $quantity;
            $cart['items'][$itemKey]['name'] = $itemName;
            $cart['items'][$itemKey]['slug'] = $product->slug();
            $cart['items'][$itemKey]['sku'] = $itemSku;
            $cart['items'][$itemKey]['price'] = $itemPrice;
            $cart['items'][$itemKey]['image'] = $itemImage;
            $cart['items'][$itemKey]['options'] = $options;
            $cart['items'][$itemKey]['variant_name'] = $variantLabel;
        } else {
            $cart['items'][$itemKey] = [
                'product_id' => $productId,
                'name' => $itemName,
                'slug' => $product->slug(),
                'sku' => $itemSku,
                'price' => $itemPrice,
                'image' => $itemImage,
                'quantity' => $quantity,
                'options' => $options,
                'variant_name' => $variantLabel,
            ];
        }

        $this->putCart($cart);
        return true;
    }

    public function update(string $itemKey, int $quantity): bool
    {
        $cart = $this->get();

        if (!isset($cart['items'][$itemKey])) {
            return false;
        }

        if ($quantity <= 0) {
            unset($cart['items'][$itemKey]);
        } else {
            $item = $cart['items'][$itemKey];
            $product = $this->productRepository->find($item['product_id'] ?? '');
            if ($product) {
                $available = $product->availableQuantity($item['options'] ?? []);
                if ($available !== null && $quantity > $available) {
                    return false;
                }
            }

            $cart['items'][$itemKey]['quantity'] = $quantity;
        }

        $this->putCart($cart);
        return true;
    }

    public function remove(string $itemKey): bool
    {
        $cart = $this->get();

        if (!isset($cart['items'][$itemKey])) {
            return false;
        }

        unset($cart['items'][$itemKey]);
        $this->putCart($cart);
        return true;
    }

    public function clear(): void
    {
        Session::forget($this->sessionKey());
    }

    public function stockError(): ?string
    {
        $needed = [];

        foreach ($this->get()['items'] ?? [] as $item) {
            $productId = (string) ($item['product_id'] ?? '');
            $variant = $item['options']['variant_index'] ?? 'base';
            $bucket = $productId.'|'.$variant;
            $needed[$bucket]['qty'] = ($needed[$bucket]['qty'] ?? 0) + (int) ($item['quantity'] ?? 0);
            $needed[$bucket]['item'] = $item;
        }

        foreach ($needed as $row) {
            $item = $row['item'];
            $product = $this->productRepository->find($item['product_id'] ?? '');
            if (! $product) {
                return 'A product in your cart is no longer available.';
            }

            $available = $product->availableQuantity($item['options'] ?? []);
            if ($available !== null && $row['qty'] > $available) {
                $label = $item['name'] ?? $product->title();
                if ($available <= 0) {
                    return $label.' is out of stock.';
                }

                return $label.' only has '.$available.' available.';
            }
        }

        return null;
    }

    public function reserveStock(): bool
    {
        return $this->withStockLock(function () {
            if ($this->stockError() !== null) {
                return false;
            }

            return $this->applyStockDelta(-1);
        });
    }

    public function releaseStock(): bool
    {
        return $this->withStockLock(function () {
            return $this->applyStockDelta(1);
        });
    }

    protected function withStockLock(callable $callback): bool
    {
        $lock = null;

        try {
            $lock = \Illuminate\Support\Facades\Cache::lock('webbycommerce-stock', 15);
            $lock->block(5);
        } catch (\Throwable $e) {
            $lock = null;
        }

        try {
            return (bool) $callback();
        } finally {
            if ($lock) {
                try {
                    $lock->release();
                } catch (\Throwable $e) {
                    //
                }
            }
        }
    }

    protected function applyStockDelta(int $direction): bool
    {
        $applied = [];

        foreach ($this->get()['items'] ?? [] as $item) {
            $product = $this->productRepository->find($item['product_id'] ?? '');
            if (! $product) {
                $this->rollbackStockDelta($applied, $direction);

                return false;
            }

            $variantIndex = isset($item['options']['variant_index'])
                ? (int) $item['options']['variant_index']
                : null;
            $delta = $direction * (int) ($item['quantity'] ?? 0);

            if (! $product->adjustStock($delta, $variantIndex)) {
                $this->rollbackStockDelta($applied, $direction);

                return false;
            }

            $applied[] = [$product, $delta, $variantIndex];
        }

        return true;
    }

    protected function rollbackStockDelta(array $applied, int $direction): void
    {
        foreach (array_reverse($applied) as [$product, $delta, $variantIndex]) {
            $product->adjustStock(-$delta, $variantIndex);
        }
    }

    public function applyCoupon(string $code): array
    {
        $result = $this->couponRepository->validateCoupon($code, $this->subtotal());

        if ($result['valid']) {
            $cart = $this->get();
            $cart['coupon'] = [
                'code' => $result['coupon']->code(),
                'discount_type' => $result['coupon']->discountType(),
                'discount_value' => $result['coupon']->discountValue(),
            ];
            $this->putCart($cart);
        }

        return $result;
    }

    public function removeCoupon(): void
    {
        $cart = $this->get();
        $cart['coupon'] = null;
        $this->putCart($cart);
    }

    protected function sessionKey(): string
    {
        return config('webbycommerce.cart.session_key', 'cart');
    }

    protected function sessionExpiresAfter(): int
    {
        return (int) config('webbycommerce.cart.expires_after', 60 * 24 * 7);
    }

    protected function putCart(array $cart): void
    {
        $cart['updated_at'] = now()->toDateTimeString();
        Session::put($this->sessionKey(), $cart);
    }

    protected function isExpired(array $cart): bool
    {
        if (!isset($cart['updated_at']) || $this->sessionExpiresAfter() <= 0) {
            return false;
        }

        return now()->diffInMinutes(
            \Carbon\Carbon::parse($cart['updated_at'])
        ) >= $this->sessionExpiresAfter();
    }

    protected function getItemKey(string $productId, array $options): string
    {
        ksort($options);
        return md5($productId . serialize($options));
    }

    public function toArray(): array
    {
        $taxBreakdown = $this->getTaxBreakdown();

        return [
            'items' => $this->items(),
            'count' => $this->count(),
            'total_quantity' => $this->totalQuantity(),
            'subtotal' => $this->subtotal(),
            'tax' => $this->tax(),
            'tax_rate' => $this->taxRate(),
            'tax_breakdown' => $taxBreakdown,
            'shipping' => $this->shipping(),
            'shipping_breakdown' => $this->shippingBreakdown(),
            'discount' => $this->discount(),
            'total' => $this->total(),
            'coupon' => $this->coupon(),
        ];
    }

    public function getTaxBreakdown(): array
    {
        try {
            $lineItems = [];
            foreach ($this->items() as $item) {
                $product = $this->productRepository->find($item['product_id']);
                if ($product) {
                    $lineItems[] = [
                        'product' => $product,
                        'quantity' => $item['quantity'],
                        'price' => $item['price'],
                    ];
                }
            }

            return $this->taxManager->engine()->getTaxBreakdown($lineItems, $this->shipping());
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Tax breakdown error: ' . $e->getMessage());
            return [
                'line_items' => [],
                'shipping' => 0,
                'total' => 0,
            ];
        }
    }

    protected function calculateShippingBreakdown(array $items, float $shippingAmount): array
    {
        if (empty($items) || $shippingAmount <= 0) {
            return collect($items)
                ->mapWithKeys(fn ($item, $key) => [$key => [
                    'key' => $key,
                    'product_id' => $item['product_id'] ?? null,
                    'shipping_charge' => 0.0,
                    'shipping_per_unit' => 0.0,
                ]])
                ->all();
        }

        $lineTotals = [];
        $subtotal = 0.0;

        foreach ($items as $key => $item) {
            $lineTotal = (float) ($item['price'] ?? 0) * (int) ($item['quantity'] ?? 1);
            $lineTotals[$key] = $lineTotal;
            $subtotal += $lineTotal;
        }

        if ($subtotal <= 0) {
            return collect($items)
                ->mapWithKeys(fn ($item, $key) => [$key => [
                    'key' => $key,
                    'product_id' => $item['product_id'] ?? null,
                    'shipping_charge' => 0.0,
                    'shipping_per_unit' => 0.0,
                ]])
                ->all();
        }

        $breakdown = [];
        $allocated = 0.0;
        $lastKey = array_key_last($items);

        foreach ($items as $key => $item) {
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $lineShipping = $key === $lastKey
                ? round($shippingAmount - $allocated, 2)
                : round($shippingAmount * ($lineTotals[$key] / $subtotal), 2);

            $allocated += $lineShipping;

            $breakdown[$key] = [
                'key' => $key,
                'product_id' => $item['product_id'] ?? null,
                'name' => $item['name'] ?? null,
                'quantity' => $quantity,
                'line_total' => round($lineTotals[$key], 2),
                'shipping_charge' => $lineShipping,
                'shipping_per_unit' => round($lineShipping / $quantity, 2),
            ];
        }

        return $breakdown;
    }
}
