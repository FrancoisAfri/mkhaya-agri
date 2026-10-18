<?php

namespace App\Modules\Policies\Console;

use App\Modules\Policies\Services\AttestationService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

class SendAttestationReminders extends Command
{
    use RunsPerTenant;

    protected $signature = 'attestations:send-reminders
                            {--days=3 : Send reminders for attestations due within this many days}';

    protected $description = 'Send reminder notifications to employees with upcoming policy attestation deadlines';

    public function handle(AttestationService $service): int
    {
        $days = (int) $this->option('days');

        // Tenant-safe: per-company so reminders read each company's attestations, email the right
        // employees, and create reminder rows with the correct company_id.
        $this->eachTenant(app(TenantContext::class), function () use ($service, $days) {
            $markedOverdue = $service->markOverdue();
            if ($markedOverdue > 0) {
                $this->line("  Marked {$markedOverdue} attestation(s) as overdue.");
            }
            $sent = $service->sendReminders($days);
            $this->info("Sent {$sent} attestation reminder(s) for deadlines within {$days} day(s).");
        });

        return self::SUCCESS;
    }
}
