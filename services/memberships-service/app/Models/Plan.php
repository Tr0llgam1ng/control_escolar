<?php

namespace App\Models;

use App\Memberships\Features;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Basic (free) · Bronze · Silver · Gold. Bought with credits; lasts duration_days. */
class Plan extends Model
{
    public const BASIC = 'basic';

    protected $fillable = ['code', 'name', 'description', 'price_credits', 'duration_days', 'tier', 'color', 'is_active'];

    protected function casts(): array
    {
        return ['price_credits' => 'integer', 'duration_days' => 'integer', 'tier' => 'integer', 'is_active' => 'boolean'];
    }

    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class);
    }

    public function isBasic(): bool
    {
        return $this->code === self::BASIC;
    }

    public static function basic(): self
    {
        return static::where('code', self::BASIC)->firstOrFail();
    }

    public function feature(string $key): ?PlanFeature
    {
        return $this->relationLoaded('features')
            ? $this->features->firstWhere('feature', $key)
            : $this->features()->where('feature', $key)->first();
    }

    /** Plan with every feature of the registry, labelled (for the comparison table). */
    public function toPublicArray(): array
    {
        $rows = $this->features->keyBy('feature');

        return [
            ...$this->only(['id', 'code', 'name', 'description', 'price_credits', 'duration_days', 'tier', 'color', 'is_active']),
            'features' => collect(Features::DEFINITIONS)->map(function (array $def, string $key) use ($rows) {
                $row = $rows->get($key);

                return Features::describe($key, $row?->enabled ?? false, $row?->value);
            })->values()->all(),
        ];
    }
}
