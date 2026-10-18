<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contractor management (§5.9).
 *
 * A contractor company is a first-class entity with a compliance file, because in South Africa the
 * host employer carries duties for people on its premises whoever employs them: OHS Act s37 makes
 * the host liable for a mandatary's acts unless there is a written agreement, and the annual s24
 * statistics include contractors working on the employer's premises.
 *
 * That last point drives `contractor_id` on the incident: SHEQ-CON-4 needs contractor incidents to
 * roll up **separately and in aggregate**, and a report that cannot separate them cannot be
 * submitted. Attribution is stored directly rather than inferred from the people involved, because
 * an incident can involve a contractor's plant with nobody hurt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheq_contractors', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('name');
            $table->string('registration_number', 60)->nullable();
            $table->string('trade', 120)->nullable();          // Electrical, Scaffolding, Cleaning…

            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 40)->nullable();

            // Where they are permitted to work. Null = the whole company.
            $table->foreignId('site_id')->nullable()->constrained('sheq_sites')->nullOnDelete();

            // If the host also uses the Supplier Management module, this is the same organisation —
            // a reference, not a copy (principle P3).
            $table->unsignedBigInteger('supplier_id')->nullable();

            // COMPLIANT|EXPIRING|BLOCKED — computed, never typed in (SHEQ-CON-2).
            $table->string('access_status', 20)->default('BLOCKED');
            $table->timestampTz('access_status_computed_at')->nullable();
            // An override lets a site manager admit a contractor with a lapsed document, on record.
            $table->boolean('access_overridden')->default(false);
            $table->text('access_override_reason')->nullable();
            $table->foreignId('access_overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('access_override_expires')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'access_status']);
            $table->index(['company_id', 'site_id', 'is_active']);
        });

        Schema::create('sheq_contractor_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('contractor_id')->constrained('sheq_contractors')->cascadeOnDelete();

            // PUBLIC_LIABILITY|COIDA_LETTER|TAX_CLEARANCE|BBBEE|SAFETY_FILE|METHOD_STATEMENT|
            // RISK_ASSESSMENT|APPOINTMENT|INDUCTION|OTHER
            $table->string('document_type', 40);
            $table->string('title')->nullable();
            $table->string('reference', 120)->nullable();     // policy number, certificate number
            $table->string('issuing_body')->nullable();

            $table->date('issued_on')->nullable();
            // Null expiry means "does not expire" — a method statement for a finished job, say.
            $table->date('expires_on')->nullable();

            $table->string('file_path')->nullable();
            $table->char('sha256', 64)->nullable();

            // A missing mandatory document blocks access; a missing optional one does not.
            $table->boolean('is_mandatory')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'contractor_id', 'document_type']);
            $table->index(['company_id', 'expires_on']);
        });

        Schema::create('sheq_contractor_workers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('contractor_id')->constrained('sheq_contractors')->cascadeOnDelete();

            $table->string('name');
            $table->string('id_number_hash', 64)->nullable();   // POPIA: hashed, never the raw number
            $table->string('trade', 120)->nullable();

            // Contractor workers use the same competency machinery as employees (SHEQ-CON-3), so
            // they need an identifier the competency tables accept. Negative-space note: they are
            // deliberately NOT hr_people rows — they are not the host's employees.
            $table->unsignedBigInteger('competency_subject_id')->nullable();

            $table->date('inducted_on')->nullable();
            $table->date('induction_expires')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'contractor_id', 'is_active']);
        });

        // SHEQ-CON-4: attribute an incident to a contractor so statistics can separate them.
        Schema::table('sheq_incidents', function (Blueprint $table) {
            $table->unsignedBigInteger('contractor_id')->nullable()->after('linked_hazard_id');
            $table->index(['company_id', 'contractor_id']);
        });

        $this->applyTenantRls([
            'sheq_contractors', 'sheq_contractor_documents', 'sheq_contractor_workers',
        ]);
    }

    public function down(): void
    {
        Schema::table('sheq_incidents', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'contractor_id']);
            $table->dropColumn('contractor_id');
        });

        Schema::dropIfExists('sheq_contractor_workers');
        Schema::dropIfExists('sheq_contractor_documents');
        Schema::dropIfExists('sheq_contractors');
    }

    private function applyTenantRls(array $tables): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $session = "NULLIF(current_setting('app.company_id', true), '')";
        $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
        }
    }
};
