<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Regulatory Pack Engine storage (§3) — "the single most important architectural decision in the
 * document".
 *
 * Core code contains no reference to Section 24, OSHA 300 or RIDDOR. A pack is a versioned, signed
 * bundle that teaches the generic engine how one jurisdiction works, and these tables hold the
 * installed versions and which sites have activated them.
 *
 * Three requirements shape the schema:
 *
 * - SHEQ-REG-2: historical records are evaluated against the pack version effective at
 *   `occurred_at`, never retroactively reclassified. Versions are therefore immutable rows with an
 *   effective window, and every classified record stores the pack id + version it was judged under.
 * - SHEQ-REG-1: activation is per site, so a group can run za-mhsa on a mine and za-ohsa on a
 *   factory at the same time.
 * - SHEQ-REG-8: updates arrive without a code deploy, are cryptographically signed, and are
 *   previewable in a sandbox before a tenant accepts them — hence `signature`, `checksum` and the
 *   ACCEPTED/PREVIEW status on the activation, not on the version.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Installed pack versions are platform-wide reference data, not tenant data: two tenants on
        // za-ohsa v2026.1 must be judged by identical rules. No company_id, therefore no RLS.
        Schema::create('sheq_regulatory_packs', function (Blueprint $table) {
            $table->id();
            $table->string('pack_id', 60)->unique();     // za-ohsa, za-mhsa, iso-45001
            $table->string('jurisdiction', 60);          // ZA, NA, UK, INTERNATIONAL
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('sheq_regulatory_pack_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pack_id')->constrained('sheq_regulatory_packs')->cascadeOnDelete();
            $table->string('version', 40);               // 2026.1.0

            $table->date('effective_from');
            $table->date('effective_to')->nullable();    // null = current

            // The compiled bundle: classifications, obligations, forms, metrics, templates,
            // legal register seed, retention policy. Stored whole so a version is reproducible
            // exactly as shipped, independent of the files on disk.
            $table->jsonb('manifest');

            $table->char('checksum', 64);                // sha256 of the canonical manifest
            $table->text('signature')->nullable();       // SHEQ-REG-8 detached signature
            $table->string('signed_by')->nullable();
            $table->boolean('signature_verified')->default(false);

            $table->timestampTz('installed_at')->useCurrent();
            $table->timestamps();

            $table->unique(['pack_id', 'version']);
            $table->index(['pack_id', 'effective_from']);
        });

        // Which pack versions a tenant's sites run. Tenant data — RLS applies.
        Schema::create('sheq_regulatory_pack_activations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            // Null site = the whole company; a site row overrides its ancestors.
            $table->foreignId('site_id')->nullable()->constrained('sheq_sites')->cascadeOnDelete();
            $table->foreignId('pack_version_id')->constrained('sheq_regulatory_pack_versions')->cascadeOnDelete();

            // PREVIEW = sandbox evaluation only, never writes obligations (SHEQ-REG-8).
            $table->string('status', 20)->default('ACCEPTED');   // ACCEPTED | PREVIEW | SUSPENDED
            $table->timestampTz('activated_at')->useCurrent();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('deactivated_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'site_id', 'status']);
        });

        // SHEQ-REG-9: a pack update that changes an obligation affecting open records raises an
        // alert listing them, rather than silently mutating live compliance state.
        Schema::create('sheq_compliance_change_alerts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('from_version_id')->nullable()->constrained('sheq_regulatory_pack_versions')->nullOnDelete();
            $table->foreignId('to_version_id')->constrained('sheq_regulatory_pack_versions')->cascadeOnDelete();

            $table->jsonb('diff');                 // what changed, clause by clause
            $table->jsonb('affected_subjects');    // [{type, id, reference, why}]
            $table->unsignedInteger('affected_count')->default(0);

            $table->string('status', 20)->default('OPEN');   // OPEN | REVIEWED | DISMISSED
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();

            $table->timestamps();
            $table->index(['company_id', 'status']);
        });

        $this->applyTenantRls(['sheq_regulatory_pack_activations', 'sheq_compliance_change_alerts']);
    }

    public function down(): void
    {
        Schema::dropIfExists('sheq_compliance_change_alerts');
        Schema::dropIfExists('sheq_regulatory_pack_activations');
        Schema::dropIfExists('sheq_regulatory_pack_versions');
        Schema::dropIfExists('sheq_regulatory_packs');
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
