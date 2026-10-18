<?php

namespace App\Modules\Operations\ProjectManagement\Console;

use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Notifications\ProjectAlertNotification;
use App\Modules\Operations\ProjectManagement\Services\CostService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Core\User\Models\User;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Budget alerts and deadline reminders (§27).
 *
 * ── Why one command and not two ─────────────────────────────────────────────
 *
 * They run on the same cadence, address the same people, and a project manager would rather have
 * one message about their project than two arriving a second apart. So the command gathers
 * everything about a project and sends at most one notification per person per run.
 *
 * ── Why it fans out per tenant ──────────────────────────────────────────────
 *
 * `CompanyScope` is inactive in the console, and the RLS policy treats an unset session variable as
 * "allow every row". A command that iterated projects without entering each tenant would read the
 * whole platform and email one company's figures to another's project manager. `TenantContext::run`
 * is what makes each pass see exactly one tenant.
 */
class SendProjectAlertsCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'pm:alerts
        {--days=7 : How far ahead a deadline counts as approaching}
        {--threshold=85 : Percentage of budget consumed before it is worth saying}
        {--dry : Report what would be sent without sending it}';

    protected $description = 'Warn project managers about approaching deadlines and budgets running out.';

    public function handle(TenantContext $tenants, CostService $costs): int
    {
        $days = max(1, (int) $this->option('days'));
        $threshold = max(1, (int) $this->option('threshold'));
        $dry = (bool) $this->option('dry');
        $sent = 0;

        // `CompanyScope` is inactive in the console and an unset RLS session variable matches
        // every row, so each pass has to enter one tenant explicitly. `eachCompany` is the shared
        // helper that does it — and the one definition of which tenants a cron should touch.
        $this->eachTenant($tenants, function () use ($days, $threshold, $dry, $costs, &$sent) {
                foreach (Project::query()->where('status', 'active')->get() as $project) {
                    $alerts = [];

                    // ── Deadlines ────────────────────────────────────────────
                    $due = Task::where('project_id', $project->id)
                        ->whereNotIn('status', ['done', 'cancelled'])
                        ->whereNotNull('planned_end')
                        ->whereBetween('planned_end', [now()->startOfDay(), now()->addDays($days)->endOfDay()])
                        ->orderBy('planned_end')
                        ->get(['name', 'planned_end']);

                    $overdue = Task::where('project_id', $project->id)
                        ->whereNotIn('status', ['done', 'cancelled'])
                        ->whereDate('planned_end', '<', now())
                        ->count();

                    if ($due->isNotEmpty()) {
                        $alerts[] = [
                            'kind'   => 'deadline',
                            'title'  => __('project_management.alerts.deadline_title', ['count' => $due->count(), 'days' => $days]),
                            'detail' => $due->take(5)->map(fn ($t) => $t->name . ' — ' . $t->planned_end->toDateString())->implode('; '),
                        ];
                    }

                    if ($overdue > 0) {
                        $alerts[] = [
                            'kind'   => 'overdue',
                            'title'  => __('project_management.alerts.overdue_title', ['count' => $overdue]),
                            'detail' => __('project_management.alerts.overdue_detail'),
                        ];
                    }

                    // ── Budget ───────────────────────────────────────────────
                    $position = $costs->forProject($project);
                    $budget = (float) $position['budget'];

                    if ($budget > 0) {
                        $consumed = ((float) $position['actual'] + (float) $position['committed']) / $budget * 100;

                        if ($consumed >= $threshold) {
                            $alerts[] = [
                                'kind'   => $consumed >= 100 ? 'over_budget' : 'budget',
                                'title'  => $consumed >= 100
                                    ? __('project_management.alerts.over_budget_title')
                                    : __('project_management.alerts.budget_title', ['pct' => round($consumed)]),
                                // Committed is included on purpose: a budget that looks intact
                                // until the invoices land is one nobody can act on in time.
                                'detail' => __('project_management.alerts.budget_detail', [
                                    'actual'    => number_format((float) $position['actual'], 2),
                                    'committed' => number_format((float) $position['committed'], 2),
                                    'budget'    => number_format($budget, 2),
                                    'currency'  => (string) $position['currency'],
                                ]),
                            ];
                        }
                    }

                    if ($alerts === []) {
                        continue;
                    }

                    $this->line("  {$project->code}: " . count($alerts) . ' alert(s)');

                    if ($dry) {
                        continue;
                    }

                    // The people who can actually do something: the manager and anybody on the
                    // project holding a delivery role. A client does not need to be told the
                    // budget is tight.
                    $recipients = User::whereIn('id', ProjectMember::where('project_id', $project->id)
                        ->active()->whereNotNull('user_id')
                        ->whereNotIn('role_key', ['client', 'contractor'])
                        ->pluck('user_id'))->get();

                    if ($recipients->isNotEmpty()) {
                        Notification::send($recipients, new ProjectAlertNotification($project, $alerts));
                        $sent += $recipients->count();
                    }
                }
        });

        $this->info($dry ? 'Dry run complete.' : "Sent {$sent} notification(s).");

        return self::SUCCESS;
    }
}
