<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A trading entity: the business whose name, logo and banking details appear on a document.
 *
 * One Mkhaya tenant trades as Mkhaya MK, Mkhaya Move and Mkhaya Hub. Each issues its own quotes
 * and invoices, under its own letterhead and its own numbering, while the staff, payroll, leave
 * and subscription behind them stay single and shared.
 *
 * ── Why this is not another company ──────────────────────────────────────────
 *
 * `companies` is the tenant: it carries the plan, the monthly total, the trial and the reseller.
 * Making each brand a company would separate everything — its own employees, its own payroll, its
 * own subscription — which is the opposite of what one business with several trading names wants.
 * So the tenant stays whole and Accounting gains a second dimension: `company_id` says whose data
 * it is, `entity_id` says which of their businesses issued it.
 *
 * ── The shape ────────────────────────────────────────────────────────────────
 *
 * Deliberately thin. Everything that describes an entity — trading name, address, VAT number,
 * registration number, logo, banking details, financial year start — already exists on
 * `fin_settings`, which until now held one row per tenant and now holds one per entity. This table
 * is the identity those settings hang from, and nothing more.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_entities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            /*
             * Nullable, as `fin_settings.company_id` is.
             *
             * A standalone install keeps its settings on a row with no company, and an entity has
             * to be able to live in the same place. Every multi-tenant row carries a company.
             */
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();

            $table->string('name', 150);

            // Stable across renames: a document rendered "Mkhaya Move" keeps pointing at this row
            // even after somebody corrects the spelling of the name.
            $table->string('slug', 60);

            /*
             * The entity a user lands in, and where existing data was gathered by the backfill.
             *
             * Enforced as one per tenant by the partial unique index below rather than by hoping
             * the application always remembers.
             */
            $table->boolean('is_default')->default(false);

            // Archived, never deleted: a document issued under this entity must keep naming it
            // for as long as the ledger keeps the document.
            $table->string('status', 20)->default('active');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('company_id');
            $table->unique(['company_id', 'slug']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            // One default per tenant. A partial index, because `is_default = false` repeats freely
            // and only the true rows need to be unique.
            \Illuminate\Support\Facades\DB::statement(
                'CREATE UNIQUE INDEX fin_entities_one_default_per_company'
                . ' ON fin_entities (company_id) WHERE is_default'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_entities');
    }
};
