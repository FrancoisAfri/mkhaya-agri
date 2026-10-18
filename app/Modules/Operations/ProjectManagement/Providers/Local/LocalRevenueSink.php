<?php

namespace App\Modules\Operations\ProjectManagement\Providers\Local;

use App\Modules\Operations\ProjectManagement\Contracts\Providers\RevenueSink;
use App\Modules\Operations\ProjectManagement\Models\Invoice;

/**
 * Without Accounting, an issued invoice stays exactly what it has always been: a document.
 *
 * Nothing is recorded and nothing pretends to be. `postsToLedger()` returning false is the whole
 * contract — it is what stops a screen telling somebody their books have been updated when the
 * module that would have updated them is not installed.
 */
class LocalRevenueSink implements RevenueSink
{
    public function post(Invoice $invoice): ?string
    {
        return null;
    }

    public function reverse(Invoice $invoice, string $reason): ?string
    {
        return null;
    }

    public function postsToLedger(): bool
    {
        return false;
    }
}
