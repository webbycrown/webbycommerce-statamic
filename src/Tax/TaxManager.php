<?php

namespace WebbyCrown\WebbyCommerceStatamic\Tax;

use Illuminate\Support\Facades\App;

class TaxManager
{
    protected ?TaxEngine $engine = null;

    public function engine(): TaxEngine
    {
        // Always use the latest checkout.address to calculate location-based taxes.
        // If the engine is already resolved, reuse it; StandardTaxEngine reads address
        // from the session at calculation time.

        if ($this->engine === null) {
            $this->engine = $this->resolveEngine();
        }

        return $this->engine;
    }

    public function resetEngine(): void
    {
        $this->engine = null;
    }

    protected function resolveEngine(): TaxEngine
    {
        // Default to 'standard' engine which reads tax rates directly from each
        // product's tax_rate field in the Statamic CP — no config required.
        $taxConfig = config('webbycommerce.tax', []);
        $engineType = $taxConfig['engine'] ?? config('webbycommerce.tax.engine') ?: 'standard';
        $config = $taxConfig['engine_config'][$engineType] ?? config('webbycommerce.tax.engine_config.' . $engineType, []);

        // Provide built-in defaults for StandardTaxEngine so it works without config
        if (empty($config) && $engineType === 'standard') {
            $config = [
                'address' => 'shipping',
                'shipping_taxes' => false,
                'behaviour' => [
                    'no_address_provided' => 'default_address',
                    'no_rate_available' => 'use_default',
                ],
                'default_address' => [],
            ];
        }

        if (empty($config) && $engineType === 'basic') {
            $config = [
                'rate' => config('webbycommerce.tax.rate', 0),
                'included_in_prices' => config('webbycommerce.tax.included_in_prices', false),
                'shipping_taxes' => config('webbycommerce.tax.shipping_taxes', false),
            ];
        }

        return match($engineType) {
            'basic' => new BasicTaxEngine($config),
            'standard' => new Standard\StandardTaxEngine($config),
            default => new Standard\StandardTaxEngine($config),
        };
    }

    public function setEngine(TaxEngine $engine): void
    {
        $this->engine = $engine;
    }

    public function reset(): void
    {
        $this->engine = null;
    }
}
