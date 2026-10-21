<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two things SHEQ was missing for an organisation that works under certification schemes and
 * risky field work.
 *
 * Compliance schemes. A regulatory pack is a signed rulebook; a scheme (GLOBALG.A.P., SIZA, an
 * organic standard, a customer's code of practice) is something the organisation writes down
 * itself. A scheme is a set of clauses — the requirement, what proves it, how often it is renewed —
 * and adopting it at a site seeds the site's legal register exactly as a pack does, so its lines are
 * tracked, evidenced and computed by the same register.
 *
 * Inspection gates. A published inspection template can be named as a gate for a kind of work (a
 * category of job in another module): that work may not start until an inspection on that template,
 * for that job, has passed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheq_compliance_schemes', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->index();
            $t->string('code', 40);                                   // becomes the register's pack id: scheme:<code>
            $t->string('name', 160);
            $t->string('jurisdiction', 60)->nullable();
            $t->string('scheme', 80)->nullable();                     // e.g. GLOBALG.A.P. IFA v6
            $t->unsignedInteger('version')->default(0);               // 0 until first published
            $t->string('status', 12)->default('draft');               // draft | published | retired
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('published_by')->nullable();
            $t->timestampTz('published_at')->nullable();
            $t->timestampsTz();
            $t->unique(['company_id', 'code']);
        });
        Schema::create('sheq_compliance_scheme_clauses', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->foreignId('scheme_id')->constrained('sheq_compliance_schemes')->cascadeOnDelete();
            $t->string('clause', 60);
            $t->string('instrument', 255);                            // the scheme section or document it comes from
            $t->text('requirement');
            $t->string('category', 40)->nullable();
            $t->jsonb('evidence_types');                              // what proves it, in the register's evidence types
            $t->string('review_frequency', 20)->nullable();           // ISO 8601, e.g. P12M
            $t->string('responsible_role', 120)->nullable();
            $t->timestampsTz();
            $t->unique(['scheme_id', 'clause']);
        });
        Schema::create('sheq_compliance_scheme_adoptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->foreignId('scheme_id')->constrained('sheq_compliance_schemes')->cascadeOnDelete();
            $t->unsignedBigInteger('site_id')->nullable();
            $t->unsignedInteger('version');
            $t->unsignedBigInteger('adopted_by')->nullable();
            $t->timestampsTz();
            $t->unique(['scheme_id', 'site_id']);
        });

        Schema::create('sheq_inspection_gates', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->index();
            $t->string('template_code', 60);                          // the inspection that must pass (any published version)
            $t->string('subject_type', 40);                           // the work it gates, e.g. WORK_ORDER
            $t->string('category', 60);                               // the kind of that work, e.g. spraying
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestampsTz();
            $t->unique(['company_id', 'template_code', 'subject_type', 'category']);
        });

        if (DB::getDriverName() === 'pgsql') {
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
            foreach (['sheq_compliance_schemes', 'sheq_compliance_scheme_clauses', 'sheq_compliance_scheme_adoptions', 'sheq_inspection_gates'] as $table) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
                DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
                DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
                DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
            }
        }
    }

    public function down(): void
    {
        foreach (['sheq_inspection_gates', 'sheq_compliance_scheme_adoptions', 'sheq_compliance_scheme_clauses', 'sheq_compliance_schemes'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
