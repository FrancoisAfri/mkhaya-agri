<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Services\PartySyncService;
use App\Modules\Operations\ProjectManagement\Models\ExternalRef;
use App\Modules\Operations\ProjectManagement\Models\Project;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The accounting customer a project's client *is*.
 *
 * Nothing can be invoiced to somebody Accounting has never heard of, so this is the prerequisite
 * for a project invoice ever reaching the books. It answers one question — given a project, which
 * `fin_parties` row is its client — and it is the only place that question is answered.
 *
 * ── The link mostly already exists ──────────────────────────────────────────
 *
 * Both modules already keep their clients in step with CRM, independently and without knowing
 * about each other: PM points a `project_client` ref at a CRM account, and Accounting mirrors CRM
 * accounts into `fin_parties.crm_account_id`. So on a tenant running CRM there is a complete chain
 * already — it has simply never been walked end to end:
 *
 *     pm_projects.client_ref_id → pm_external_refs (source_module='crm') → fin_parties.crm_account_id
 *
 * ── Standalone is the case that needs work ──────────────────────────────────
 *
 * Without CRM the ref is `source_module = 'local'`: a name PM owns, pointing at nothing. Accounting
 * may still have that customer, typed in by hand by whoever raises the invoices, and the two have
 * no idea they are the same company. Matching them is the same problem `ClientSyncService::adopt()`
 * already solved between PM and CRM, and it is solved the same way here, for the same reasons:
 *
 *   **email first, then name.** An email match is a fact; a name match is a guess. Matching on name
 *   alone is how "Sasol" and "Sasol Ltd" become one customer belonging to neither, and how an
 *   invoice ends up against the wrong debtor — which is corrected with a credit note and a
 *   telephone call, not a migration.
 *
 * ── Creating, and when not to ───────────────────────────────────────────────
 *
 * `resolve()` never creates. It reports what is already true, which is what a backfill and a
 * pre-flight report need. `resolveOrCreate()` is the billing path and will create the customer from
 * what PM already knows, because refusing to invoice a client whose name is right there is not a
 * safety measure — it is a job somebody does by hand at month end.
 */
class ProjectPartyResolver
{
    public function __construct(private readonly PartySyncService $parties)
    {
    }

    /**
     * The existing accounting customer for this project's client, or null.
     *
     * Never creates. Null means "no counterpart today", which is a thing a report needs to be able
     * to say about forty projects at once without changing any of them.
     */
    public function resolve(Project $project): ?FinParty
    {
        if (! $this->accountingInstalled()) {
            return null;
        }

        $ref = $project->clientRef;

        if (! $ref) {
            return null;
        }

        return $this->fromCrm($ref) ?? $this->matchLocally($ref);
    }

    /**
     * The accounting customer for this project's client, creating one where none exists.
     *
     * The billing path. A project with no client at all still returns null — there is nobody to
     * invoice, and inventing a customer to hold the gap would put a phantom debtor in the ledger.
     */
    public function resolveOrCreate(Project $project): ?FinParty
    {
        if (! $this->accountingInstalled()) {
            return null;
        }

        $ref = $project->clientRef;

        if (! $ref) {
            return null;
        }

        return $this->resolve($project) ?? $this->createFrom($ref);
    }

    /**
     * Why a project cannot be billed, in words somebody can act on.
     *
     * Used by the pre-flight report. A count of failures is not a task list; the name of the
     * project and the reason is.
     */
    public function blockedReason(Project $project): ?string
    {
        if (! $this->accountingInstalled()) {
            return __('project_management.billing.no_accounting');
        }

        if (! $project->clientRef) {
            return __('project_management.billing.no_client');
        }

        return null;
    }

    // ── The CRM chain ────────────────────────────────────────────────────────

    /**
     * The party mirrored from the CRM account this ref points at.
     *
     * `partyForCrmAccount()` creates the mirror where the account exists but has not been synced
     * yet, which is Accounting's own business and exactly what it is for — this is not PM inventing
     * a customer, it is PM asking Accounting to finish a sync it already performs on a schedule.
     */
    private function fromCrm(ExternalRef $ref): ?FinParty
    {
        if ($ref->source_module !== 'crm' || ! $ref->source_id) {
            return null;
        }

        // `crm_accounts.id` is a bigint. A uuid handed to a numeric comparison raises 22P02 and
        // aborts the surrounding transaction, which on the billing path is a failed invoice run
        // rather than a missed match.
        if (! ctype_digit((string) $ref->source_id)) {
            return null;
        }

        return $this->parties->partyForCrmAccount((int) $ref->source_id);
    }

    // ── Standalone matching ──────────────────────────────────────────────────

    /**
     * An existing accounting customer that is plainly the same company.
     *
     * Email, then name, and nothing cleverer. A fuzzy match here is a wrong debtor.
     */
    private function matchLocally(ExternalRef $ref): ?FinParty
    {
        $email = $this->snapshotValue($ref, 'email');

        if ($email) {
            $byEmail = FinParty::query()
                ->customers()
                ->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])
                ->first();

            if ($byEmail) {
                return $byEmail;
            }
        }

        $name = trim((string) $ref->display_name);

        if ($name === '') {
            return null;
        }

        /*
         * Exactly one, or none.
         *
         * Two customers with the same name is a real state — a duplicate somebody has not merged —
         * and picking either would attach a project's whole billing history to a coin toss. The
         * report says "ambiguous" and a person decides once.
         */
        $matches = FinParty::query()
            ->customers()
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->limit(2)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** A customer built from what PM already knows about this client. */
    private function createFrom(ExternalRef $ref): ?FinParty
    {
        $name = trim((string) $ref->display_name);

        if ($name === '') {
            return null;
        }

        return FinParty::create(array_filter([
            'kind'    => 'customer',
            'name'    => $name,
            'email'   => $this->snapshotValue($ref, 'email'),
            'phone'   => $this->snapshotValue($ref, 'phone'),
            'address' => $this->snapshotValue($ref, 'address'),
            'vat_number' => $this->snapshotValue($ref, 'vat_number'),
            'registration_number' => $this->snapshotValue($ref, 'registration_number'),
            // Says where it came from, so a later CRM reconcile can adopt it rather than duplicate
            // it — `PartySyncService::reconcileManualCustomers()` walks exactly this.
            'source'  => 'manual',
            'is_active' => true,
        ], fn ($value) => $value !== null && $value !== ''));
    }

    // ── Plumbing ─────────────────────────────────────────────────────────────

    /**
     * Guarded on the table rather than the module registry.
     *
     * PM has to keep working on a tenant that never bought Accounting, and a missing table is the
     * honest test for that — the same test `CrmClientProvider` already makes for the same reason.
     */
    private function accountingInstalled(): bool
    {
        return Schema::hasTable('fin_parties');
    }

    private function snapshotValue(ExternalRef $ref, string $key): ?string
    {
        $value = is_array($ref->snapshot) ? ($ref->snapshot[$key] ?? null) : null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
