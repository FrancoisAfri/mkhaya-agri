<?php

namespace App\Modules\Operations\ProjectManagement\Providers\Local;

use App\Modules\Operations\ProjectManagement\Contracts\Providers\PurchaseProvider;
use App\Modules\Operations\ProjectManagement\Support\Providers\ProvidedRecord;
use RuntimeException;

/**
 * Purchases against a project, when Procurement is not installed.
 *
 * PM raises no requisitions and issues no orders of its own — that is Procurement's job, and
 * inventing a second purchasing workflow inside PM would be worse than having none. So this
 * answers with an empty list and `isAvailable() === false`, and the UI hides the tab entirely
 * rather than showing an empty one that looks broken (PM-1042).
 */
class LocalPurchaseProvider implements PurchaseProvider
{
    public function forProject(string $projectId): array
    {
        return [];
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function canRaise(): bool
    {
        return false;
    }

    /**
     * Never reached from the UI, which hides the action when `canRaise()` is false.
     *
     * It throws rather than silently recording something PM-side: a "requisition" that no
     * purchasing system has heard of is a promise nobody is going to keep.
     */
    public function raise(string $projectId, array $data): ProvidedRecord
    {
        throw new RuntimeException(__('project_management.purchases.unavailable'));
    }
}
