<?php

namespace App\Modules\HR\Payroll\Services;

use App\Core\User\Models\User;
use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\HR\Payroll\Models\Bureau\BureauEmployee;
use App\Modules\HR\Payroll\Models\EmploymentMilestone;
use App\Modules\HR\Payroll\Models\EmploymentMilestoneRule;
use App\Modules\HR\Payroll\Models\EtiClaim;
use App\Modules\HR\Payroll\Notifications\EmploymentMilestoneReached;
use App\Modules\HR\Payroll\Support\EmploymentCategory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Raises the employment milestones a payroll administrator has to act on.
 *
 * Both engines, one implementation. An intern is an intern whether their payroll is run in-house or
 * by a bureau, and the incentive expiring at twenty-four months costs the employer exactly the same
 * money either way — so the scan reads bureau_employees and hr_people through the same shape rather
 * than being written once and then half-ported.
 *
 * Safe to run repeatedly, which is the point of writing the notice down. A daily scan with no memory
 * would either resend every reminder every morning — which teaches people to filter payroll mail —
 * or fire only on the exact anniversary and lose it to a weekend, a holiday or an hour of downtime.
 * The row is claimed against a unique index before anything is sent, so the second scan of the day
 * finds it already there and stays quiet.
 */
class EmploymentMilestoneService
{
    /** Holding any of these means you are the person who has to act on a milestone. */
    private const ADMIN_PERMISSIONS = [
        'payroll.runs.calculate',
        'payroll.runs.create',
        'payroll.settings.manage',
        'finance.payroll.manage',
    ];

    public function __construct(private readonly EtiClaimService $claims)
    {
    }

    /**
     * Scan every employee on both engines and raise whatever has come due.
     *
     * @return Collection<int, EmploymentMilestone> the notices raised by *this* pass
     */
    public function scan(?Carbon $asAt = null, ?BureauCompany $onlyClient = null): Collection
    {
        $asAt = $asAt ? $asAt->copy()->startOfDay() : Carbon::now()->startOfDay();
        $raised = collect();

        foreach ($this->bureauClients($onlyClient) as $client) {
            $rules = $this->rulesFor($client);

            if ($rules->isEmpty()) {
                continue;
            }

            foreach ($this->bureauEmployees($client) as $employee) {
                $raised = $raised->merge($this->forEmployee(
                    EmploymentMilestone::BUREAU,
                    (int) $employee->id,
                    [
                        'name'       => trim("{$employee->first_name} {$employee->last_name}"),
                        'number'     => $employee->employee_number,
                        'category'   => $employee->employment_category,
                        'start_date' => $employee->start_date ? Carbon::parse($employee->start_date) : null,
                        'client'     => $client,
                    ],
                    $rules,
                    $asAt,
                ));
            }
        }

        // The in-house payroll has no client company, so it uses the tenant's own default rules.
        if ($onlyClient === null) {
            $rules = $this->rulesFor(null);

            if ($rules->isNotEmpty()) {
                foreach ($this->hrPeople() as $person) {
                    $raised = $raised->merge($this->forEmployee(
                        EmploymentMilestone::HR,
                        (int) $person->id,
                        [
                            'name'       => trim("{$person->first_name} " . ($person->surname ?? '')),
                            'number'     => $person->employee_number,
                            'category'   => $person->employment_category,
                            'start_date' => $this->joinedOn($person),
                            'client'     => null,
                        ],
                        $rules,
                        $asAt,
                    ));
                }
            }
        }

        return $raised;
    }

