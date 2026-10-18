<?php

namespace App\Modules\Operations\ProjectManagement\Contracts\Providers;

use App\Modules\Operations\ProjectManagement\Models\Project;

/**
 * Where a project's budget comes from, and where its spend is booked.
 *
 * Three finance capabilities meet here because they are one question in practice — "what is this
 * job allowed to cost, against which cost centre, and what has been invoiced on it?":
 *
 *   finance.budgets       the allocated amount, from the budget register rather than typed twice
 *   finance.cost_centres  the account spend is booked against
 *   finance.invoices      paid supplier invoices, which are actual cost rather than committed
 *
 * A budget held in two places is a budget that disagrees with itself the first time somebody
 * revises one of them. When Finance owns it, PM reads it and stops asking.
 *
 * The whole Project is passed rather than an id, deliberately. `budgets.scope_id` is a bigint and
 * PM addresses everything by uuid, so a method taking "the project id" invites the caller to hand
 * over the wrong one — which is not a miss but a `22P02` that aborts the transaction. Passing the
 * model removes the choice.
 */
interface BudgetProvider
{
    /**
     * The budget linked to this project, if any.
     *
     * @return array{
     *     id:string, label:string, amount:float, currency:?string,
     *     spent:?float, cost_centre:?string
     * }|null
     */
    public function forProject(Project $project): ?array;

    /** Budgets a person could link a project to. @return list<array{id:string,label:string,amount:float}> */
    public function search(string $query, int $limit = 20): array;

    /**
     * Paid supplier invoices against this project, as actual cost.
     *
     * Paid only: an invoice that has arrived is committed, and counting it as spent would report
     * money as gone before it left.
     */
    public function paidInvoiceTotal(Project $project): float;

    /** Cost centres the tenant keeps, for booking project spend. @return list<array{id:string,label:string}> */
    public function costCentres(): array;

    public function isAvailable(): bool;
}
