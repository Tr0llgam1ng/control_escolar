<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One line of the credits ledger. Never updated or deleted. */
class CreditTransaction extends Model
{
    public const PURCHASE = 'purchase';
    public const BONUS = 'bonus';
    public const SPEND = 'spend';
    public const REFUND = 'refund';
    public const ADJUSTMENT = 'adjustment';
    public const TYPES = [self::PURCHASE, self::BONUS, self::SPEND, self::REFUND, self::ADJUSTMENT];

    public const LABELS = ['purchase' => 'Compra', 'bonus' => 'Regalo', 'spend' => 'Consumo', 'refund' => 'Reembolso', 'adjustment' => 'Ajuste'];

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $appends = ['type_label'];

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'amount' => 'integer', 'balance_after' => 'integer', 'source_id' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('credit_transactions is append-only.'));
        static::deleting(fn () => throw new \LogicException('credit_transactions is append-only.'));
    }

    public function getTypeLabelAttribute(): string
    {
        return self::LABELS[$this->type] ?? $this->type;
    }
}
