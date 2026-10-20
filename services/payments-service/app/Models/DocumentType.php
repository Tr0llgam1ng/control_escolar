<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Paperwork a student can request: constancia de estudios, kárdex, inscripción, boleta... */
class DocumentType extends Model
{
    protected $fillable = ['code', 'name', 'description', 'price', 'is_active'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'is_active' => 'boolean'];
    }
}
