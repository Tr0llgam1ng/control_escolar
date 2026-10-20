<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One payment attempt. Only the last 4 digits and the brand of a card are kept. */
class PaymentTransaction extends Model
{
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    protected $fillable = [
        'charge_id', 'method', 'status', 'amount', 'amount_received', 'change_given', 'test_card_id',
        'card_brand', 'card_last4', 'authorization_code', 'card_balance_after', 'decline_reason', 'recorded_by',
    ];

    /** The card balance stays in the bank simulator's records: nobody (student or admin) gets it through the API. */
    protected $hidden = ['test_card_id', 'card_balance_after'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'amount_received' => 'decimal:2',
            'change_given' => 'decimal:2',
            'card_balance_after' => 'decimal:2',
        ];
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }
}
