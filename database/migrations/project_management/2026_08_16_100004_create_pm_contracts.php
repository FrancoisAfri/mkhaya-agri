<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Project contracts, owned by Project Management.
 *
 * ── Why this reverses an earlier decision ───────────────────────────────────
 *
 * The first cut of subcontractor contracts deliberately did *not* build this: it added two columns
 * to Contract Management's `contracts` table and drove the whole flow from there, on the reasoning
 * that a second register was duplication.
 *
 * That reasoning was wrong for this platform, and the client said why: the modules are sold
 * separately. A company may buy Project Management and never buy Contract Management, and on that
 * tenant the entire feature was writing into — and reading templates from — a module they do not
 * own. PM's own rule, stated in ModuleRegistry, is that PM must be fully usable with nothing else
 * installed and that integrations *add* capability. Contracts were breaking it.
 *
 * So the register is PM's, and Contract Management becomes what every other integration is: a
 * source you can import from when it happens to be there.
 *
 * ── Amendment is the point of the versions table ────────────────────────────
 *
 * A contract is negotiated. Clauses that do not apply to one contractor get struck; a rate changes;
 * the other side asks for a change and the document goes round again. Keeping only the current body
 * would mean nobody can answer "what did I actually sign" — so every edit writes a row here, with
 * the body as it then read and a hash of it.
 *
 * That hash is what makes a signature honest. `pm_contract_signatures.content_sha256` is copied
 * from the version at the moment of signing and never recomputed, so a contract amended after
 * signature shows as signed-on-an-earlier-version instead of silently carrying the tick forward.
 * The same rule already governs `pm_document_signatures`; this is that rule applied to contracts.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── The template library ─────────────────────────────────────────────
        Schema::create('pm_contract_templates', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();

            $t->string('name', 190);
            $t->string('summary', 500)->nullable();
            // Free text rather than an enum: what a practice calls its own categories is theirs.
            $t->string('category', 80)->nullable();
            $t->longText('body')->nullable();

            /**
             * Where this template came from, for a screen that has to be able to say so.
             *
             * 'seeded'   — shipped with the module, installed per tenant
             * 'own'      — the company wrote it
             * 'imported' — copied out of Contract Management
             *
             * An import is a copy, never a reference: editing an imported template must not reach
             * back and rewrite Contract Management's original, and a tenant who later drops that
             * module must keep the wording they were using. `source_ref` is a note of where it came
             * from, not a foreign key.
             */
            $t->string('source', 20)->default('own');
            $t->string('source_ref', 64)->nullable();

            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'is_active']);
        });

        // ── The register ─────────────────────────────────────────────────────
        Schema::create('pm_contracts', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('project_id')->index();
            // Nullable: a contract can be drafted before the other party is a project member, and
            // the member may later be removed without the contract ceasing to have existed.
            $t->unsignedBigInteger('project_member_id')->nullable()->index();
            // A note of the template it started from. Null for one written from scratch, which the
            // client asked for explicitly.
            $t->unsignedBigInteger('template_id')->nullable();

            $t->string('contract_number', 60);
            $t->string('title', 255);
            $t->text('summary')->nullable();

            /**
             * draft → in_review → approved → out_for_signature → signed
             *                  ↘ rejected              ↘ declined
             *
             * `cancelled` is reachable from anywhere before signature. Amending a contract that is
             * out for signature returns it to draft and withdraws the pending signatures — that is
             * the negotiation loop, and it is enforced in ProjectContractService, not here.
             */
            $t->string('status', 30)->default('draft');

            $t->decimal('contract_value', 18, 2)->default(0);
            $t->string('currency', 3)->default('ZAR');
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();

            // The version everybody is currently looking at. The body itself lives on the version
            // rows, so that "current" is a pointer rather than a second copy that can disagree.
            $t->unsignedInteger('current_version')->default(1);

            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->foreign('project_id')->references('id')->on('pm_projects')->cascadeOnDelete();
            $t->foreign('project_member_id')->references('id')->on('pm_project_members')->nullOnDelete();
            $t->foreign('template_id')->references('id')->on('pm_contract_templates')->nullOnDelete();

            // Numbers are per project (`PRJ/C001`), and two tenants may both run a project coded
            // PRJ — so the key carries the company. See [[reference_tenancy_gaps]] on composite keys.
            $t->unique(['company_id', 'project_id', 'contract_number'], 'pm_contracts_number_unique');
        });

        // ── Every version the document has had ───────────────────────────────
        Schema::create('pm_contract_versions', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('contract_id')->index();

            $t->unsignedInteger('version_no');
            $t->longText('body')->nullable();
            // Why this version exists — "removed clause 7, no plant on this package". The audit
            // trail a negotiation needs is not "changed at 14:02" but what changed and why.
            $t->string('change_note', 500)->nullable();
            $t->char('content_sha256', 64)->nullable();

            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();

            $t->foreign('contract_id')->references('id')->on('pm_contracts')->cascadeOnDelete();
            $t->unique(['contract_id', 'version_no']);
        });

        // ── Signatures, against a specific version ───────────────────────────
        Schema::create('pm_contract_signatures', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('contract_id')->index();
            // Which version was in front of them. Not nullable: a signature that cannot name what
            // it signed is not evidence of anything.
            $t->unsignedBigInteger('version_id');

            // One round of signing. A new round supersedes the last rather than deleting it.
            $t->uuid('envelope_uuid')->index();
            $t->unsignedInteger('sign_order')->default(1);
            // 'contractor' — the other side; 'company' — us. Kept as a word rather than a boolean
            // because a screen has to label it and a third party may appear later.
            $t->string('party', 20)->default('company');

            // The contractor signs in the portal with the login they were given when they were
            // added, so a signer is a user of this system — no emailed magic link to guard.
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->string('signer_name', 190)->nullable();
            $t->string('signer_email', 190)->nullable();

            $t->string('status', 20)->default('pending');
            $t->timestamp('signed_at')->nullable();
            $t->string('typed_name', 190)->nullable();
            $t->text('decline_reason')->nullable();

            /** Copied from the version at signing time and never recomputed. */
            $t->char('content_sha256', 64)->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();

            $t->timestamps();

            $t->foreign('contract_id')->references('id')->on('pm_contracts')->cascadeOnDelete();
            $t->foreign('version_id')->references('id')->on('pm_contract_versions')->cascadeOnDelete();
        });

        $this->applyTenantRls([
            'pm_contract_templates', 'pm_contracts', 'pm_contract_versions', 'pm_contract_signatures',
        ]);

        $this->adoptExistingProjectContracts();
    }

    /**
     * Bring across the contracts PM already drafted into Contract Management's table.
     *
     * Only rows carrying a `project_id` — those are the ones PM raised. Everything else in that
     * table belongs to a supplier and to the other module, and is left exactly as it is.
     *
     * The old rows are not deleted. This migration is reversible, the other module still lists
     * them, and a half-migrated contract that exists in neither place is the one outcome worth
     * ruling out.
     */
    private function adoptExistingProjectContracts(): void
    {
        if (! Schema::hasTable('contracts') || ! Schema::hasColumn('contracts', 'project_id')) {
            $this->repointInvoices([]);

            return;
        }

        /** @var array<int,int> old contracts.id => new pm_contracts.id */
        $moved = [];

        DB::table('contracts')->whereNotNull('project_id')->orderBy('id')
            ->chunkById(200, function ($rows) use (&$moved) {
                foreach ($rows as $row) {
                    $status = match ($row->signature_status ?? null) {
                        'signed'   => 'signed',
                        'declined' => 'declined',
                        'sent'     => 'out_for_signature',
                        default    => 'draft',
                    };

                    $body = $row->description ?? null;

                    $contractId = DB::table('pm_contracts')->insertGetId([
                        'uuid'              => (string) Str::uuid(),
                        'company_id'        => $row->company_id ?? null,
                        'project_id'        => $row->project_id,
                        'project_member_id' => $row->project_member_id ?? null,
                        'contract_number'   => $row->contract_number ?: ('C' . $row->id),
                        'title'             => $row->title ?: 'Contract',
                        'status'            => $status,
                        'contract_value'    => $row->contract_value ?? 0,
                        'currency'          => $row->currency ?: 'ZAR',
                        'start_date'        => $row->start_date ?? null,
                        'end_date'          => $row->end_date ?? null,
                        'current_version'   => 1,
                        'created_by'        => $row->owner_id ?? null,
                        'created_at'        => $row->created_at ?? now(),
                        'updated_at'        => now(),
                    ]);

                    DB::table('pm_contract_versions')->insert([
                        'uuid'           => (string) Str::uuid(),
                        'company_id'     => $row->company_id ?? null,
                        'contract_id'    => $contractId,
                        'version_no'     => 1,
                        'body'           => $body,
                        'change_note'    => 'Adopted from Contract Management.',
                        'content_sha256' => $body === null ? null : hash('sha256', $body),
                        'created_by'     => $row->owner_id ?? null,
                        'created_at'     => $row->created_at ?? now(),
                        'updated_at'     => now(),
                    ]);

                    $moved[$row->id] = $contractId;
                }
            });

        $this->repointInvoices($moved);
    }

    /**
     * Contractor invoices counted against a contract, and that contract has moved.
     *
     * `pm_contractor_invoices.contract_id` pointed at Contract Management's table. Left alone it
     * would either keep resolving to the abandoned row or, worse, collide with an unrelated
     * `pm_contracts.id` — the same id-shape trap that 500'd the Delivery page once already. So the
     * constraint is dropped, the values are translated through the adoption map, and the new
     * constraint is added against the table the ids now belong to.
     *
     * @param  array<int,int>  $moved
     */
    private function repointInvoices(array $moved): void
    {
        if (! Schema::hasTable('pm_contractor_invoices')) {
            return;
        }

        Schema::table('pm_contractor_invoices', function (Blueprint $t) {
            $t->dropForeign(['contract_id']);
        });

        foreach ($moved as $old => $new) {
            DB::table('pm_contractor_invoices')->where('contract_id', $old)->update(['contract_id' => $new]);
        }

        // Anything left points at a contract that was never adopted, so it points at nothing.
        // Null is the honest value: the invoice still stands, it simply counts against no contract.
        if ($moved !== []) {
            DB::table('pm_contractor_invoices')
                ->whereNotIn('contract_id', array_values($moved))
                ->whereNotNull('contract_id')
                ->update(['contract_id' => null]);
        } else {
            DB::table('pm_contractor_invoices')->whereNotNull('contract_id')->update(['contract_id' => null]);
        }

        Schema::table('pm_contractor_invoices', function (Blueprint $t) {
            $t->foreign('contract_id')->references('id')->on('pm_contracts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // The invoice constraint has to let go before the table it points at can be dropped, and
        // the ids it holds are pm_contracts ids that mean nothing in the old table — so they are
        // cleared rather than translated back. The contracts themselves were never deleted.
        if (Schema::hasTable('pm_contractor_invoices')) {
            Schema::table('pm_contractor_invoices', function (Blueprint $t) {
                $t->dropForeign(['contract_id']);
            });

            DB::table('pm_contractor_invoices')->whereNotNull('contract_id')->update(['contract_id' => null]);

            if (Schema::hasTable('contracts')) {
                Schema::table('pm_contractor_invoices', function (Blueprint $t) {
                    $t->foreign('contract_id')->references('id')->on('contracts')->nullOnDelete();
                });
            }
        }

        Schema::dropIfExists('pm_contract_signatures');
        Schema::dropIfExists('pm_contract_versions');
        Schema::dropIfExists('pm_contracts');
        Schema::dropIfExists('pm_contract_templates');
    }

    /** Copied per-migration; this module has no shared base class. TenantSchemaTest enforces it. */
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
