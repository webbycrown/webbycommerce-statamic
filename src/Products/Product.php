<?php

namespace WebbyCrown\WebbyCommerceStatamic\Products;

use Statamic\Facades\Entry;
use Illuminate\Support\Carbon;

class Product
{
    protected $entry;

    public function __construct($entry)
    {
        $this->entry = $entry;
    }

    public static function fromEntry($entry)
    {
        return new self($entry);
    }

    public function id()
    {
        return $this->entry->id();
    }

    public function title()
    {
        return $this->entry->value('title');
    }

    public function slug()
    {
        return $this->entry->slug();
    }

    public function sku()
    {
        return $this->entry->value('sku');
    }

    public function price()
    {
        return (float) $this->entry->value('price', 0);
    }

    public function salePrice()
    {
        return $this->entry->value('sale_price') ? (float) $this->entry->value('sale_price') : null;
    }

    public function comparePrice()
    {
        return $this->entry->value('compare_price') ? (float) $this->entry->value('compare_price') : null;
    }

    public function costPrice()
    {
        return $this->entry->value('cost_price') ? (float) $this->entry->value('cost_price') : null;
    }

    public function finalPrice()
    {
        return $this->salePrice() ?? $this->price();
    }

    public function description()
    {
        return $this->entry->value('description');
    }

    public function shortDescription()
    {
        return $this->entry->value('short_description');
    }

    public function quantity()
    {
        foreach (['stock', 'quantity'] as $handle) {
            $value = $this->entry->value($handle);
            if ($value !== null && $value !== '') {
                return (int) $value;
            }
        }

        return 0;
    }

    public function trackInventory()
    {
        foreach (['track_stock', 'track_inventory'] as $handle) {
            $value = $this->entry->value($handle);
            if ($value !== null && $value !== '') {
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            }
        }

        // Blueprint default for Track Stock is on.
        return true;
    }

    /**
     * Units that can still be sold. Null means inventory is not tracked.
     */
    public function availableQuantity(array $options = []): ?int
    {
        if (! $this->trackInventory()) {
            return null;
        }

        if ($this->hasVariants() && array_key_exists('variant_index', $options)) {
            $variant = $this->findVariant((int) $options['variant_index']);
            if ($variant === null) {
                return 0;
            }

            if ($variant['stock'] !== null) {
                return (int) $variant['stock'];
            }
        }

        return $this->quantity();
    }

    /**
     * Change on-hand stock. Negative delta reserves or sells; positive restores.
     * Writes both stock and quantity so Control Panel and older reads stay aligned.
     */
    public function adjustStock(int $delta, ?int $variantIndex = null): bool
    {
        if (! $this->trackInventory() || $delta === 0) {
            return true;
        }

        $entry = $this->entry();

        if ($variantIndex !== null && $this->hasVariants()) {
            $variants = collect($entry->value('variants') ?? [])->values()->all();
            if (! isset($variants[$variantIndex]) || ! is_array($variants[$variantIndex])) {
                return false;
            }

            $current = (int) ($variants[$variantIndex]['stock'] ?? 0);
            $next = $current + $delta;
            if ($next < 0) {
                return false;
            }

            $variants[$variantIndex]['stock'] = $next;
            $entry->set('variants', $variants);

            return (bool) $entry->save();
        }

        $next = $this->quantity() + $delta;
        if ($next < 0) {
            return false;
        }

        $entry->set('stock', $next);
        $entry->set('quantity', $next);

        return (bool) $entry->save();
    }

    public function isOutOfStock()
    {
        return $this->trackInventory() && $this->quantity() <= 0;
    }

    public function isLowStock($threshold = 5)
    {
        return $this->trackInventory() && $this->quantity() > 0 && $this->quantity() <= $threshold;
    }

    public function featured()
    {
        return (bool) $this->entry->value('featured', false);
    }

    public function status()
    {
        return $this->entry->value('status', 'draft');
    }

    public function isActive()
    {
        return $this->status() === 'active' && $this->entry->published();
    }

    public function categories()
    {
        return $this->entry->value('categories') ?? [];
    }

    public function tags()
    {
        return $this->entry->value('tags') ?? [];
    }

    public function images()
    {
        return $this->normalizeImages($this->entry->value('images') ?: $this->entry->value('product_image'));
    }

    public function primaryImage()
    {
        return $this->images()[0] ?? null;
    }

    public function weight()
    {
        return $this->entry->value('weight') ? (float) $this->entry->value('weight') : null;
    }

