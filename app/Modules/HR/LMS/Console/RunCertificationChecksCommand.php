<?php

namespace App\Modules\HR\LMS\Console;

use App\Modules\HR\LMS\Services\LmsCertificationService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

class RunCertificationChecksCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'lms:certification-checks
                            {--expire    : Mark expired certificates}
                            {--reminders : Send expiry reminder notifications}
                            {--refresher : Assign refresher training courses}';

    protected $description = 'Run LMS certification expiry checks, reminders, and refresher assignments.';

    public function __construct(private readonly LmsCertificationService $service) { parent::__construct(); }

    public function handle(): int
    {
        // Tenant-safe: per-company so certificate reads, reminder recipients and refresher
        // enrollments are scoped to the company that owns each certification.
        $this->eachTenant(app(TenantContext::class), function () {
            if ($this->option('expire')) {
                $count = $this->service->trackCertificateExpiry();
                $this->info("Marked {$count} certificate(s) as expired.");
            }

            if ($this->option('reminders')) {
                $count = $this->service->sendCertificateExpiryReminder();
                $this->info("Sent {$count} expiry reminder notification(s).");
            }

            if ($this->option('refresher')) {
                $count = $this->service->assignRefresherTraining(actorId: 0);
                $this->info("Assigned {$count} refresher training enrollment(s).");
            }
        });

        return self::SUCCESS;
    }
}
