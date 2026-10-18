<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a host demands of a contractor before anybody comes on site.
 *
 * `ContractorService::complianceFile()` and `refreshAccessStatus()` both take a `$requiredTypes`
 * list and decide access from it — a contractor missing a required document is BLOCKED. Nothing
 * stored that list, and every caller passed an empty array, so "required" was a concept the code
 * understood and the installation could not express. A contractor was registered, immediately read
 * as blocked, and no screen could say which document would unblock them.
 *
 * The default is the set a South African host ordinarily insists on:
 *
 * - **Letter of Good Standing** — proof the contractor is registered with the Compensation Fund
 *   and up to date. Without it their injured worker becomes the host's liability under COIDA.
 * - **Public liability insurance** — damage to third parties and property.
 * - **Section 37(2) agreement** — the OHS Act mandatary agreement that fixes who is responsible
 *   for what between the host and the contractor.
 * - **Safety file** — the contractor's own health and safety documentation for the work.
 *
 * Set per tenant, because a host's demands are its own. An empty list means nothing is demanded,
 * which is a decision rather than an accident now that it can be expressed.
 */
return new class extends Migration
{
    private const DEFAULT_REQUIRED = [
        'LETTER_OF_GOOD_STANDING',
        'PUBLIC_LIABILITY_INSURANCE',
        'SECTION_37_2_AGREEMENT',
        'SAFETY_FILE',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('sheq_settings')) {
            return;
        }

        if (! Schema::hasColumn('sheq_settings', 'contractor_required_documents')) {
            Schema::table('sheq_settings', function ($table) {
                $table->jsonb('contractor_required_documents')->nullable()
                    ->comment('Document types a contractor must hold before site access is granted.');
            });
        }

        // Existing tenants get the default rather than an empty list: a host that has not thought
        // about it yet is better served demanding the usual four than demanding nothing.
        DB::table('sheq_settings')
            ->whereNull('contractor_required_documents')
            ->update(['contractor_required_documents' => json_encode(self::DEFAULT_REQUIRED)]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('sheq_settings', 'contractor_required_documents')) {
            Schema::table('sheq_settings', function ($table) {
                $table->dropColumn('contractor_required_documents');
            });
        }
    }
};
