<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Databases created before the fix have expires_at as a NOT NULL timestamp, which MySQL/MariaDB
     * turn into ON UPDATE CURRENT_TIMESTAMP: saving last_used_at expired the token right away
     * (the session only lasted the gateway's 30 s validation cache).
     */
    public function up(): void
    {
        Schema::table('access_tokens', function (Blueprint $table) {
            $table->dateTime('expires_at')->change();
        });
    }

    public function down(): void
    {
        // Intentionally not reverted to timestamp: that would reintroduce the bug.
    }
};
