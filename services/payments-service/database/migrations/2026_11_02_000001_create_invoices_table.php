<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CFDI 4.0 invoices of paid references, stamped through the PAC (TimbraCFDI).
 * One stamped invoice per reference at a time; failed attempts stay for audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('charge_id')->constrained('charges');
            $table->unsignedBigInteger('student_id')->index();
            $table->string('status', 12)->index(); // stamped | cancelled | failed
            $table->char('uuid', 36)->nullable()->unique();
            $table->string('series', 25);
            $table->string('folio', 40)->nullable();

            $table->string('issuer_rfc', 13);
            $table->string('receiver_rfc', 13);
            $table->string('receiver_name', 254);
            $table->char('receiver_zip', 5);
            $table->char('receiver_regime', 3);
            $table->char('cfdi_use', 3);
            $table->char('payment_form', 2);

            $table->decimal('subtotal', 10, 2);
            $table->decimal('tax', 10, 2);
            $table->decimal('total', 10, 2);

            $table->longText('xml')->nullable();            // stamped CFDI (with TimbreFiscalDigital)
            $table->longText('qr_png')->nullable();         // QR image (Base64 PNG) for the printed version
            $table->text('original_chain')->nullable();     // CadenaOriginalTimbre
            $table->dateTime('stamped_at')->nullable();

            $table->char('cancel_reason', 2)->nullable();   // c_MotivoCancelacion
            $table->string('cancel_message', 255)->nullable();
            $table->longText('cancel_ack')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();

            $table->string('error_code', 20)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['charge_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
