<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The Auth Service owns identities (credentials + role) and access tokens. Nothing else. */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role', 20)->index(); // admin, teacher, student
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('access_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique(); // sha256 of the plain token, never the token itself
            // dateTime, not timestamp: MySQL/MariaDB give the first NOT NULL timestamp column
            // ON UPDATE CURRENT_TIMESTAMP, which would reset the expiry on every validation.
            $table->dateTime('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_tokens');
        Schema::dropIfExists('users');
    }
};
