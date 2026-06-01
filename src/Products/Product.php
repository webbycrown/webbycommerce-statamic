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
        return (int) $this->entry->value('quantity', 0);
    }

    public function trackInventory()
    {
        return (bool) $this->entry->value('track_inventory', false);
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
            'cost_price' => $this->costPrice(),
            'final_price' => $this->finalPrice(),
            'description' => $this->description(),
            'short_description' => $this->shortDescription(),
            'quantity' => $this->quantity(),
            'track_inventory' => $this->trackInventory(),
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