    public function dimensions()
    {
        return [
            'length' => $this->entry->value('length') ? (float) $this->entry->value('length') : null,
            'width' => $this->entry->value('width') ? (float) $this->entry->value('width') : null,
            'height' => $this->entry->value('height') ? (float) $this->entry->value('height') : null,
        ];
    }

    public function taxClass()
    {
        return $this->entry->value('tax_class');
    }

    public function taxCategory()
    {
        return $this->entry->value('tax_category') ?: $this->entry->value('tax_class');
    }

    public function shippingClass()
    {
        return $this->entry->value('shipping_class');
    }

    public function hasVariants(): bool
    {
        return (bool) $this->entry->value('has_variants', false);
    }

    public function variantOptions(): array
    {
        if (!$this->hasVariants()) {
            return [];
        }

        $options = $this->entry->value('variant_options') ?? [];

        return collect($options)->map(function ($opt) {
            return [
                'name'   => $opt['option_name'] ?? '',
                'values' => array_map('trim', explode(',', $opt['option_values'] ?? '')),
            ];
        })->all();
    }

    public function variants(): array
    {
        if (!$this->hasVariants()) {
            return [];
        }

        return collect($this->entry->value('variants') ?? [])->map(function ($v, $index) {
            return [
                'index' => $index,
                'name'  => $v['name'] ?? '',
                'sku'   => $v['sku'] ?? $this->sku(),
                'price' => isset($v['price']) && $v['price'] !== '' && $v['price'] !== null
                    ? (float) $v['price']
                    : $this->price(),
                'stock' => isset($v['stock']) ? (int) $v['stock'] : null,
                'image' => $v['image'] ?? null,
            ];
        })->all();
    }

    public function findVariant(int $index): ?array
    {
        $variants = $this->variants();
        return $variants[$index] ?? null;
    }

    public function attributes()
    {
        return $this->entry->value('attributes') ?? [];
    }

    public function metaTitle()
    {
        return $this->entry->value('meta_title') ?? $this->title();
    }

    public function metaDescription()
    {
        return $this->entry->value('meta_description');
    }

    public function isTaxExempt()
    {
        return (bool) $this->entry->value('exempt_from_tax', false);
    }

    public function url()
    {
        return $this->entry->url();
    }

    public function apiUrl()
    {
        return $this->entry->apiUrl();
    }

    public function entry()
    {
        return $this->entry;
    }

    protected function normalizeImages($images): array
    {
        if (! $images) {
            return [];
        }

        if ($images instanceof \Statamic\Assets\Asset) {
            return [$images->url()];
        }

        if ($images instanceof \Illuminate\Support\Collection) {
            $images = $images->all();
        }

        if (! is_array($images)) {
            $images = [$images];
        }

        return collect($images)
            ->map(function ($image) {
                if ($image instanceof \Statamic\Assets\Asset) {
                    return $image->url();
                }

                if (is_array($image)) {
                    return $image['url'] ?? $image['path'] ?? $image['value'] ?? null;
                }

                return $image;
            })
            ->filter()
            ->values()
            ->all();
    }

    public function toArray()
    {
        return [
            'id' => $this->id(),
            'title' => $this->title(),
            'slug' => $this->slug(),
            'sku' => $this->sku(),
            'price' => $this->price(),
            'sale_price' => $this->salePrice(),
            'compare_price' => $this->comparePrice(),
            'final_price' => $this->finalPrice(),
            'description' => $this->description(),
            'short_description' => $this->shortDescription(),
            'quantity' => $this->quantity(),
            'stock' => $this->quantity(),
            'track_inventory' => $this->trackInventory(),
            'track_stock' => $this->trackInventory(),
            'is_out_of_stock' => $this->isOutOfStock(),
            'is_low_stock' => $this->isLowStock(),
            'featured' => $this->featured(),
            'status' => $this->status(),
            'is_active' => $this->isActive(),
            'categories' => $this->categories(),
            'tags' => $this->tags(),
            'images' => $this->images(),
            'weight' => $this->weight(),
            'dimensions' => $this->dimensions(),
            'tax_class' => $this->taxClass(),
            'tax_category' => $this->taxCategory(),
            'shipping_class' => $this->shippingClass(),
            'has_variants' => $this->hasVariants(),
            'variant_options' => $this->variantOptions(),
            'variants' => $this->variants(),
            'attributes' => $this->attributes(),
            'meta_title' => $this->metaTitle(),
            'meta_description' => $this->metaDescription(),
            'url' => $this->url(),
            'api_url' => $this->apiUrl(),
        ];
    }
}
