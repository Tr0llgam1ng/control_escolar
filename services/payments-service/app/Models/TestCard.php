<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A card of the local bank simulator (test data only, never real cards).
 * The CVV is stored hashed; the number is masked whenever the card is serialized.
 */
class TestCard extends Model
{
    public const ACTIVE = 'active';
    public const BLOCKED = 'blocked';

    protected $fillable = ['number', 'holder_name', 'brand', 'exp_month', 'exp_year', 'cvv_hash', 'balance', 'status', 'notes'];

    protected $hidden = ['number', 'cvv_hash', 'balance'];

    protected $appends = ['masked_number'];

    protected function casts(): array
    {
        return ['balance' => 'decimal:2', 'exp_month' => 'integer', 'exp_year' => 'integer'];
    }

    public function getMaskedNumberAttribute(): string
    {
        return '•••• '.substr((string) $this->number, -4);
    }

    /** The card expires at the end of its expiration month. */
    public function isExpired(): bool
    {
        return now()->greaterThan(now()->setDate($this->exp_year, $this->exp_month, 1)->endOfMonth());
    }
}
