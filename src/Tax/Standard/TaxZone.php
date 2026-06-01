<?php

namespace WebbyCrown\WebbyCommerceStatamic\Tax\Standard;

use Statamic\Entries\Entry;

class TaxZone
{
    protected Entry $entry;

    public function __construct(Entry $entry)
    {
        $this->entry = $entry;
    }

    public function entry(): Entry
    {
        return $this->entry;
    }

    public function id(): string
    {
        return $this->entry->id();
    }

    protected function value(string $key)
    {
        return $this->entry->value($key);
    }

    public function country(): ?string
    {
        $c = $this->value('country');
        if ($c === null) return null;
        $c = trim((string) $c);
        return $c === '' ? null : strtoupper($c);
    }

    public function region(): ?string
    {
        $r = $this->value('region');
        if ($r === null) return null;
        $r = trim((string) $r);
        return $r === '' ? null : strtoupper($r);
    }

    /**
     * Returns true if this zone matches the provided address array.
     *
     * Matching Logic:
     * 1. If zone has country: must match address country (and region if zone has region)
     * 2. If zone has only region (no country): checked after country logic
     *
     * Address keys expected: country, region (or state), postal_code, city
     */
    public function matches(array $address): bool
    {
        $zoneCountry = $this->country();
        $zoneRegion = $this->region();

        if (!$zoneCountry && !$zoneRegion) {
            return true;
        }

        $country = isset($address['country']) ? strtoupper(trim((string) $address['country'])) : null;

        if ($zoneCountry) {
            if (!$country || $zoneCountry !== $country) {
                return false;
            }
        }

        if (!$zoneRegion) {
            return true;
        }

        $addrRegion = isset($address['region']) ? $address['region'] : ($address['state'] ?? null);
        $addrRegion = $addrRegion ? strtoupper(trim((string) $addrRegion)) : null;

        if (!$addrRegion) {
            return false;
        }

        return $addrRegion === $zoneRegion;
    }
}
