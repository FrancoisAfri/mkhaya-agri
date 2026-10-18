<?php

namespace App\Modules\Finance\Tax\Support;

use App\Modules\Finance\Accounting\Models\FinEntity;
use App\Modules\Finance\Accounting\Services\CurrentEntity;
use App\Modules\Tenancy\Services\CompanyEntitlements;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Which businesses tax can be kept for.
 *
 *   Accounting enabled  → the businesses set up in Accounting (Configure → Businesses). Tax is
 *                         reconciled to each one's own ledger, so they must be the same list.
 *   Accounting not used → the company itself, under the name it was registered with.
 *
 * "Enabled" is the same test the module gate applies — the plan in SaaS, the signed licence when
 * self-hosted — so a standalone install and a tenant on the platform get the same answer from the
 * same rule. Either way a tax record points at a business row (fin_entities): for a company without
 * Accounting that is its default business, created on first use and named after the company, which
 * means switching Accounting on later keeps every tax record where it was.
 */
class TaxBusinesses
{
    public function __construct(
        private readonly CompanyEntitlements $entitlements,
        private readonly CurrentCompany $company,
        private readonly CurrentEntity $entities,
    ) {
    }

    public function accountingEnabled(): bool
    {
        $installed = DB::table('security_modules')->where('code_name', 'accounting')->where('active', 1)->exists();

        return $installed && $this->entitlements->currentAllowsModule('accounting');
    }

    /** @var array<int, list<array{id:int, name:string}>> per company, for this instance's lifetime (one request) */
    private array $memo = [];

    /** @return list<array{id:int, name:string}> */
    public function options(): array
    {
        $companyId = $this->companyId();
        if ($companyId === null) {
            return [];
        }

        // Lists name every row's business, so this is asked once per row: answer it once per instance.
        return $this->memo[$companyId] ??= $this->load($companyId);
    }

    /** @return list<array{id:int, name:string}> */
    private function load(int $companyId): array
    {

        if ($this->accountingEnabled()) {
            return FinEntity::query()->withoutGlobalScopes()->where('company_id', $companyId)->where('status', 'active')
                ->orderByDesc('is_default')->orderBy('name')->get(['id', 'name'])
                ->map(fn ($e) => ['id' => (int) $e->id, 'name' => $e->name])->all();
        }

        $default = $this->entities->defaultFor($companyId);
        $name = DB::table('companies')->where('id', $companyId)->value('name') ?: $default?->name;

        return $default ? [['id' => (int) $default->id, 'name' => (string) $name]] : [];
    }

    /** @return list<int> */
    public function ids(): array
    {
        return array_column($this->options(), 'id');
    }

    /** The only business when there is one — the forms then preselect it and hide the choice. */
    public function single(): ?int
    {
        $ids = $this->ids();

        return count($ids) === 1 ? $ids[0] : null;
    }

    /** A requested business filter, honoured only if it is one of this company's. */
    public function filter(mixed $requested): ?int
    {
        $id = is_numeric($requested) ? (int) $requested : null;

        return $id && in_array($id, $this->ids(), true) ? $id : null;
    }

    public function name(?int $id): ?string
    {
        if (! $id) {
            return null;
        }
        foreach ($this->options() as $o) {
            if ($o['id'] === $id) {
                return $o['name'];
            }
        }

        return DB::table('fin_entities')->where('id', $id)->value('name');
    }

    private function companyId(): ?int
    {
        return $this->company->id() ?? Auth::user()?->company_id;
    }
}
