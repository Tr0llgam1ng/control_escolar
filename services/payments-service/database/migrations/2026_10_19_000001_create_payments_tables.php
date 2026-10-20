<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments Service database (soa_payments). student_id and the *_by columns are ids owned by
 * other services (catalog / auth): plain integers, never foreign keys across databases.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Paperwork a student can request (constancia, kárdex, ...). The price lives here, never in the client.
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->string('description', 255)->nullable();
            $table->decimal('price', 10, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // A payment reference generated for one requested document.
        Schema::create('charges', function (Blueprint $table) {
            $table->id();
            $table->char('reference', 13)->unique();
            $table->unsignedBigInteger('student_id')->index();
            $table->foreignId('document_type_id')->constrained('document_types');
            $table->string('concept', 120);
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3)->default('MXN');
            $table->string('status', 12)->default('pending')->index(); // pending | approved | rejected
            $table->string('payment_method', 10)->nullable()->index();  // card | cash
            // dateTime, not timestamp: MySQL/MariaDB give the first NOT NULL timestamp column
            // ON UPDATE CURRENT_TIMESTAMP, which would silently move the expiration on every update.
            $table->dateTime('expires_at');
            $table->timestamp('paid_at')->nullable();
            $table->string('status_reason', 255)->nullable();
            $table->timestamp('reviewed_at')->nullable()->index();      // null = new reference for the admin
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'status']);
        });

        // Local "bank": test cards the card simulator validates against. Never real card data.
        Schema::create('test_cards', function (Blueprint $table) {
            $table->id();
            $table->string('number', 19)->unique();
            $table->string('holder_name', 100);
            $table->string('brand', 20);
            $table->unsignedTinyInteger('exp_month');
            $table->unsignedSmallInteger('exp_year');
            $table->string('cvv_hash');
            $table->decimal('balance', 12, 2);
            $table->string('status', 10)->default('active'); // active | blocked
            $table->string('notes', 120)->nullable();
            $table->timestamps();
        });

        // Every payment attempt: card (approved or declined) and cash received at the window.
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('charge_id')->constrained('charges');
            $table->string('method', 10);  // card | cash
            $table->string('status', 10);  // approved | rejected
            $table->decimal('amount', 10, 2);
            $table->decimal('amount_received', 10, 2)->nullable();
            $table->decimal('change_given', 10, 2)->nullable();
            $table->foreignId('test_card_id')->nullable()->constrained('test_cards');
            $table->string('card_brand', 20)->nullable();
            $table->char('card_last4', 4)->nullable();
            $table->string('authorization_code', 12)->nullable();
            $table->decimal('card_balance_after', 12, 2)->nullable();
            $table->string('decline_reason', 120)->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable(); // admin user that received cash
            $table->timestamps();
        });

        Schema::create('charge_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('charge_id')->constrained('charges');
            $table->string('action', 30);
            $table->string('from_status', 12)->nullable();
            $table->string('to_status', 12)->nullable();
            $table->string('actor_type', 10);
            $table->string('actor_id', 60)->nullable();
            $table->string('reason', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charge_audit_logs');
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('test_cards');
        Schema::dropIfExists('charges');
        Schema::dropIfExists('document_types');
    }
};
