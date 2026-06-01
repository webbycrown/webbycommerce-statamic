<?php

namespace WebbyCrown\WebbyCommerceStatamic\Tags;

use Statamic\Tags\Tags;

class Wishlist extends Tags
{
    /**
     * The {{ wishlist:count }} tag.
     */
    public function count()
    {
        return app('wishlist')->count();
    }

    /**
     * The {{ wishlist:items }} tag.
     */
    public function items()
    {
        return app('wishlist')->items();
    }

    /**
     * The {{ wishlist:has }} tag.
     */
    public function has()
    {
        if ($this->params->has('product_id') || $this->params->has('product')) {
            $productId = $this->params->get('product_id') ?? $this->params->get('product');
            
            if ($productId === 'id' && $this->context) {
                $productId = $this->context->get('id');
            }
            
            if ($productId) {
                return app('wishlist')->has($productId);
            }
            
            return false;
        }

        return app('wishlist')->count() > 0;
    }
}
