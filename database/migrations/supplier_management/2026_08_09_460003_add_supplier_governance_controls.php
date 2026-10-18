<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The columns and constraints the Supplier Management controls need to exist.
 *
 * Every addition here backs a rule the application is about to start enforcing. Splitting them out
 * means the enforcement code can assume the shape of the table rather than defending against it.
 *
 * The unique indexes are the important half. An application check stops the honest mistake; only the
 * index stops the double-submit, the race between two tabs, and the import script nobody told you
 * about. Both are added — the check to give a decent error message, the index to make it true.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── An award has to be attributable ──────────────────────────────────
        // Who awarded, when, and — when the winner was not the best-scored bid — why. Without the
        // reason, the one award that most needs explaining is the one that leaves no trace.
        Schema::table('rfx_events', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable()->after('closing_date');
            $table->timestamp('closed_at')->nullable()->after('published_at');
            $table->unsignedBigInteger('awarded_by')->nullable()->after('awarded_supplier_id');
            $table->timestamp('awarded_at')->nullable()->after('awarded_by');
            $table->text('award_reason')->nullable()->after('awarded_at');
        });

        // ── One bid per supplier per event ───────────────────────────────────
        // Otherwise a supplier can undercut its own bid after seeing the field, and the comparison
        // table shows the same company twice at two prices.
        $this->dedupe('rfx_responses', ['rfx_id', 'supplier_id']);

        Schema::table('rfx_responses', function (Blueprint $table) {
            $table->unique(['rfx_id', 'supplier_id'], 'rfx_responses_event_supplier_unique');
        });

        // ── One invoice number per supplier per tenant ───────────────────────
        // A resubmitted invoice must collide rather than become a second payable.
        $this->dedupe('supplier_invoices', ['company_id', 'supplier_id', 'invoice_number']);

        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->unique(['company_id', 'supplier_id', 'invoice_number'], 'supplier_invoices_number_unique');
        });

        // ── One supplier per registration number per tenant ──────────────────
        // Partial, because a registration number is optional: Postgres treats NULLs as distinct, but
        // being explicit documents that blanks are deliberately exempt rather than accidentally so.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS suppliers_registration_unique
            ON suppliers (company_id, company_registration_number)
            WHERE company_registration_number IS NOT NULL
              AND company_registration_number <> ''
              AND deleted_at IS NULL
        SQL);

        // ── The portal credential gains a lifetime ───────────────────────────
        // Null expiry keeps every existing link working; a rotation writes one.
        Schema::table('suppliers', function (Blueprint $table) {
            $table->timestamp('portal_token_expires_at')->nullable()->after('portal_token');
            $table->timestamp('portal_token_rotated_at')->nullable()->after('portal_token_expires_at');
        });

        // ── KYC needs to know who asked, to refuse to let them answer ────────
        Schema::table('supplier_kyc_approvals', function (Blueprint $table) {
            $table->unsignedBigInteger('requested_by')->nullable()->after('submitted_at');
        });

        // Existing pending approvals were raised by whoever created the supplier. Backfilling means
        // separation of duties applies to the queue as it stands, not only to new entries.
        DB::statement(<<<'SQL'
            UPDATE supplier_kyc_approvals a
            SET requested_by = s.created_by
            FROM suppliers s
            WHERE s.id = a.supplier_id AND a.requested_by IS NULL
        SQL);

        // ── A purchase order is approved by someone ──────────────────────────
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('approved_by')->nullable()->after('created_by');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
        });

        // ── A new company must not land on a retired tier ────────────────────
        // `Company::$attributes` defaults the plan to `starter`, but the column default was still
        // `standard` — the legacy tier the launch pricing migration deactivated and kept only so
        // historical subscriptions resolve a price. Any company created outside Eloquent (a seeder,
        // an import, a raw insert) therefore arrived subscribed to a plan nobody can buy, which is
        // how a fresh tenant ends up entitled to a module list that no longer reflects any tier.
        DB::statement("ALTER TABLE companies ALTER COLUMN plan SET DEFAULT 'starter'");

        DB::table('companies')
            ->where('plan', 'standard')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('subscriptions')
                ->whereColumn('subscriptions.company_id', 'companies.id'))
            ->update(['plan' => 'starter', 'updated_at' => now()]);
    }

    /**
     * Soft-delete rows that would violate a unique index about to be created, keeping the newest.
     *
     * Adding the index would otherwise fail on any installation that already has duplicates — which
     * is precisely the installation that needs it. The survivor is the most recent row because that
     * is the one someone most likely meant; the others stay recoverable rather than disappearing.
     */
    private function dedupe(string $table, array $columns): void
    {
        if (! Schema::hasColumn($table, 'deleted_at')) {
            return;
        }

        $partition = implode(', ', $columns);

        DB::statement(<<<SQL
            UPDATE {$table}
            SET deleted_at = NOW()
            WHERE id IN (
                SELECT id FROM (
                    SELECT id, ROW_NUMBER() OVER (PARTITION BY {$partition} ORDER BY id DESC) AS rn
                    FROM {$table}
                    WHERE deleted_at IS NULL
                ) ranked
                WHERE ranked.rn > 1
            )
        SQL);
    }

    public function down(): void
    {
        Schema::table('rfx_events', function (Blueprint $table) {
            $table->dropColumn(['published_at', 'closed_at', 'awarded_by', 'awarded_at', 'award_reason']);
        });

        Schema::table('rfx_responses', function (Blueprint $table) {
            $table->dropUnique('rfx_responses_event_supplier_unique');
        });

        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->dropUnique('supplier_invoices_number_unique');
        });

        DB::statement('DROP INDEX IF EXISTS suppliers_registration_unique');

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(['portal_token_expires_at', 'portal_token_rotated_at']);
        });

        Schema::table('supplier_kyc_approvals', function (Blueprint $table) {
            $table->dropColumn('requested_by');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['approved_by', 'approved_at']);
        });
    }
};
