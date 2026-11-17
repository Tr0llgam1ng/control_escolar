<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 *  pending  → the payment reference has not been paid yet
 *  credited → paid; the credits (+ plan bonus) are in the wallet
 *  review   → paid, but something does not match (e.g. the amount): Control Escolar decides
 *  rejected → the reference expired or was rejected without payment
 */
class CreditPurchase extends Model
{
    public const PENDING = 'pending';
    public const CREDITED = 'credited';
    public const REVIEW = 'review';
    public const REJECTED = 'rejected';
    public const STATUSES = [self::PENDING, self::CREDITED, self::REVIEW, self::REJECTED];
    public const LABELS = ['pending' => 'Pendiente de pago', 'credited' => 'Acreditada', 'review' => 'En revisión', 'rejected' => 'Rechazada'];

    protected $guarded = ['id'];

    protected $appends = ['status_label', 'total_credits'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer', 'student_id' => 'integer', 'charge_id' => 'integer', 'credit_pack_id' => 'integer',
            'amount' => 'decimal:2', 'credits' => 'integer', 'bonus_credits' => 'integer', 'bonus_pct' => 'integer',
            'credited_at' => 'datetime', 'checked_at' => 'datetime',
        ];
    }

    public function pack(): BelongsTo
    {
        return $this->belongsTo(CreditPack::class, 'credit_pack_id');
    }

    public function getStatusLabelAttribute(): string
    {
        return self::LABELS[$this->status] ?? $this->status;
    }

    public function getTotalCreditsAttribute(): int
    {
        return $this->credits + $this->bonus_credits;
    }
}
