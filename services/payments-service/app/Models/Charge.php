<?php

namespace App\Models;

use App\Payments\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A payment reference for one requested document.
 *
 *  pending  → the student has not paid yet and the reference is still alive
 *  approved → the payment was verified (card validated by the bank simulator, or cash received)
 *  rejected → the student never paid and the reference expired
 */
class Charge extends Model
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const STATUSES = [self::PENDING, self::APPROVED, self::REJECTED];

    public const CARD = 'card';
    public const CASH = 'cash';
    public const TRANSFER = 'transfer';

    public const STATUS_LABELS = ['pending' => 'Pendiente', 'approved' => 'Aprobado', 'rejected' => 'Rechazado'];
    public const METHOD_LABELS = ['card' => 'Tarjeta', 'cash' => 'Efectivo', 'transfer' => 'Transferencia'];

    // reference, student_id, amount and status are set only by ChargeService / PaymentService.
    protected $fillable = ['document_type_id', 'concept', 'expires_at', 'created_by'];

    protected $appends = ['status_label', 'method_label', 'is_expired', 'is_new'];

    protected function casts(): array
    {
        return [
            'student_id' => 'integer',
            'document_type_id' => 'integer',
            'amount' => 'decimal:2',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // The amount and the reference are immutable once the reference exists.
        static::updating(function (Charge $charge) {
            foreach (['amount', 'reference', 'student_id', 'currency', 'document_type_id'] as $field) {
                if ($charge->isDirty($field)) {
                    throw new \LogicException("charges.{$field} is immutable.");
                }
            }
        });
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class)->orderBy('id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(ChargeAuditLog::class)->orderBy('id');
    }

    public function amountCents(): int
    {
        return Money::toCents((string) $this->amount);
    }

    /** The reference is past its expiration date. */
    public function getIsExpiredAttribute(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Not reviewed yet by Control Escolar. */
    public function getIsNewAttribute(): bool
    {
        return $this->reviewed_at === null;
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function getMethodLabelAttribute(): ?string
    {
        return $this->payment_method ? (self::METHOD_LABELS[$this->payment_method] ?? $this->payment_method) : null;
    }

    /** Can still receive a payment: pending and not expired. */
    public function isPayable(): bool
    {
        return $this->status === self::PENDING && ! $this->is_expired;
    }

    public function hasApprovedPayment(): bool
    {
        return $this->transactions()->where('status', PaymentTransaction::APPROVED)->exists();
    }
}
