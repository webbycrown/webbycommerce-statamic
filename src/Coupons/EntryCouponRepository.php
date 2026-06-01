<?php

namespace WebbyCrown\WebbyCommerceStatamic\Coupons;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

class EntryCouponRepository
{
    protected string $collection = 'coupons';

    public function all(): Collection
    {
        return Entry::query()
            ->where('collection', $this->collection)
            ->get()
            ->map(fn ($entry) => Coupon::fromEntry($entry));
    }

    public function find($id): ?Coupon
    {
        $entry = Entry::find($id);

        if (! $entry || $entry->collection()->handle() !== $this->collection) {
            return null;
        }

        return Coupon::fromEntry($entry);
    }

    public function findByCode(string $code): ?Coupon
    {
        $entry = Entry::query()
            ->where('collection', $this->collection)
            ->where('code', strtoupper($code))
            ->first();

        return $entry ? Coupon::fromEntry($entry) : null;
    }

    public function query()
    {
        return new EntryCouponQueryBuilder($this->collection);
    }

    public function whereActive(): Collection
    {
        return $this->all()->filter(fn ($coupon) => $coupon->isActive());
    }

    public function whereExpired(): Collection
    {
        return $this->all()->filter(fn ($coupon) => $coupon->isExpired());
    }

    public function whereFuture(): Collection
    {
        return $this->all()->filter(fn ($coupon) => $coupon->isFuture());
    }

    public function search(string $term): Collection
    {
        return Entry::query()
            ->where('collection', $this->collection)
            ->where(function ($query) use ($term) {
                $query->where('title', 'like', '%'.$term.'%')
                    ->orWhere('code', 'like', '%'.$term.'%')
                    ->orWhere('description', 'like', '%'.$term.'%');
            })
            ->get()
            ->map(fn ($entry) => Coupon::fromEntry($entry));
    }

    public function make(): StatamicEntry
    {
        return Entry::make()
            ->collection($this->collection)
            ->locale(Site::current() ?? Site::default());
    }

    public function save(Coupon $coupon): Coupon
    {
        $coupon->entry()->save();

        return $coupon;
    }

    public function delete(Coupon $coupon): bool
    {
        return $coupon->entry()->delete();
    }

    public function paginate(int $perPage = 50, $page = null)
    {
        return Entry::query()
            ->where('collection', $this->collection)
            ->orderBy('title', 'asc')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    public function generateCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while ($this->findByCode($code));

        return $code;
    }

    public function validateCoupon(string $code, float $subtotal = 0): array
    {
        $coupon = $this->findByCode($code);

        if (! $coupon) {
            return ['valid' => false, 'message' => 'Coupon not found.'];
        }

        if (! $coupon->isActive()) {
            if ($coupon->isExpired()) {
                return ['valid' => false, 'message' => 'Coupon has expired.'];
            }
            if ($coupon->isFuture()) {
                return ['valid' => false, 'message' => 'Coupon is not yet active.'];
            }
            if (! $coupon->hasRemainingUses()) {
                return ['valid' => false, 'message' => 'Coupon has reached its usage limit.'];
            }

            return ['valid' => false, 'message' => 'Coupon is not active.'];
        }

        if ($coupon->minimumAmount() > 0 && $subtotal < $coupon->minimumAmount()) {
            return ['valid' => false, 'message' => 'Minimum order amount required.'];
        }

        $discount = $coupon->calculateDiscount($subtotal);

        return [
            'valid' => true,
            'coupon' => $coupon,
            'discount' => $discount,
            'message' => 'Coupon applied successfully!',
        ];
    }
}
