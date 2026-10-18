<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a contract be signed with a drawn signature, not only a typed name.
 *
 * A typed name is a valid electronic signature under the ECT Act and it stays the default — it
 * works on any device and there is nothing to smudge. But people expect to sign a contract by
 * signing it, and a plant hire agreement that only ever offers a text box reads as unfinished.
 *
 * `signature_data` holds the drawing as a PNG data URI, which is how the platform's other
 * signature surface (pm_document_signatures) already stores one. `signature_type` records which
 * of the two was used, because the audit answer to "how did they sign" should not be inferred
 * from whether a column happens to be null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_contract_signatures', function (Blueprint $t) {
            // 'typed' | 'drawn'. Defaulted rather than nullable: every signature already taken was
            // a typed one, and leaving them null would make the audit trail lie by omission.
            $t->string('signature_type', 12)->default('typed')->after('typed_name');
            $t->longText('signature_data')->nullable()->after('signature_type');
        });
    }

    public function down(): void
    {
        Schema::table('pm_contract_signatures', function (Blueprint $t) {
            $t->dropColumn(['signature_type', 'signature_data']);
        });
    }
};
