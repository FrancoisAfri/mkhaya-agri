<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * POPIA fields on the people this module holds.
 *
 * Section 14 requires that personal information is not kept longer than necessary, and sections
 * 23-25 give a person the right to ask what is held about them and to have it corrected or deleted.
 * Neither is answerable without knowing, per record, why it is held and until when — so both become
 * columns rather than a policy document.
 *
 * `purge_after` is computed when the supplier relationship ends rather than at capture, because the
 * clock starts when the relationship does, not when the form was filled in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_persons', function (Blueprint $t) {
            $t->string('lawful_basis', 40)->nullable()->after('source');
            $t->string('collection_purpose', 190)->nullable()->after('lawful_basis');
            $t->timestamp('identifiers_captured_at')->nullable()->after('collection_purpose');
            $t->date('purge_after')->nullable()->after('identifiers_captured_at');
            $t->timestamp('identifiers_purged_at')->nullable()->after('purge_after');
        });

        // Every person already on file was captured for supplier due diligence under the same basis;
        // saying so explicitly is better than leaving a null that reads as "nobody decided".
        DB::table('supplier_persons')->whereNull('lawful_basis')->update([
            'lawful_basis' => 'legitimate_interest',
            'collection_purpose' => 'Supplier verification and screening',
        ]);
    }

    public function down(): void
    {
        Schema::table('supplier_persons', function (Blueprint $t) {
            $t->dropColumn([
                'lawful_basis', 'collection_purpose', 'identifiers_captured_at',
                'purge_after', 'identifiers_purged_at',
            ]);
        });
    }
};
