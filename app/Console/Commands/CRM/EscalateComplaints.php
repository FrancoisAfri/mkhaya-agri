<?php

namespace App\Console\Commands\CRM;

use App\Modules\CRM\Models\CrmComplaint;
use App\Modules\CRM\Services\CrmComplaintService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Drives the complaint lifecycle SLA: escalates complaints that have breached
 * their resolve-by deadline (or sat unresolved past the configured window) and
 * aren't yet escalated, notifying managers / execs / admins.
 */
class EscalateComplaints extends Command
{
    use RunsPerTenant;

    protected $signature   = 'crm:escalate-complaints';
    protected $description = 'Escalate complaints that have breached their SLA';

    public function handle(CrmComplaintService $service): int
    {
        $escalated = 0;

        $this->eachTenant(app(TenantContext::class), function () use ($service, &$escalated) {
            $afterHours = (int) ($service->settings()['escalate_after_hours'] ?? 24);

            $due = CrmComplaint::where('type', 'complaint')
                ->whereNotIn('status', ['resolved', 'closed'])
                ->where('escalated', false)
                ->where(function ($q) use ($afterHours) {
                    $q->where(fn ($w) => $w->whereNotNull('resolve_by')->where('resolve_by', '<', now()))
                      ->orWhere('created_at', '<', now()->subHours($afterHours));
                })
                ->get();

            foreach ($due as $complaint) {
                $reason = $complaint->resolve_by && $complaint->resolve_by->isPast()
                    ? 'Resolution SLA breached'
                    : "Unresolved after {$afterHours}h";
                $service->escalate($complaint, $reason);
                $this->info("Escalated {$complaint->reference}: {$reason}");
            }

            $escalated += $due->count();
        });

        Log::info('crm:escalate-complaints completed', ['escalated' => $escalated]);
        $this->info("Escalated {$escalated} complaint(s).");

        return self::SUCCESS;
    }
}