    /**
     * The milestones one employee has reached but not yet been raised for.
     *
     * Every rule is checked, not just the next one. Somebody imported from another system with two
     * years of service has passed one, three, six, twelve and eighteen months on the day they are
     * loaded, and only twenty-four is still ahead of them — reporting only the newest would leave
     * their incentive position unexamined, which is the one thing these notices exist to surface.
     *
     * @param  array{name:string, number:?string, category:?string, start_date:?Carbon, client:?BureauCompany}  $employee
     * @param  Collection<int, EmploymentMilestoneRule>  $rules
     * @return Collection<int, EmploymentMilestone>
     */
    private function forEmployee(string $type, int $employeeId, array $employee, Collection $rules, Carbon $asAt): Collection
    {
        $start = $employee['start_date'];

        // Without a start date there is no duration to measure. Not an error — a client's import
        // routinely arrives with some of these blank — so it is skipped rather than guessed at.
        if (! $start) {
            return collect();
        }

        $raised = collect();

        foreach ($rules as $rule) {
            if (! $rule->appliesTo($employee['category'])) {
                continue;
            }

            $dueOn = $start->copy()->addMonthsNoOverflow($rule->months);

            // Raised early by however much the rule asks for: a twenty-four month incentive expiry
            // is no use on the day it expires, because the run it affects was prepared before then.
            if ($asAt->lt($dueOn->copy()->subDays($rule->lead_days))) {
                continue;
            }

            if ($milestone = $this->raise($type, $employeeId, $employee, $rule, $start, $dueOn)) {
                $raised->push($milestone);
            }
        }

        return $raised;
    }

    /**
     * Write the notice, or return null because it already exists.
     *
     * The unique index is what actually decides, not the check before it. Two scans overlapping — a
     * cron tick and somebody pressing the button — would otherwise both see nothing and both write.
     */
    private function raise(
        string $type,
        int $employeeId,
        array $employee,
        EmploymentMilestoneRule $rule,
        Carbon $start,
        Carbon $dueOn,
    ): ?EmploymentMilestone {
        $exists = EmploymentMilestone::query()
            ->forEmployee($type, $employeeId)
            ->where('months', $rule->months)
            ->exists();

        if ($exists) {
            return null;
        }

        $tracksEti = EmploymentCategory::tracksEti($employee['category']);
        $claimed = $tracksEti ? $this->claims->claimedMonths($type, $employeeId) : null;

        try {
            return EmploymentMilestone::create([
                'employee_type'        => $type,
                'employee_id'          => $employeeId,
                'bureau_company_id'    => $employee['client']?->id,
                'rule_id'              => $rule->id,
                'months'               => $rule->months,
                'start_date'           => $start->toDateString(),
                'due_on'               => $dueOn->toDateString(),
                'employee_name'        => $employee['name'],
                'employee_number'      => $employee['number'],
                'employment_category'  => $employee['category'],
                'eti_tracked'          => $tracksEti,
                'eti_months_claimed'   => $claimed,
                'eti_months_remaining' => $tracksEti ? max(0, EtiClaim::MAX_MONTHS - $claimed) : null,
                'reason'               => $this->reasonFor($rule, $tracksEti, $claimed),
            ]);
        } catch (QueryException) {
            // Somebody else raised it between the check and the insert. Exactly what was wanted.
            return null;
        }
    }

    /** Why the administrator is being told, in a sentence they can act on. */
    private function reasonFor(EmploymentMilestoneRule $rule, bool $tracksEti, ?int $claimed): string
    {
        $label = $rule->label ?: "{$rule->months}-month milestone";

        if (! $tracksEti) {
            return $label;
        }

        $remaining = max(0, EtiClaim::MAX_MONTHS - (int) $claimed);

        return $remaining === 0
            ? "{$label}. The employment tax incentive is exhausted — all " . EtiClaim::MAX_MONTHS . ' months have been claimed.'
            : "{$label}. Review incentive eligibility before the next run: {$claimed} of "
                . EtiClaim::MAX_MONTHS . " months used, {$remaining} remaining.";
    }

