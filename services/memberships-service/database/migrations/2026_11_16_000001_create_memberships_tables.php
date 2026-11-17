<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Memberships Service database (soa_memberships).
 * user_id, student_id, teacher_id and charge_id are ids owned by other services
 * (auth / catalog / payments): plain integers, never foreign keys across databases.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------------------------- plans and their limits
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();          // basic | bronze | silver | gold
            $table->string('name', 60);
            $table->string('description', 255)->nullable();
            $table->unsignedInteger('price_credits');       // 0 = basic (free, no subscription)
            $table->unsignedSmallInteger('duration_days');
            $table->unsignedTinyInteger('tier');            // 0 basic … 3 gold (to know what is an upgrade)
            $table->string('color', 20)->default('slate');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // One row per plan and feature. Meaning of value depends on the feature type (see App\Memberships\Features):
        // quota → max uses per cycle (null = unlimited) · time → hours/minutes included per cycle · percent/credits/number → the value.
        Schema::create('plan_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('feature', 40);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('value')->nullable();
            $table->timestamps();
            $table->unique(['plan_id', 'feature']);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->foreignId('plan_id')->constrained('plans');
            $table->string('status', 12)->index();          // active | replaced | expired
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->index();
            $table->unsignedInteger('price_credits');
            $table->boolean('auto_renew')->default(true);
            $table->dateTime('cancelled_at')->nullable();   // auto renew turned off
            $table->unsignedBigInteger('previous_id')->nullable();
            $table->timestamps();
        });

        // ---------------------------------------------------------------- credits (accountability)
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->unsignedBigInteger('balance')->default(0);
            $table->timestamps();
        });

        // Immutable ledger: every movement of credits, with the balance it left.
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('type', 12)->index();            // purchase | bonus | spend | refund | adjustment
            $table->bigInteger('amount');                   // + credit, − debit
            $table->unsignedBigInteger('balance_after');
            $table->string('concept', 160);
            $table->string('source_type', 30)->nullable();  // subscription, credit_purchase, tutoring_session…
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('actor_type', 10);               // user | service
            $table->string('actor_id', 60)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['source_type', 'source_id']);
        });

        // Exchange table: a paid credit pack (payments-service document type with the same code) gives these credits.
        Schema::create('credit_packs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 80);
            $table->unsignedInteger('credits');
            $table->decimal('price', 10, 2);                // must match the price charged by payments-service
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('credit_purchases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('student_id');
            $table->foreignId('credit_pack_id')->constrained('credit_packs');
            $table->unsignedBigInteger('charge_id')->unique();   // payments-service reference: credited once
            $table->char('reference', 13)->nullable();
            $table->decimal('amount', 10, 2);
            $table->unsignedInteger('credits');
            $table->unsignedInteger('bonus_credits')->default(0);
            $table->unsignedTinyInteger('bonus_pct')->default(0);
            $table->string('status', 10)->index();         // pending | credited | review | rejected
            $table->string('status_reason', 255)->nullable();
            $table->dateTime('credited_at')->nullable();
            $table->dateTime('checked_at')->nullable();
            $table->timestamps();
        });

        // ---------------------------------------------------------------- authorization: usage per cycle
        // used = number of uses (quota features) or seconds (time features) in the cycle that starts at cycle_start.
        Schema::create('usage_counters', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('feature', 40);
            $table->dateTime('cycle_start');
            $table->unsignedInteger('used')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'feature', 'cycle_start']);
        });

        // ---------------------------------------------------------------- time (days / hours / minutes)
        // Extra time bought with credits. It does not expire with the cycle (the included time does).
        Schema::create('time_balances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('module', 20);                  // study_rooms | library
            $table->unsignedInteger('extra_seconds')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'module']);
        });

        Schema::create('time_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('module', 20);
            $table->string('kind', 12);                    // use | purchase | refund
            $table->integer('included_seconds')->default(0);
            $table->integer('extra_seconds')->default(0);
            $table->string('concept', 160);
            $table->string('source_type', 30)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('study_rooms', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('location', 120)->nullable();
            $table->unsignedTinyInteger('capacity')->default(4);
            $table->string('equipment', 160)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('room_bookings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->foreignId('study_room_id')->constrained('study_rooms');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedSmallInteger('minutes');
            $table->unsignedInteger('included_seconds')->default(0);
            $table->unsignedInteger('extra_seconds')->default(0);
            $table->dateTime('cycle_start');
            $table->string('status', 10)->index();         // booked | cancelled
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['study_room_id', 'starts_at']);
        });

        Schema::create('library_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('resource', 30);
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->unsignedInteger('limit_seconds');      // time available when the session started
            $table->unsignedInteger('used_seconds')->default(0);
            $table->unsignedInteger('included_seconds')->default(0);
            $table->unsignedInteger('extra_seconds')->default(0);
            $table->string('status', 10)->index();         // open | closed
            $table->string('closed_by', 10)->nullable();   // user | limit
            $table->timestamps();
        });

        // ---------------------------------------------------------------- credit modules
        Schema::create('tutoring_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();       // student account
            $table->string('student_name', 120)->nullable();
            $table->unsignedBigInteger('teacher_id')->index();    // catalog teacher record
            $table->string('teacher_name', 120)->nullable();
            $table->string('subject', 120);
            $table->string('topic', 255);
            $table->dateTime('scheduled_at');
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedInteger('credits_cost');
            $table->unsignedTinyInteger('discount_pct')->default(0);
            $table->dateTime('cycle_start');
            $table->string('status', 10)->index();         // requested | confirmed | completed | rejected | cancelled
            $table->string('teacher_notes', 255)->nullable();
            $table->boolean('refunded')->default(false);
            $table->timestamps();
        });

        Schema::create('analytics_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedInteger('credits_cost');
            $table->string('plan_code', 20);
            $table->json('result');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['analytics_reports', 'tutoring_sessions', 'library_sessions', 'room_bookings', 'study_rooms', 'time_movements',
            'time_balances', 'usage_counters', 'credit_purchases', 'credit_packs', 'credit_transactions', 'wallets',
            'subscriptions', 'plan_features', 'plans'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
