<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** requested → confirmed → completed · rejected (by the teacher) · cancelled (by the student) */
class TutoringSession extends Model
{
    public const REQUESTED = 'requested';
    public const CONFIRMED = 'confirmed';
    public const COMPLETED = 'completed';
    public const REJECTED = 'rejected';
    public const CANCELLED = 'cancelled';
    public const LABELS = ['requested' => 'Solicitada', 'confirmed' => 'Confirmada', 'completed' => 'Impartida', 'rejected' => 'Rechazada', 'cancelled' => 'Cancelada'];

    protected $guarded = ['id'];

    protected $appends = ['status_label'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer', 'teacher_id' => 'integer', 'duration_minutes' => 'integer', 'credits_cost' => 'integer',
            'discount_pct' => 'integer', 'refunded' => 'boolean', 'scheduled_at' => 'datetime', 'cycle_start' => 'datetime',
        ];
    }

    public function getStatusLabelAttribute(): string
    {
        return self::LABELS[$this->status] ?? $this->status;
    }
}
