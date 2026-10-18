<?php

namespace App\Modules\Operations\ProjectManagement\Providers\Local;

use App\Modules\Operations\ProjectManagement\Contracts\Providers\BudgetProvider;
use App\Modules\Operations\ProjectManagement\Models\Project;

/**
 * Budget, when Finance is not installed.
 *
 * PM keeps its own `budget_amount` on the project, which is what the Delivery page reads. There is
 * nothing to link to and no cost centre to book against, and this says so rather than offering an
 * empty picker that looks like a missing setting.
 */
class LocalBudgetProvider implements BudgetProvider
{
    public function forProject(Project $project): ?array
    {
        return null;
    }

    public function search(string $query, int $limit = 20): array
    {
        return [];
    }

    public function paidInvoiceTotal(Project $project): float
    {
        return 0.0;
    }

    public function costCentres(): array
    {
        return [];
    }

    public function isAvailable(): bool
    {
        return false;
    }
}
