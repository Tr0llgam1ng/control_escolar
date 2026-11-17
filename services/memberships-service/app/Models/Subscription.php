<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 *  active   → paid and running until ends_at (auto_renew=false means "cancelled, ends at ends_at")
 *  replaced → the user changed to another plan (the unused part was refunded)
 *  expired  → it ended and was not renewed
 */
class Subscription extends Model
{
    public const ACTIVE = 'active';
    public const REPLACED = 'replaced';
    public const EXPIRED = 'expired';

    protected $fillable = ['user_id', 'plan_id', 'status', 'starts_at', 'ends_at', 'price_credits', 'auto_renew', 'cancelled_at', 'previous_id'];

    protected $appends = ['remaining_seconds'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer', 'plan_id' => 'integer', 'price_credits' => 'integer', 'auto_renew' => 'boolean',
            'starts_at' => 'datetime', 'ends_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function isRunning(): bool
    {
        return $this->status === self::ACTIVE && $this->ends_at->isFuture();
    }

    public function getRemainingSecondsAttribute(): int
    {
        return $this->ends_at ? max(0, (int) now()->diffInSeconds($this->ends_at, false)) : 0;
    }
}
