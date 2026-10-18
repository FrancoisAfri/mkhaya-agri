<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Operations\ProjectManagement\Models\ExternalRef;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Services\ProjectPartyResolver;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The accounting customer a project's client is.
 *
 * Nothing can be invoiced to somebody Accounting has never heard of, so this is the prerequisite
 * for a project invoice ever reaching the books — and the place a mistake is most expensive.
 * Resolving to the wrong customer does not fail; it posts a real debt against a company that does
 * not owe it, and is corrected with a credit note and a telephone call.
 */
class ProjectPartyResolverTest extends ProjectManagementTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Aseda Billing']);
        $this->entitleCompany($this->company);
    }

    private function resolver(): ProjectPartyResolver
    {
        return app(ProjectPartyResolver::class);
    }

    /** A project whose client ref is whatever the test needs it to be. */
    private function projectWithClient(array $ref): Project
    {
        return $this->asCompany($this->company, function () use ($ref) {
            $external = ExternalRef::create(array_merge([
                'local_type'    => 'project_client',
                'source_module' => 'local',
                'source_entity' => 'client',
                'display_name'  => 'Kwaggafontein Municipality',
                'sync_status'   => 'ok',
            ], $ref));

            return $this->makeProject(['client_ref_id' => $external->id]);
        });
    }

    private function customer(array $attributes): FinParty
    {
        return $this->asCompany($this->company, fn () => FinParty::create(array_merge([
            'kind' => 'customer',
            'code' => 'C' . Str::random(6),
            'credit_terms_days' => 30,
        ], $attributes)));
    }

    // ── The chain that already existed ───────────────────────────────────────

    /**
     * With CRM installed the link is complete and has simply never been walked:
     * project → ref (source_module 'crm') → fin_parties.crm_account_id.
     */
    public function test_a_crm_backed_client_resolves_through_the_account_it_points_at(): void
    {
        $accountId = $this->asCompany($this->company, fn () => DB::table('crm_accounts')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Sasol Limited', 'email' => 'ap@sasol.test',
            'company_id' => $this->company->id, 'created_at' => now(), 'updated_at' => now(),
        ]));

        $party = $this->customer(['name' => 'Sasol Limited', 'crm_account_id' => $accountId, 'source' => 'crm']);

        $project = $this->projectWithClient([
            'source_module' => 'crm', 'source_id' => (string) $accountId, 'display_name' => 'Sasol Limited',
        ]);

        $resolved = $this->asCompany($this->company, fn () => $this->resolver()->resolve($project));

        $this->assertNotNull($resolved, 'The CRM chain did not resolve end to end.');
        $this->assertSame($party->id, $resolved->id);
    }

    /**
     * `crm_accounts.id` is a bigint. A uuid handed to a numeric comparison raises 22P02 and aborts
     * the surrounding transaction — on the billing path that is a failed invoice run, not a miss.
     */
    public function test_a_non_numeric_source_id_is_not_handed_to_a_bigint_comparison(): void
    {
        $project = $this->projectWithClient([
            'source_module' => 'crm', 'source_id' => (string) Str::uuid(),
        ]);

        $this->assertNull($this->asCompany($this->company, fn () => $this->resolver()->resolve($project)));
    }

    // ── Standalone matching ──────────────────────────────────────────────────

    /** An email match is a fact. */
    public function test_a_local_client_matches_an_existing_customer_by_email(): void
    {
        $party = $this->customer(['name' => 'Kwaggafontein LM', 'email' => 'creditors@kwagga.test']);

        $project = $this->projectWithClient([
            'display_name' => 'Kwaggafontein Municipality',
            'snapshot' => ['email' => 'Creditors@Kwagga.test'],
        ]);

        $resolved = $this->asCompany($this->company, fn () => $this->resolver()->resolve($project));

        $this->assertSame($party->id, $resolved?->id, 'A differently-spelled name beat an exact email.');
    }

    /** Falling back to a name where there is no email is fine — where it is unambiguous. */
    public function test_a_local_client_matches_by_name_when_there_is_no_email(): void
    {
        $party = $this->customer(['name' => 'Kwaggafontein Municipality']);

        $project = $this->projectWithClient(['display_name' => 'kwaggafontein municipality']);

        $this->assertSame(
            $party->id,
            $this->asCompany($this->company, fn () => $this->resolver()->resolve($project))?->id,
        );
    }

    /**
     * Two customers with the same name is a real state — a duplicate nobody has merged. Picking
     * either would attach a project's whole billing history to a coin toss.
     */
    public function test_two_customers_of_the_same_name_are_never_guessed_between(): void
    {
        $this->customer(['name' => 'Kwaggafontein Municipality']);
        $this->customer(['name' => 'Kwaggafontein Municipality']);

        $project = $this->projectWithClient(['display_name' => 'Kwaggafontein Municipality']);

        $this->assertNull(
            $this->asCompany($this->company, fn () => $this->resolver()->resolve($project)),
            'An ambiguous name was resolved to one of two duplicates.',
        );
    }

    /** A supplier is not a customer, however well the name matches. */
    public function test_a_supplier_of_the_same_name_is_not_billed(): void
    {
        $this->customer(['name' => 'Kwaggafontein Municipality', 'kind' => 'supplier']);

        $project = $this->projectWithClient(['display_name' => 'Kwaggafontein Municipality']);

        $this->assertNull($this->asCompany($this->company, fn () => $this->resolver()->resolve($project)));
    }

    // ── Reporting versus creating ────────────────────────────────────────────

    /** `resolve()` reports what is true. A backfill must be able to ask about forty projects
     *  without changing any of them. */
    public function test_resolving_never_creates_a_customer(): void
    {
        $project = $this->projectWithClient(['display_name' => 'Brand New Client']);

        $before = $this->asCompany($this->company, fn () => FinParty::count());
        $this->asCompany($this->company, fn () => $this->resolver()->resolve($project));

        $this->assertSame($before, $this->asCompany($this->company, fn () => FinParty::count()));
    }

    /** The billing path does create, from what PM already knows. */
    public function test_resolve_or_create_builds_the_customer_from_what_the_project_knows(): void
    {
        $project = $this->projectWithClient([
            'display_name' => 'Brand New Client',
            'snapshot' => ['email' => 'ap@brandnew.test', 'vat_number' => '4370228530'],
        ]);

        $party = $this->asCompany($this->company, fn () => $this->resolver()->resolveOrCreate($project));

        $this->assertNotNull($party);
        $this->assertSame('Brand New Client', $party->name);
        $this->assertSame('ap@brandnew.test', $party->email);
        // Carried over so the invoice is a valid tax invoice without anybody retyping it.
        $this->assertSame('4370228530', $party->vat_number);
    }

    /** Twice must not make two. */
    public function test_resolve_or_create_is_idempotent(): void
    {
        $project = $this->projectWithClient([
            'display_name' => 'Brand New Client',
            'snapshot' => ['email' => 'ap@brandnew.test'],
        ]);

        $first  = $this->asCompany($this->company, fn () => $this->resolver()->resolveOrCreate($project));
        $second = $this->asCompany($this->company, fn () => $this->resolver()->resolveOrCreate($project));

        $this->assertSame($first->id, $second->id, 'A second billing run created a duplicate debtor.');
    }

    // ── Nobody to invoice ────────────────────────────────────────────────────

    /** Inventing a customer to fill the gap would put a phantom debtor in the ledger. */
    public function test_a_project_with_no_client_is_never_given_one(): void
    {
        $project = $this->asCompany($this->company, fn () => $this->makeProject(['client_ref_id' => null]));

        $this->assertNull($this->asCompany($this->company, fn () => $this->resolver()->resolveOrCreate($project)));
    }

    /** The reason has to be something a person can act on, not a false return. */
    public function test_it_says_why_a_project_cannot_be_billed(): void
    {
        $project = $this->asCompany($this->company, fn () => $this->makeProject(['client_ref_id' => null]));

        $this->assertSame(
            __('project_management.billing.no_client'),
            $this->asCompany($this->company, fn () => $this->resolver()->blockedReason($project)),
        );
    }

    public function test_a_project_with_a_client_is_not_reported_as_blocked(): void
    {
        $project = $this->projectWithClient(['display_name' => 'Kwaggafontein Municipality']);

        $this->assertNull($this->asCompany($this->company, fn () => $this->resolver()->blockedReason($project)));
    }
}
