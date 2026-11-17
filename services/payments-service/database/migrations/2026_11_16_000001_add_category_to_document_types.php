<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Memberships: credit packs are sold as payment references too (same card / cash flow).
 * category = paperwork (constancia, kárdex...) | credits (packs exchanged for credits in memberships-service).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->string('category', 20)->default('paperwork')->after('code')->index();
        });
    }

    public function down(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->dropIndex(['category']);
            $table->dropColumn('category');
        });
    }
};
