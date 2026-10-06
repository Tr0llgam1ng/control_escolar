<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Model
{
    use HasFactory;

    public const ROLES = ['admin', 'teacher', 'student'];

    protected $fillable = ['name', 'email', 'password', 'role', 'is_active'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed', // RNF-02: bcrypt, never plain text
            'is_active' => 'boolean',
        ];
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(AccessToken::class);
    }
}
