<?php

namespace WebbyCrown\WebbyCommerceStatamic\Tags;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Session;
use Statamic\Tags\Tags;

class Checkout extends Tags
{
    public function field()
    {
        $key = $this->params->get('key');
        $default = $this->params->get('default', '');

        if (! $key) {
            return $default;
        }

        $old = old(null, []);
        $oldValue = Arr::get($old, $key);

        if ($oldValue !== null) {
            return $oldValue;
        }

        $sessionValue = Arr::get(Session::get('checkout.address', []), $key);

        if ($sessionValue !== null) {
            return $sessionValue;
        }

        if ($key === 'email' && ($user = auth()->user())) {
            return $user->email ?? $default;
        }

        return $default === '{user:email}' ? '' : $default;
    }

    public function payment()
    {
        $key = $this->params->get('key');
        $default = $this->params->get('default', '');

        if (! $key) {
            return $default;
        }

        return Arr::get(old(null, []), $key, Arr::get(Session::get('checkout.payment', []), $key, $default));
    }

    public function couponCode()
    {
        return old('coupon_code', Arr::get(Session::get('checkout.coupon', []), 'code', ''));
    }

    public function couponDiscount()
    {
        return (float) Arr::get(Session::get('checkout.coupon', []), 'discount', 0);
    }

    public function shippingSameAsBilling()
    {
        return filter_var(
            old('shipping_same_as_billing', Arr::get(Session::get('checkout.address', []), 'shipping_same_as_billing', true)),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    public function countries()
    {
        return $this->loadJson('countries');
    }

    public function states()
    {
        $countryParam = trim((string) $this->params->get('country', ''));
        $countryCode = strtoupper($countryParam);
        $states = $this->loadJson('state');

        if ($countryCode === '') {
            return $states;
        }

        $countries = $this->loadJson('countries');
        foreach ($countries as $country) {
            if (strtoupper($country['iso'] ?? '') === $countryCode || strtoupper($country['name'] ?? '') === $countryCode) {
                $countryCode = strtoupper($country['iso']);
                break;
            }
        }

        return array_values(array_filter($states, fn ($state) => strtoupper($state['country_iso'] ?? '') === $countryCode));
    }

    protected function loadJson(string $name): array
    {
        $path = dirname(__DIR__, 2) . "/resources/json/{$name}.json";

        if (! file_exists($path)) {
            return [];
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return [];
        }

        return json_decode($contents, true) ?? [];
    }
}
