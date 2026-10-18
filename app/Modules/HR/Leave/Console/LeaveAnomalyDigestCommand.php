<?php

namespace App\Modules\HR\Leave\Console;

use App\Core\User\Models\User;
use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Leave\Models\LeaveAnomalyFlag;
use App\Modules\HR\Leave\Models\LeaveConfiguration;
use App\Modules\HR\Leave\Notifications\LeaveAnomaliesDigestNotification;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Weekly HR alert for open leave anomalies. Repeats every run while anomalies remain
 * open (incrementing each flag's alert_count) so HR keeps being reminded until they are
 * resolved or dismissed. Runs per tenant (SaaS) and once (self_hosted).
 */
class LeaveAnomalyDigestCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'leave:anomaly-digest
                            {--company= : Alert only this company}
                            {--sync : Process companies inline instead of fanning out to the queue}';

    protected $description = 'Alert HR about open leave anomalies (repeats until resolved).';

    public function handle(TenantContext $tenant): int
    {
        if ($cid = $this->option('company')) {
            $tenant->run((int) $cid, fn () => $this->alertCurrentCompany());
            return self::SUCCESS;
        }

        if ($this->option('sync')) {
            $this->eachTenant($tenant, fn () => $this->alertCurrentCompany());
            $this->info('Leave anomaly digest complete (sync).');
            return self::SUCCESS;
        }

        $n = $tenant->dispatchEachCompany('leave:anomaly-digest', []);
        $this->info("Dispatched leave anomaly digest to {$n} company(ies).");
        return self::SUCCESS;
    }

    private function alertCurrentCompany(): void
    {
        $open = LeaveAnomalyFlag::where('status', 'open')->get();
        if ($open->isEmpty()) {
            return;
        }

        $bySeverity = $open->groupBy('severity')->map->count()->toArray();
        $samples = $open->take(8)->map(function (LeaveAnomalyFlag $f) {
            $emp = HRPerson::find($f->employee_id);
            $name = $emp ? trim("{$emp->first_name} {$emp->surname}") : "Employee #{$f->employee_id}";
            return "{$name} — {$f->anomaly_type->label()}";
        })->all();

        $recipients = $this->hrRecipients();
        foreach ($recipients as $user) {
            try {
                $user->notify(new LeaveAnomaliesDigestNotification($open->count(), $bySeverity, $samples));
            } catch (\Throwable $e) {
                // Never let a mail failure block the DB alert / the loop.
            }
        }

        // Persistent-alert bookkeeping: bump each open flag so repeat reminders are tracked.
        LeaveAnomalyFlag::where('status', 'open')->update([
            'alert_count'     => DB::raw('alert_count + 1'),
            'last_alerted_at' => now(),
        ]);

        $this->info('  Alerted ' . $recipients->count() . " recipient(s) about {$open->count()} open anomaly(ies).");
    }

    /** HR recipients for the current company: HR-manager pivots ∪ designated HR person ∪ HR role. */
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

        // Also anyone holding the HR role (Spatie), in case pivots are unset.
        try {
            $byRole = User::role('HR')->get();
            $users = $users->concat($byRole);
        } catch (\Throwable) {
            // role table may not resolve in some contexts — pivots are the primary source.
        }

        return $users->unique('id')->values();
    }
}
