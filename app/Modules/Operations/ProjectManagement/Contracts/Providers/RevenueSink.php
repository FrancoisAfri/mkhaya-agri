<?php

namespace App\Modules\Operations\ProjectManagement\Contracts\Providers;

use App\Modules\Operations\ProjectManagement\Models\Invoice;

/**
 * Where an issued project invoice goes.
 *
 * Local: nowhere — it stays a document PM produced, which is what it has always been. Integrated:
 * a receivable in Accounting, so the client becomes a debtor and the revenue reaches the books.
 *
 * The asymmetry with `ExpenseSink` is deliberate and is the whole reason this exists. Approved
 * costs have had a route to the ledger for some time; issued invoices have had none, so a project
 * that spent money and billed for it showed only the spending.
 */
interface RevenueSink
{
    /**
     * Record an issued invoice as revenue.
     *
     * @return string|null the receiving system's reference, or null when nothing was recorded
     */
    public function post(Invoice $invoice): ?string;

    /**
     * Reverse one, because the invoice was withdrawn.
     *
     * A credit note, never a deletion: the client has the document, and a receivable that vanishes
     * is a receivable nobody can explain at year end.
     */
    public function reverse(Invoice $invoice, string $reason): ?string;

    /** False when invoices simply stay in PM — so no screen claims the books moved. */
    public function postsToLedger(): bool;
}
