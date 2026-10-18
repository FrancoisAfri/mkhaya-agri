<?php

namespace App\Modules\Procurement\Governance\Support\Presenters;

use App\Modules\Procurement\Governance\Services\Capabilities;
use App\Modules\Procurement\Governance\Services\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The dropdown lists every form in this module needs — departments, officers, projects, categories,
 * financial years.
 *
 * Each list comes from whichever module owns it and returns an empty array when that module is not
 * installed, so a form degrades to a free-text field instead of showing an empty required select
 * that cannot be satisfied. Categories are the clearest case: Supplier Management owns a category
 * list, and a tenant without it types a category instead of picking one. Neither path blocks
 * planning.
 */
class OptionLists
{
    /**
     * The jurisdictions this product is sold into, with the currency each ordinarily tenders in.
     *
     * Deliberately a short explicit list rather than every ISO country. A jurisdiction here means
     * "we have a preference framework and a currency story for this place" — offering Kenya in a
     * dropdown when neither exists would be a promise the module cannot keep. Extending it is a
     * code change *and* a framework, which is the right amount of friction.
     */
    public const JURISDICTIONS = [
        ['code' => 'ZA', 'name' => 'South Africa', 'currency' => 'ZAR'],
        ['code' => 'SZ', 'name' => 'Eswatini', 'currency' => 'SZL'],
        ['code' => 'BW', 'name' => 'Botswana', 'currency' => 'BWP'],
    ];

