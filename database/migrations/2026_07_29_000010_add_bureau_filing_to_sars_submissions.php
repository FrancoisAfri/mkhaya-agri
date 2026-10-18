<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a SARS submission optionally belong to a payroll-bureau CLIENT company, so the bureau can
 * file EMP201/IRP5 for each client into the same shared audit trail as the tenant's own returns.
 *
 * - bureau_company_id: NULL for the tenant's own return; set to the bureau client it was filed
 *   for. Versioning/amendment lookups key on (tax_type, period, bureau_company_id) so two
 *   clients' returns for the same month are not treated as versions of each other.
 * - subject_label: the client's display name, denormalised for the submission history list.
 *
 * The row still carries the operator tenant's company_id (+ FORCE RLS) unchanged, so bureau
 * filings live in the operator's audit trail and are RLS-isolated like everything else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sars_submissions', function (Blueprint $t) {
            $t->unsignedBigInteger('bureau_company_id')->nullable()->after('company_id')->index();
            $t->string('subject_label')->nullable()->after('bureau_company_id');
        });
    }

    public function down(): void
    {
        Schema::table('sars_submissions', function (Blueprint $t) {
            $t->dropColumn(['bureau_company_id', 'subject_label']);
        });
    }
};
