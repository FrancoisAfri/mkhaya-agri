<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SARS Compliance — foundation tables.
 *
 * sars_tax_configs: one row per company — the single source of truth for the company's SARS
 * registration numbers (VAT/PAYE/UIF/SDL/Income Tax), VAT filing frequency and SDL liability.
 * Feeds every return header and the validation engine.
 *
 * sars_submissions: an immutable-in-spirit record of every generated return (VAT201, EMP201,
 * EMP501, IRP5/IT3(a), UI-19) — with a status lifecycle, versioning for amendments, a JSON
 * snapshot of the figures, the validation result at generation time, and the produced file.
 *
 * Both are tenant-scoped (company_id + FORCE RLS) like every other module table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sars_tax_configs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable()->unique();
            $t->string('vat_number', 20)->nullable();
            $t->boolean('vat_registered')->default(false);
            $t->string('vat_frequency', 20)->default('bi_monthly'); // monthly | bi_monthly | category_c | category_d
            $t->string('paye_reference', 20)->nullable();
            $t->string('uif_reference', 20)->nullable();
            $t->string('sdl_reference', 20)->nullable();
            $t->boolean('sdl_liable')->default(false);
            $t->string('income_tax_number', 20)->nullable();
            $t->string('sic_code', 20)->nullable();          // trade classification
            $t->string('contact_person')->nullable();
            $t->string('contact_email')->nullable();
            $t->string('efiling_username')->nullable();
            $t->timestamps();
        });

        Schema::create('sars_submissions', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->string('reference', 40);                     // e.g. SUB-000123
            $t->string('tax_type', 20);                      // vat201 | emp201 | emp501 | irp5 | it3a | ui19
            $t->string('period', 20);                        // YYYY-MM, or a recon window label
            $t->string('status', 20)->default('draft');      // draft|generated|validated|submitted|accepted|rejected
            $t->unsignedInteger('version')->default(1);
            $t->unsignedBigInteger('amends_id')->nullable(); // previous version this amends
            $t->json('totals')->nullable();                  // headline figures (PAYE/UIF/SDL, VAT payable…)
            $t->json('payload')->nullable();                 // full generated snapshot
            $t->json('validation')->nullable();              // validation result at generation time
            $t->string('file_path')->nullable();             // stored SARS export / PDF
            $t->text('notes')->nullable();
            $t->unsignedBigInteger('generated_by')->nullable();
            $t->timestamp('generated_at')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'tax_type', 'period']);
        });

        if (DB::getDriverName() === 'pgsql') {
            foreach (['sars_tax_configs', 'sars_submissions'] as $table) {
                $session = "NULLIF(current_setting('app.company_id', true), '')";
                $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
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
        Schema::dropIfExists('sars_submissions');
        Schema::dropIfExists('sars_tax_configs');
    }
};
