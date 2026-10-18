<?php

namespace App\Modules\Shared\Party;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinParty;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One customer, one page — with only the sections the tenant actually has.
 *
 * Every module shows the same customer, so people stop hunting for "the CRM
 * version" versus "the Accounting version" of a client. Sections are assembled
 * from whatever is switched on: a tenant running CRM alone sees identity and
 * sales; add Accounting and the financial section appears, on the same page,
 * with no separate screen to learn.
 *
 * Adding a section later means adding one method here and one entry in
 * sections() — no changes to the page component.
 */
class CustomerProfileController extends Controller
{
    public function __construct(
        private readonly PartyDirectory $directory,
        private readonly ModuleAvailability $modules,
    ) {}

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search'));

        return Inertia::render('Modules/Shared/Customers/Index', [
            'customers' => $this->directory->search($search ?: '', 100)
                ->map(fn (FinParty $p) => [
                    'uuid'            => $p->uuid,
                    'name'            => $p->name,
                    'display_number'  => $this->directory->displayNumber($p),
                    'customer_number' => $p->customer_number,
                    'email'           => $p->email,
                    'kind'            => $p->kind,
                    'is_active'       => (bool) $p->is_active,
                ])->values(),
            'filters' => ['search' => $search],
        ]);
    }

    public function show(string $uuid): Response
    {
        $party = $this->directory->find($uuid);

        abort_unless($party, 404);

        return Inertia::render('Modules/Shared/Customers/Profile', [
            'identity' => $this->directory->identity($uuid),
            'sections' => $this->sections($party),
        ]);
    }

    /**
     * Build only the sections this tenant can see. Each entry is self-describing
     * so the page renders them generically.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sections(FinParty $party): array
    {
        $sections = [];

        if ($this->modules->active('accounting')) {
            $sections[] = [
                'key'   => 'financial',
                'title' => 'customer_profile.section.financial',
                'icon'  => 'tabler-cash',
                'data'  => $this->financialSection($party),
            ];
        }

        if ($this->modules->active('crm')) {
            $sections[] = [
                'key'   => 'sales',
                'title' => 'customer_profile.section.sales',
                'icon'  => 'tabler-target-arrow',
                'data'  => $this->salesSection($party),
            ];
        }

        return $sections;
    }

    /**
     * Debtor terms plus a live AR position. Invoice totals are read straight from
     * fin_invoices so the figure always matches Accounting rather than a cached
     * copy that can drift.
     *
     * @return array<string, mixed>
     */
    private function financialSection(FinParty $party): array
    {
        $profile = $this->directory->financialProfile($party->uuid) ?? [];

        $ar = ['invoice_count' => 0, 'outstanding' => 0.0, 'overdue' => 0.0];

        if (Schema::hasTable('fin_invoices')) {
            $rows = DB::table('fin_invoices')
                ->where('party_id', $party->id)
                ->where('direction', 'receivable')
                ->whereIn('status', ['open', 'part_paid'])
                ->select('total', 'amount_paid', 'due_date')
                ->get();

            $ar['invoice_count'] = $rows->count();
            $ar['outstanding'] = round($rows->sum(fn ($r) => (float) $r->total - (float) $r->amount_paid), 2);
            $ar['overdue'] = round(
                $rows->filter(fn ($r) => $r->due_date && $r->due_date < now()->toDateString())
                    ->sum(fn ($r) => (float) $r->total - (float) $r->amount_paid),
                2
            );
        }

        return $profile + ['ar' => $ar];
    }

    /**
     * The CRM view of the same customer. Read through the existing link on the
     * party rather than a second copy of the relationship.
     *
     * @return array<string, mixed>
     */
    private function salesSection(FinParty $party): array
    {
        if (! $party->crm_account_id || ! Schema::hasTable('crm_accounts')) {
            return ['linked' => false];
        }

        $account = DB::table('crm_accounts')->where('id', $party->crm_account_id)->first();

        if (! $account) {
            return ['linked' => false];
        }

        return [
            'linked'   => true,
            'id'       => $account->id,
            'industry' => $account->industry ?? null,
            'website'  => $account->website ?? null,
            'city'     => $account->city ?? null,
            'country'  => $account->country ?? null,
        ];
    }
}
