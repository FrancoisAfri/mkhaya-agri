<?php

namespace App\Console\Commands\CRM;

use App\Modules\CRM\Jobs\PollCrmMailboxJob;
use App\Modules\CRM\Models\CrmMailbox;
use App\Modules\CRM\Services\CrmMailboxService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PollCrmMailboxes extends Command
{
    protected $signature   = 'crm:poll-mailboxes {--sync : Poll inline instead of fanning out to the queue}';
    protected $description = 'Poll active CRM mailboxes, AI-filter inbound email and register legitimate leads';

    public function handle(CrmMailboxService $service): int
    {
        if ($this->option('sync')) {
            $summary = $service->pollAll();
            $this->info("Mailboxes: {$summary['mailboxes']}  Processed: {$summary['processed']}  Leads: {$summary['leads']}  Complaints: {$summary['complaints']}  Spam: {$summary['spam']}");
            foreach ($summary['errors'] as $err) {
                $this->warn($err);
            }
            Log::info('crm:poll-mailboxes completed (sync)', $summary);

            return self::SUCCESS;
        }

        // Default: one queued job per active mailbox — parallel IMAP + AI triage.
        $count = 0;
        CrmMailbox::where('active', true)->pluck('id')->each(function ($id) use (&$count) {
            PollCrmMailboxJob::dispatch((int) $id)->onQueue('mailboxes');
            $count++;
        });

        $this->info("Dispatched {$count} mailbox poll job(s) to the 'mailboxes' queue.");

        return self::SUCCESS;
    }
}
