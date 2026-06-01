<?php

namespace WebbyCrown\WebbyCommerceStatamic\Coupons;

use Statamic\Facades\Entry;
use Illuminate\Support\Carbon;

class Coupon
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

    public function code()
    {
        return $this->entry->value('code');
    }

    public function description()
    {
        return $this->entry->value('description');
    }

    public function discountType()
    {
        return $this->entry->value('type') ?? $this->entry->value('discount_type', 'fixed');
    }

    public function discountValue()
    {
        return (float) ($this->entry->value('value') ?? $this->entry->value('discount_value', 0));
    }

    public function minimumAmount()
    {
        return (float) $this->entry->value('minimum_amount', 0);
    }

    public function maximumUses()
    {
        $value = $this->entry->value('use_limit') ?? $this->entry->value('maximum_uses');

        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    public function usedCount()
    {
        return (int) ($this->entry->value('redeemed_count') ?? $this->entry->value('used_count', 0));
    }

    public function remainingUses()
    {
        if ($this->maximumUses() === null) {
            return null;
        }
        return max(0, $this->maximumUses() - $this->usedCount());
    }

    public function hasRemainingUses()
    {
        if ($this->maximumUses() === null) {
            return true;
        }
        return $this->remainingUses() > 0;
    }

    public function startsAt()
    {
        $date = $this->entry->value('starts_at');
        return $date ? Carbon::parse($date) : null;
    }

    public function expiresAt()
    {
        $date = $this->entry->value('expires_at');
        return $date ? Carbon::parse($date) : null;
    }

    public function isActive()
    {
        $now = Carbon::now();
        
        if (!$this->entry->published()) {
            return false;
        }

        if ($this->startsAt() && $this->startsAt()->isFuture()) {
            return false;
        }

        if ($this->expiresAt() && $this->expiresAt()->isPast()) {
            return false;
        }

        if (!$this->hasRemainingUses()) {
            return false;
        }

        return true;
    }

    public function isExpired()
    {
        return $this->expiresAt() && $this->expiresAt()->isPast();
    }

    public function isFuture()
    {
        return $this->startsAt() && $this->startsAt()->isFuture();
    }

    public function calculateDiscount(float $subtotal): float
    {
        if ($this->minimumAmount() > 0 && $subtotal < $this->minimumAmount()) {
            return 0;
        }

        if ($this->discountType() === 'percentage') {
            return $subtotal * ($this->discountValue() / 100);
        }

        return min($this->discountValue(), $subtotal);
    }

    public function entry()
    {
        return $this->entry;
    }

    public function toArray()
    {
        return [
            'id' => $this->id(),
            'title' => $this->title(),
            'code' => $this->code(),
            'description' => $this->description(),
            'discount_type' => $this->discountType(),
            'discount_value' => $this->discountValue(),
            'minimum_amount' => $this->minimumAmount(),
            'maximum_uses' => $this->maximumUses(),
            'used_count' => $this->usedCount(),
            'remaining_uses' => $this->remainingUses(),
            'starts_at' => $this->startsAt()?->toIso8601String(),
            'expires_at' => $this->expiresAt()?->toIso8601String(),
            'is_active' => $this->isActive(),
            'is_expired' => $this->isExpired(),
            'is_future' => $this->isFuture(),
        ];
    }
}
