<?php

namespace App\Modules\Finance\Accounting\Jobs;

use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Services\DunningService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends one debtor reminder off the request thread (rendering the invoice PDF + emailing is slow).
 * Runs inside the invoice's company context so it is tenant-safe on the queue, where the global
 * company scope is otherwise off. Used by the manual "Send reminder" and the bulk action.
 */
class SendDebtorReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        private int $invoiceId,
        private ?int $companyId,
        private ?string $channel = null,
        private ?string $tone = null,
    ) {
    }

    public function handle(TenantContext $tenant, DunningService $dunning): void
    {
        $tenant->run($this->companyId, function () use ($dunning) {
            $invoice = FinInvoice::find($this->invoiceId);
            if ($invoice) {
                $dunning->sendReminder($invoice, $this->channel, $this->tone);
            }
        });
    }
}
