<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AccessToken extends Model
{
    protected $fillable = ['user_id', 'token_hash', 'expires_at', 'last_used_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'last_used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Creates a token and returns [plainToken, model]. Only the hash is stored. */
    public static function issueFor(User $user): array
    {
        $plain = Str::random(64);

        $token = static::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addMinutes((int) config('auth.token_ttl', 480)),
        ]);

        return [$plain, $token];
    }

    public static function findValid(string $plain): ?self
    {
        return static::with('user')
            ->where('token_hash', hash('sha256', $plain))
            ->where('expires_at', '>', now())
            ->first();
    }
}
