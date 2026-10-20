<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only audit trail: rows can be created, never updated or deleted. */
class ChargeAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['charge_id', 'action', 'from_status', 'to_status', 'actor_type', 'actor_id', 'reason', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Audit logs are append-only.'));
        static::deleting(fn () => throw new \LogicException('Audit logs are append-only.'));
    }
}
