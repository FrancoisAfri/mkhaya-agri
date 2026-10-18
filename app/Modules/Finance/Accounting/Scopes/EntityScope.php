<?php

namespace App\Modules\Finance\Accounting\Scopes;

use App\Modules\Finance\Accounting\Services\CurrentEntity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Limits a query to the trading entity the request is working in.
 *
 * The same shape as CompanyScope, one level down: CompanyScope keeps one tenant's data away from
 * another's, this keeps one of a tenant's businesses out of another's books. Both apply by
 * default, because the failure they prevent is silent — a report that reads correctly and adds
 * Mkhaya Move's revenue to Mkhaya MK's is found by whoever reconciles to the bank, weeks later.
 *
 * ── When it does not apply ───────────────────────────────────────────────────
 *
 * With no entity resolved — a console pass with no tenant, a queued job outside any context — the
 * scope stays out of the way rather than filtering on null and returning nothing. That mirrors
 * CompanyScope, and it is why `entity_id` stays nullable through Phase 1: a row written without a
 * context is still readable while the migration is being proven.
 *
 * Crossing entities on purpose — a consolidated trial balance across a tenant's businesses — is
 * `withoutEntityScope()`, which is deliberately easy to find in a search.
 *
 * ── The system's own rows ────────────────────────────────────────────────────
 *
 * A few rows belong to nobody: Bad Debts, Retention Receivable, Staff Loans Receivable, installed
 * by migration with no company and no business. Every tenant posts to them, and hiding them would
 * make a write-off fail to find its contra account in every business in the system. So they are
 * let through — the same allowance CompanyScope makes for shared library rows.
 *
 * Both halves of that test are load-bearing, and the second is what makes the first safe. Through
 * Phase 2 this clause was wider: any row with no business was readable in all of them, because
 * while the ledger was half converted a row that escaped stamping had to stay visible — books that
 * silently stop adding up are worse than a row appearing twice.
 *
 * Phase 4 removed the case rather than the rule. Every finance table now carries
 * `CHECK (company_id IS NULL OR entity_id IS NOT NULL)`: a tenant's row cannot exist without a
 * business, so "no business" now means "belongs to nobody" and nothing else. Narrowing the clause
 * to match is what makes the separation absolute — without the constraint it would hide a
 * tenant's unstamped rows from every one of their businesses, which is the failure the wider form
 * existed to avoid.
 */
class EntityScope implements Scope
{
    /**
     * Which of these tables name a company, asked of the schema once rather than per query.
     *
     * This scope is on the path of every finance read — a hundred invoices is a hundred calls —
     * and the answer cannot change while the process runs. Memoising CurrentEntity for the same
     * reason took a listing from 40 statements to 20; a schema lookup is dearer than that one was.
     *
     * @var array<string, bool>
     */
    private static array $hasCompanyColumn = [];

    public function apply(Builder $builder, Model $model): void
    {
        $entityId = app(CurrentEntity::class)->id();

        if ($entityId === null) {
            return;
        }

        $table = $model->getTable();
        $column = $model->getEntityColumn();

        /*
         * "Belongs to nobody" is read off company_id, and a few of these tables have no such
         * column — fin_nonpayment_reasons is a shared list with nothing tenant-specific on it, so
         * it was never given one. Naming the column anyway turned every screen that reads such a
         * table into a 500 rather than a leak, which is the better failure but still a failure.
         *
         * Where the column is absent, every row of the table already belongs to nobody, so a null
         * business is the whole of the allowance.
         */
        $hasCompany = self::$hasCompanyColumn[$table] ??= $model->getConnection()
            ->getSchemaBuilder()
            ->hasColumn($table, 'company_id');

        $builder->where(function (Builder $query) use ($table, $column, $entityId, $hasCompany) {
            $query->where($table.'.'.$column, $entityId)
                ->orWhere(fn (Builder $nobodys) => $hasCompany
                    ? $nobodys->whereNull($table.'.'.$column)->whereNull($table.'.company_id')
                    : $nobodys->whereNull($table.'.'.$column));
        });
    }
}
