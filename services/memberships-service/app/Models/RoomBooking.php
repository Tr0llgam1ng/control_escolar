<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomBooking extends Model
{
    public const BOOKED = 'booked';
    public const CANCELLED = 'cancelled';

    protected $guarded = ['id'];

    protected $appends = ['is_past', 'is_current'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer', 'study_room_id' => 'integer', 'minutes' => 'integer',
            'included_seconds' => 'integer', 'extra_seconds' => 'integer',
            'starts_at' => 'datetime', 'ends_at' => 'datetime', 'cycle_start' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(StudyRoom::class, 'study_room_id');
    }

    public function getIsPastAttribute(): bool
    {
        return $this->ends_at->isPast();
    }

    public function getIsCurrentAttribute(): bool
    {
        return $this->status === self::BOOKED && $this->starts_at->isPast() && $this->ends_at->isFuture();
    }
}
