<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LibrarySession extends Model
{
    public const OPEN = 'open';
    public const CLOSED = 'closed';

    protected $guarded = ['id'];

    protected $appends = ['elapsed_seconds', 'remaining_seconds', 'resource_name'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer', 'limit_seconds' => 'integer', 'used_seconds' => 'integer',
            'included_seconds' => 'integer', 'extra_seconds' => 'integer',
            'started_at' => 'datetime', 'ended_at' => 'datetime',
        ];
    }

    public function getElapsedSecondsAttribute(): int
    {
        if ($this->status === self::CLOSED) {
            return $this->used_seconds;
        }

        return min($this->limit_seconds, max(0, (int) $this->started_at->diffInSeconds(now(), false)));
    }

    public function getRemainingSecondsAttribute(): int
    {
        return $this->status === self::OPEN ? max(0, $this->limit_seconds - $this->elapsed_seconds) : 0;
    }

    public function getResourceNameAttribute(): string
    {
        return config("memberships.library.resources.{$this->resource}.name", $this->resource);
    }
}
