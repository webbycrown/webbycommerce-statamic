<?php

namespace WebbyCrown\WebbyCommerceStatamic\Tags;

use Statamic\Facades\Entry;
use Statamic\Tags\Tags;
use Statamic\Facades\Collection;

class ProductTag extends Tags
{
    public function index()
    {
        $products = Collection::find('products')?->queryEntries()->get() ?? collect();

        return [
            'results' => $products,
            'no_results' => $products->isEmpty(),
        ];
    }
}