    /**
     * Tell the people who have to act, and stamp the notice so it is never sent twice.
     *
     * Delivery is best-effort per notice. One employee with a broken email address must not stop
     * the other three hundred milestones in the pass from being delivered — and the notice row is
     * already written, so a failure here loses the message, not the record.
     *
     * @param  Collection<int, EmploymentMilestone>  $milestones
     * @return int how many were delivered
     */
    public function notify(Collection $milestones): int
    {
        $sent = 0;

        foreach ($milestones as $milestone) {
            if ($milestone->notified_at) {
                continue;
            }

            $withEmail = (bool) ($milestone->rule?->notify_email ?? false);
            $recipients = $this->recipientsFor($milestone);

            if ($recipients->isEmpty()) {
                continue;
            }

            try {
                Notification::send($recipients, new EmploymentMilestoneReached($milestone, $withEmail));
                $milestone->forceFill(['notified_at' => now()])->save();
                $sent++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $sent;
    }

    /**
     * Who hears about it.
     *
     * A bureau client's notices go to the agent who runs that client's payroll — they are the person
     * who will be preparing the run this affects. Everything else goes to whoever holds the payroll
     * permissions in the tenant. Deliberately permission-based rather than role-based: roles are
     * renamed by customers, permissions are not.
     *
     * @return Collection<int, User>
     */
    private function recipientsFor(EmploymentMilestone $milestone): Collection
    {
        $recipients = collect();

        if ($milestone->bureau_company_id) {
            $agentId = BureauCompany::query()->whereKey($milestone->bureau_company_id)->value('agent_id');

            if ($agentId && $agent = User::find($agentId)) {
                $recipients->push($agent);
            }
        }

        $administrators = User::query()
            ->whereHas('roles.permissions', fn ($q) => $q->whereIn('name', self::ADMIN_PERMISSIONS))
            ->orWhereHas('permissions', fn ($q) => $q->whereIn('name', self::ADMIN_PERMISSIONS))
            ->get();

        return $recipients->merge($administrators)->unique('id')->values();
    }

    /**
     * The rules that apply to a client, or the tenant's own defaults.
     *
     * A client with rules of its own replaces the defaults rather than adding to them — otherwise a
     * client who deliberately removed the one-month probation notice would still get it from the
     * tenant row, and there would be no way to switch anything off.
     *
     * @return Collection<int, EmploymentMilestoneRule>
     */
    public function rulesFor(?BureauCompany $client): Collection
    {
        if ($client) {
            $own = EmploymentMilestoneRule::query()
                ->where('bureau_company_id', $client->id)
                ->where('is_active', true)
                ->orderBy('months')
                ->get();

            if ($own->isNotEmpty()) {
                return $own;
            }
        }

        return EmploymentMilestoneRule::query()
            ->whereNull('bureau_company_id')
            ->where('is_active', true)
            ->orderBy('months')
            ->get();
    }

    /**
     * Give a company the starting set of rules, once.
     *
     * Returns how many were created, so calling it again on a company that already has them is a
     * no-op rather than a duplicate — the unique index would refuse anyway.
     */
    public function installDefaults(?BureauCompany $client = null): int
    {
        $created = 0;

        foreach (EmploymentMilestoneRule::DEFAULTS as $default) {
            $rule = EmploymentMilestoneRule::firstOrCreate(
                ['bureau_company_id' => $client?->id, 'months' => $default['months']],
                $default + ['is_active' => true],
            );

            $created += $rule->wasRecentlyCreated ? 1 : 0;
        }

        return $created;
    }

    /** @return Collection<int, BureauCompany> */
    private function bureauClients(?BureauCompany $only): Collection
    {
        if ($only) {
            return collect([$only]);
        }

        return BureauCompany::query()->where('is_active', true)->get();
    }

    /** @return Collection<int, BureauEmployee> */
    private function bureauEmployees(BureauCompany $client): Collection
    {
        return $client->employees()
            ->where('status', BureauEmployee::STATUS_ACTIVE)
            ->whereNotNull('start_date')
            ->get();
    }

    /**
     * The in-house payroll's people.
     *
     * Read raw rather than through the model, because the columns wanted here are legacy Unix
     * timestamps and the model's accessors would hand back Carbon instances for some rows and
     * integers for others depending on how they were written.
     */
    private function hrPeople(): Collection
    {
        return DB::table('hr_people')
            ->select('id', 'first_name', 'surname', 'employee_number', 'employment_category', 'date_joined', 'date_left')
            ->whereNotNull('date_joined')
            ->where('date_joined', '>', 0)
            // Still employed. `date_left` is a Unix timestamp too, and unset is either null or zero
            // depending on which decade the row was written in.
            ->where(fn ($q) => $q->whereNull('date_left')->orWhere('date_left', 0))
            ->get();
    }

    /** `hr_people.date_joined` is a Unix timestamp in a bigint column, and comes back as a string. */
    private function joinedOn(object $person): ?Carbon
    {
        $joined = $person->date_joined ?? null;

        return blank($joined) ? null : Carbon::createFromTimestamp((int) $joined)->startOfDay();
    }
}
