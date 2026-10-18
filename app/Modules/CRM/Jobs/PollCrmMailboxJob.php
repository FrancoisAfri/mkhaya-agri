<?php

namespace App\Modules\CRM\Jobs;

use App\Modules\CRM\Models\CrmMailbox;
use App\Modules\CRM\Services\CrmMailboxService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Polls ONE CRM mailbox (IMAP fetch → AI triage → leads/complaints). Fanned out
 * one per active mailbox onto the `mailboxes` queue so the IMAP + per-email AI
 * classification runs in parallel across workers rather than serially for every
 * tenant in a single cron process. Runs in the mailbox's tenant context (RLS).
 */
class PollCrmMailboxJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 240;

    public function __construct(public int $mailboxId)
    {
    }

    public function handle(TenantContext $tenant, CrmMailboxService $service): void
    {
        $mailbox = CrmMailbox::find($this->mailboxId);
        if (! $mailbox || ! $mailbox->active) {
            return;
        }

        try {
            $tenant->run($mailbox->company_id, fn () => $service->poll($mailbox));
        } catch (\Throwable $e) {
            Log::warning('crm mailbox poll failed', ['mailbox_id' => $this->mailboxId, 'error' => $e->getMessage()]);
        }
    }
}
