<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Generated statutory forms (SHEQ-REG-6).
 *
 * A form instance is a record of what was submitted, not a view. Once a W.Cl.2 goes to the
 * Compensation Commissioner, "what did we actually send" is a question the employer may have to
 * answer years later — so the resolved field values are frozen on the instance rather than
 * recomputed from the incident, which will have moved on.
 *
 * Overrides are stored beside the generated value, never on top of it. SHEQ-REG-6 requires the
 * override to be audited, and an audit that cannot show what the system had proposed is not an
 * audit. The pack version that produced the layout is stamped for the same reason (SHEQ-REG-2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheq_statutory_form_instances', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('form_code', 40);                  // ANNEXURE_1, WCL1_OR_WCL2
            $table->string('subject_type', 40)->default('INCIDENT');
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('obligation_id')->nullable()
                ->constrained('sheq_statutory_obligations')->nullOnDelete();

            $table->string('regulatory_pack_id', 60);
            $table->string('regulatory_pack_version', 40);
            $table->string('title');

            // The resolved form: sections, fields, generated values, overrides and gaps.
            $table->jsonb('content');
            $table->unsignedSmallInteger('gap_count')->default(0);
            $table->unsignedSmallInteger('override_count')->default(0);

            // DRAFT|FINAL — a finalised form is what was sent and stops changing.
            $table->string('status', 20)->default('DRAFT');
            $table->timestampTz('generated_at');
            $table->timestampTz('finalised_at')->nullable();
            $table->foreignId('finalised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->char('content_sha256', 64)->nullable();

            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'subject_type', 'subject_id']);
            $table->index(['company_id', 'form_code', 'status']);
        });

        Schema::create('sheq_statutory_form_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('form_instance_id')->constrained('sheq_statutory_form_instances')
                ->cascadeOnDelete();

            $table->string('field_key', 60);
            // Both sides kept: what the system produced, and what the person put instead.
            $table->text('generated_value')->nullable();
            $table->text('override_value')->nullable();
            $table->text('reason')->nullable();

            $table->foreignId('overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('overridden_at');
            $table->timestamps();

            $table->index(['company_id', 'form_instance_id']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $session = "NULLIF(current_setting('app.company_id', true), '')";
        $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

        foreach (['sheq_statutory_form_instances', 'sheq_statutory_form_overrides'] as $t) {
            DB::statement("ALTER TABLE {$t} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$t}");
            DB::statement("CREATE POLICY tenant_isolation ON {$t} USING ({$policy}) WITH CHECK ({$policy})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sheq_statutory_form_overrides');
        Schema::dropIfExists('sheq_statutory_form_instances');
    }
};
