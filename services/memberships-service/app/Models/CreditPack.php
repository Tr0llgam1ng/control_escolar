<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Money → credits exchange. code = payments-service document type code of the pack. */
class CreditPack extends Model
{
    protected $fillable = ['code', 'name', 'credits', 'price', 'is_active'];

    protected function casts(): array
    {
        return ['credits' => 'integer', 'price' => 'decimal:2', 'is_active' => 'boolean'];
    }
}
