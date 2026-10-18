<?php

namespace App\Console\Commands\Attendance;

use App\Core\User\Models\User;
use App\Modules\HR\Hr\Models\Attendance\AttendanceAnomaly;
use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Hr\Notifications\AttendanceAnomaliesDigestNotification;
use App\Modules\HR\Leave\Models\LeaveConfiguration;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Weekly HR alert for open attendance anomalies. Re-alerts each run while anomalies remain
 * open. Runs per tenant (SaaS) and once (self_hosted).
 */
class AttendanceAnomalyDigest extends Command
{
    use RunsPerTenant;

    protected $signature = 'attendance:anomaly-digest
                            {--company= : Alert only this company}
                            {--sync : Process companies inline instead of fanning out to the queue}';

    protected $description = 'Alert HR about open attendance anomalies (repeats until resolved).';

    public function handle(TenantContext $tenant): int
    {
        if ($cid = $this->option('company')) {
            $tenant->run((int) $cid, fn () => $this->alertCurrentCompany());
            return self::SUCCESS;
        }

        if ($this->option('sync')) {
            $this->eachTenant($tenant, fn () => $this->alertCurrentCompany());
            $this->info('Attendance anomaly digest complete (sync).');
            return self::SUCCESS;
        }

        $n = $tenant->dispatchEachCompany('attendance:anomaly-digest', []);
        $this->info("Dispatched attendance anomaly digest to {$n} company(ies).");
        return self::SUCCESS;
    }

    private function alertCurrentCompany(): void
    {
        $open = AttendanceAnomaly::where('status', 'open')->get();
        if ($open->isEmpty()) {
            return;
        }

        $bySeverity = $open->groupBy('severity')->map->count()->toArray();
        $samples = $open->take(8)->map(function (AttendanceAnomaly $a) {
            $emp = HRPerson::find($a->employee_id);
            $name = $emp ? trim("{$emp->first_name} {$emp->surname}") : "Employee #{$a->employee_id}";
            $label = ucwords(str_replace('_', ' ', (string) $a->anomaly_type));
            return "{$name} — {$label}";
        })->all();

        foreach ($this->hrRecipients() as $user) {
            try {
                $user->notify(new AttendanceAnomaliesDigestNotification($open->count(), $bySeverity, $samples));
            } catch (\Throwable) {
                // never let mail failure block the loop
            }
        }

        $this->info("  Alerted about {$open->count()} open attendance anomaly(ies).");
    }

    /** HR recipients: HR-manager pivots ∪ designated HR person ∪ HR role. */
    private function hrRecipients(): \Illuminate\Support\Collection
    {
        $ids = DB::table('company_user_pivots')->where('type', 1)->pluck('user_id');

        $hrPersonId = LeaveConfiguration::first()?->hr_person_id;
        if ($hrPersonId) {
            $uid = HRPerson::where('id', $hrPersonId)->value('user_id');
            if ($uid) {
                $ids->push($uid);
            }
        }

        $users = User::whereIn('id', $ids->unique()->filter()->all())->get();

        try {
            $users = $users->concat(User::role('HR')->get());
        } catch (\Throwable) {
            // role table may not resolve in some contexts
        }

        return $users->unique('id')->values();
    }
}
