<?php

namespace WebbyCrown\WebbyCommerceStatamic\Tags;

use Illuminate\Support\Facades\File;
use Statamic\Tags\Tags;
use WebbyCrown\WebbyCommerceStatamic\Regions;
use WebbyCrown\WebbyCommerceStatamic\Countries;

class WebbyCommerce extends Tags
{
    public function countries()
    {
        $countries = Countries::map(function ($country) {
            return array_merge($country, [
                'regions' => Regions::findByCountry($country)->toArray(),
            ]);
        })->sortBy('name')->values();

        if ($inclusions = $this->params->explode('only', [])) {
            $countries = $countries
                ->filter(function ($country) use ($inclusions) {
                    return in_array($country['iso'], $inclusions)
                        || in_array($country['name'], $inclusions);
                })->sortBy(function ($country) use ($inclusions) {
                    return array_search($country['iso'], $inclusions);
                });
        } else {
            if ($exclusions = $this->params->explode('exclude', [])) {
                $countries = $countries->filter(function ($country) use ($exclusions) {
                    return ! (in_array($country['iso'], $exclusions)
                        || in_array($country['name'], $exclusions));
                });
            }

            if ($common = $this->params->explode('common', [])) {
                $commonCountries = $countries
                    ->filter(function ($country) use ($common) {
                        return in_array($country['iso'], $common)
                            || in_array($country['name'], $common);
                    })->sortBy(function ($country) use ($common) {
                        return array_search($country['iso'], $common);
                    });

                $commonCountries->push([
                    'iso' => '',
                    'name' => '-',
                ]);

                $countries = $commonCountries->concat($countries->filter(function ($country) use ($common) {
                    return ! (in_array($country['iso'], $common) || in_array($country['name'], $common));
                }));
            }
        }

        return $countries->map(function ($country) {
            return array_merge($country, [
                'name' => $country['name'],
            ]);
        })->toArray();
    }




    public function regions()
    {
        $regions = collect(Regions::all());

        if ($country = $this->params->get('country')) {
            $regions = $regions->where('country_iso', $country);
        }

        return $regions
            ->map(function ($region) {
                return array_merge($region, [
                    'country' => Countries::findByRegion($region)->first(),
                ]);
            })
            ->sortBy('name')
            ->toArray();
    }
}
