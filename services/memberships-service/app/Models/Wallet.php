<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Credit balance of a user. Only App\Memberships\CreditLedger changes it. */
class Wallet extends Model
{
    protected $fillable = ['user_id'];

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'balance' => 'integer'];
    }
}
