<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link a contract to an ESign envelope so it can be sent for e-signature.
 * `esign_envelope_id` references esign_envelopes.id; `signature_status` mirrors
 * the envelope lifecycle (sent|in_progress|completed|voided|...) for quick display.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->unsignedBigInteger('esign_envelope_id')->nullable()->after('file_size');
            $table->string('signature_status')->nullable()->after('esign_envelope_id');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['esign_envelope_id', 'signature_status']);
        });
    }
};