    /** §2.1 procurement methods. Fixed set, because the rules engine's conditions select on them. */
    public const PROCUREMENT_METHODS = [
        'OPEN_TENDER',
        'RFQ',
        'LIMITED_BID',
        'SOLE_SOURCE',
        'PANEL',
        'TRANSVERSAL',
        'PETTY',
    ];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly Capabilities $capabilities,
    ) {}

    /**
     * Departments are company units of type `department` — the platform's own org structure, which
     * is where every other module reads them from.
     *
     * @return list<array{id:int,name:string}>
     */
    public function departments(int $companyId): array
    {
        return DB::table('company_units')
            ->where('company_id', $companyId)
            ->where('active', true)
            ->when(
                Schema::hasColumn('company_units', 'type'),
                fn ($q) => $q->where(fn ($inner) => $inner->where('type', 'department')->orWhereNull('type'))
            )
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name'])
            ->map(fn ($u) => ['id' => (int) $u->id, 'name' => (string) $u->name])
            ->all();
    }

    /**
     * Procurement officers: users who can act as one. There is no `users.name` column on this
     * platform, so the label comes from the HR record and falls back to the login email.
     *
     * @return list<array{id:int,name:string}>
     */
    public function officers(int $companyId): array
    {
        /*
         * The tenant's own users, plus whoever is currently working inside the tenant.
         *
         * A platform administrator has no company_id — that is what makes them platform-level — so
         * filtering on company_id alone silently removes them from every person-picker in the
         * module. The effect is that the person setting a tenant up cannot appoint themselves
         * chairperson, secretary, procurement officer or witness, with no error to explain why:
         * their own name is simply absent from a list they are looking straight at.
         *
         * Only the current user is added, not every platform administrator. They are demonstrably
         * operating in this tenant right now, whereas listing all of them would put a directory of
         * the vendor's staff into every customer's dropdowns to solve a problem nobody has.
         */
        $currentUserId = auth()->id();

        return DB::table('users as u')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->where(function ($q) use ($companyId, $currentUserId) {
                $q->where('u.company_id', $companyId);

                if ($currentUserId) {
                    $q->orWhere('u.id', $currentUserId);
                }
            })
            ->whereNull('u.deleted_at')
            ->orderByRaw("coalesce(nullif(trim(coalesce(p.first_name,'') || ' ' || coalesce(p.surname,'')), ''), u.email)")
            ->limit(1000)
            ->get([
                'u.id',
                'u.email',
                DB::raw("trim(coalesce(p.first_name,'') || ' ' || coalesce(p.surname,'')) as full_name"),
            ])
            ->map(fn ($u) => [
                'id' => (int) $u->id,
                'name' => trim((string) $u->full_name) ?: (string) $u->email,
            ])
            ->all();
    }

    /** @return list<array{id:int,name:string,code:string|null}> */
    public function projects(int $companyId): array
    {
        if (! $this->capabilities->moduleInstalled('project_management') || ! Schema::hasTable('pm_projects')) {
            return [];
        }

        return DB::table('pm_projects')
            ->where('company_id', $companyId)
            ->whereIn('status', ['active', 'planning', 'draft'])
            ->when(Schema::hasColumn('pm_projects', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'code', 'name'])
            ->map(fn ($p) => ['id' => (int) $p->id, 'code' => $p->code, 'name' => (string) $p->name])
            ->all();
    }

    /**
     * Procurement categories for the picker.
     *
     * The tenant's configured list leads, because that is the one that groups correctly in a spend
     * report. Supplier Management's categories and anything already typed onto a record are merged
     * in behind it so nothing already filed becomes unselectable — but new work is steered onto the
     * configured vocabulary.
     *
     * @return list<string>
     */
    public function procurementCategories(int $companyId): array
    {
        $configured = array_column($this->optionList($companyId, 'procurement_category'), 'value');

        $fromSuppliers = [];

        if ($this->capabilities->moduleInstalled('supplier_management') && Schema::hasTable('supplier_categories')) {
            $fromSuppliers = DB::table('supplier_categories')
                ->where('status', 'active')
                ->when(
                    Schema::hasColumn('supplier_categories', 'company_id'),
                    fn ($q) => $q->where('company_id', $companyId)
                )
                ->whereNull('deleted_at')
                ->orderBy('name')
                ->limit(300)
                ->pluck('name')
                ->all();
        }

        $used = DB::table('procgov_records')
            ->where('company_id', $companyId)
            ->whereNotNull('category')
            ->distinct()
            ->limit(300)
            ->pluck('category')
            ->all();

        $planned = DB::table('procgov_plan_items')
            ->where('company_id', $companyId)
            ->whereNotNull('category')
            ->distinct()
            ->limit(300)
            ->pluck('category')
            ->all();

        $extra = array_diff(
            array_values(array_unique(array_filter(array_merge($fromSuppliers, $used, $planned)))),
            $configured,
        );
        sort($extra);

        return array_values(array_merge($configured, $extra));
    }

    /**
     * A tenant-configurable pick list, e.g. `procurement_category` or `committee_type`.
     *
     * These were free-text fields, which produced an empty box on a new tenant and — worse — "ICT",
     * "I.C.T." and "ict" as three categories that never group together in a spend report. A category
     * people type is a category nobody can report on.
     *
     * @return list<array{value:string,title:string}>
     */
    public function optionList(int $companyId, string $listKey): array
    {
        if (! Schema::hasTable('procgov_option_lists')) {
            return [];
        }

        return DB::table('procgov_option_lists')
            ->where('company_id', $companyId)
            ->where('list_key', $listKey)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get(['code', 'label'])
            ->map(fn ($o) => ['value' => (string) $o->label, 'title' => (string) $o->label])
            ->all();
    }

    /** §1.2 committee types — bid evaluation, bid adjudication, and whatever else a tenant runs. */
    public function committeeTypes(int $companyId): array
    {
        return $this->optionList($companyId, 'committee_type');
    }

    /**
     * Financial year labels: the tenant's own years, plus the current and next one so a plan can
     * always be created for the year ahead even on a brand-new install.
     *
     * @return list<string>
     */
    public function financialYearLabels(int $companyId): array
    {
        $used = DB::table('procgov_plans')
            ->where('company_id', $companyId)
            ->distinct()
            ->pluck('financial_year_label')
            ->filter()
            ->all();

        $current = $this->settings->financialYearLabel($companyId);
        $next = $this->settings->financialYearLabel($companyId, Carbon::today()->addYear());
        $previous = $this->settings->financialYearLabel($companyId, Carbon::today()->subYear());

        $all = array_values(array_unique(array_merge($used, [$previous, $current, $next])));
        rsort($all);

        return $all;
    }

    /** @return list<array{id:int,name:string}> committees available to chair or staff a review */
    public function committees(int $companyId): array
    {
        return DB::table('procgov_committees')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('status', 'ACTIVE')
            ->orderBy('name')
            ->get(['id', 'name', 'reference'])
            ->map(fn ($c) => ['id' => (int) $c->id, 'name' => "{$c->reference} — {$c->name}"])
            ->all();
    }
}
