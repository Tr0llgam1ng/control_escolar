<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Concepts a student can pay:
 *  paperwork → constancia de estudios, kárdex, inscripción, boleta...
 *  credits   → credit packs; once paid, memberships-service exchanges them for credits.
 */
class DocumentType extends Model
{
    public const PAPERWORK = 'paperwork';
    public const CREDITS = 'credits';
    public const CATEGORIES = [self::PAPERWORK, self::CREDITS];

    protected $fillable = ['code', 'category', 'name', 'description', 'price', 'is_active'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'is_active' => 'boolean'];
    }
}
