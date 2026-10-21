<?php

namespace App\Modules\Agri\Services;

use App\Modules\HR\Hr\Attendance\Services\Workforce;
use App\Modules\HR\Hr\Attendance\Services\HrSources;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\ActivityInput;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmMachine;
use App\Modules\HR\Hr\Attendance\Models\Timesheet as FarmTimesheet;
use App\Modules\HR\Hr\Attendance\Models\Worker as FarmWorker;
use App\Modules\Agri\Models\FarmWorkOrder as WO;
use App\Modules\Agri\Models\FieldActivity;
use App\Modules\Agri\Models\LivestockGroup;
use App\Modules\HR\Hr\Attendance\Models\PieceworkEntry;
use App\Modules\Agri\Models\WorkOrderLog;
use App\Modules\Agri\Models\WorkOrderMachine;
use App\Modules\Agri\Models\WorkOrderMaterial;
use App\Modules\Agri\Models\WorkOrderStatusChange;
use App\Modules\Agri\Models\WorkOrderWorker;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Services\StockLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Farm work orders (Phase 2 §2): planned, approved, worked, verified, costed.
 *
 * The rules:
 *  - Status moves only along the lifecycle, and pausing, rejecting, cancelling and reopening need a
 *    reason; every move is kept.
 *  - The person who created an order does not approve it, and the person who did the work does not
 *    verify it (the owner, who answers to nobody, excepted for approval).
 *  - Approval reserves the planned materials in the farm store; a shortfall is reported, not hidden.
 *  - Machine bookings that overlap another open order, or a machine that is disposed or under
 *    maintenance, are refused or recorded as accepted warnings.
 *  - Verification completes the order exactly once: materials are issued through the stock ledger
 *    (an idempotency key per line), machine hours are logged on the machine, a completed field
 *    activity records the work on the crop or herd pointing at those same stock movements, and the
 *    labour cost is the approved time and piecework on the order. Nothing is counted twice.
 */
class WorkOrders
{
    private const MOVES = [
        WO::DRAFT => [WO::APPROVED, WO::CANCELLED],
        WO::APPROVED => [WO::IN_PROGRESS, WO::CANCELLED, WO::DRAFT],
        WO::IN_PROGRESS => [WO::PAUSED, WO::VERIFICATION, WO::CANCELLED],
        WO::PAUSED => [WO::IN_PROGRESS, WO::CANCELLED],
        WO::VERIFICATION => [WO::COMPLETED, WO::REJECTED],
        WO::REJECTED => [WO::IN_PROGRESS, WO::CANCELLED],
        WO::COMPLETED => [WO::IN_PROGRESS],   // reopened: more work found after verification
        WO::CANCELLED => [WO::DRAFT],
    ];

    public const ISSUE_TYPES = ['incident', 'hazard', 'disease', 'pest', 'equipment_failure', 'other'];

    private const NEEDS_REASON = [WO::PAUSED, WO::REJECTED, WO::CANCELLED];

    public function __construct(
        private readonly FarmRegistry $registry,
        private readonly FarmAccess $access,
        private readonly FarmStores $stores,
        private readonly StockLedger $stock,
        private readonly Machinery $machinery,
        private readonly HrSources $hr,
        private readonly Workforce $workforce,
    ) {}

    public function create(Farm $farm, array $data, User $actor): WO
    {
        $this->registry->assertOperational($farm);
        $this->assertLinks($farm, $data);
        $start = Carbon::parse($data['planned_start']);
        $end = Carbon::parse($data['planned_end']);
        if ($end->lte($start)) {
            throw ValidationException::withMessages(['planned_end' => 'A job must be planned to end after it starts.']);
        }

        return DB::transaction(function () use ($farm, $data, $actor) {
            $year = now()->format('Y');
            $n = WO::withoutGlobalScopes()->where('company_id', $farm->company_id)->where('reference', 'like', "WO-{$year}-%")->count() + 1;
            $wo = WO::create(array_intersect_key($data, array_flip(['category', 'title', 'instructions', 'priority', 'field_id', 'location_id',
                'crop_plan_id', 'livestock_group_id', 'planned_start', 'planned_end', 'estimated_hours', 'expected_output', 'output_unit',
                'budget_amount', 'required_certifications', 'machine_id'])) + [
                    'company_id' => $farm->company_id, 'farm_id' => $farm->id, 'reference' => sprintf('WO-%s-%04d', $year, $n),
                    'status' => WO::DRAFT, 'created_by' => $actor->id,
                ]);
            $this->moved($wo, null, WO::DRAFT, null, $actor);

            return $wo;
        });
    }

    public function update(WO $wo, array $data): WO
    {
        if (! in_array($wo->status, [WO::DRAFT, WO::APPROVED], true)) {
            throw ValidationException::withMessages(['status' => "A {$wo->status} job cannot be re-planned."]);
        }
        $this->assertLinks($wo->farm, $data);
        $wo->update(array_intersect_key($data, array_flip(['title', 'instructions', 'priority', 'field_id', 'location_id', 'crop_plan_id',
            'livestock_group_id', 'planned_start', 'planned_end', 'estimated_hours', 'expected_output', 'output_unit', 'budget_amount', 'required_certifications'])));

        return $wo;
    }

    /** @return list<string> warnings accepted on the record */
    public function assignWorker(WO $wo, FarmWorker $worker, bool $acceptWarnings = false): array
    {
        $this->assertNotClosed($wo);
        if (! $worker->isActive()) {
            throw ValidationException::withMessages(['worker_id' => "{$worker->displayName()} is {$worker->status}."]);
        }
        $rules = $this->workforce->rulesFor($worker);
        $warnings = [];
        foreach ($wo->required_certifications ?? [] as $code) {
            $h = $this->hr->holds($worker->hr_person_id, $code, $wo->planned_end);
            if (! $h['ok']) {
                if ($rules->certification_policy === 'block') {
                    throw ValidationException::withMessages(['worker_id' => "{$worker->displayName()}: {$h['reason']}"]);
                }
                $warnings[] = $h['reason'];
            }
        }
        if ($warnings && ! $acceptWarnings) {
            throw ValidationException::withMessages(['warnings' => "{$worker->displayName()}: ".implode(' ', $warnings).' Confirm to assign anyway.']);
        }
        WorkOrderWorker::withoutGlobalScopes()->updateOrCreate(['work_order_id' => $wo->id, 'worker_id' => $worker->id],
            ['company_id' => $wo->company_id, 'farm_id' => $wo->farm_id, 'warnings' => $warnings ? implode(' ', $warnings) : null]);
        app(AgriNotifier::class)->workOrderAssigned($worker, $wo);

        return $warnings;
    }

    public function addMaterial(WO $wo, FinStockItem $item, array $data): WorkOrderMaterial
    {
        if (! in_array($wo->status, [WO::DRAFT, WO::APPROVED, WO::IN_PROGRESS, WO::PAUSED], true)) {
            throw ValidationException::withMessages(['status' => 'Materials can only be planned before the job is submitted.']);
        }
        if ((float) $data['planned_quantity'] <= 0) {
            throw ValidationException::withMessages(['planned_quantity' => 'Quantity must be more than zero.']);
        }
        $m = WorkOrderMaterial::create(['company_id' => $wo->company_id, 'farm_id' => $wo->farm_id, 'work_order_id' => $wo->id,
            'stock_item_id' => $item->id, 'planned_quantity' => $data['planned_quantity'], 'rate_per_ha' => $data['rate_per_ha'] ?? null,
            'withholding_days' => $data['withholding_days'] ?? null]);
        if ($wo->status !== WO::DRAFT) {
            $this->reserve($wo, $m);
        }

        return $m;
    }

    /**
     * Book a machine for the job (§7.2). A booking is a request until someone who manages the
     * machinery approves it, but it holds the slot from the start: overlapping requests and approved
     * bookings both clash. A machine out of service, disposed of, or whose required inspection will
     * have lapsed by the end of the booking cannot be booked; one under maintenance, due a service,
     * or with its insurance or licence lapsed can be, once the warning is accepted.
     */
    public function bookMachine(WO $wo, FarmMachine $machine, Carbon $from, Carbon $to, ?float $hours, bool $acceptWarnings = false,
        string $status = 'approved', ?User $actor = null, ?int $operatorId = null): WorkOrderMachine
    {
        $this->assertNotClosed($wo);
        if ((int) $machine->farm_id !== (int) $wo->farm_id) {
            throw ValidationException::withMessages(['machine_id' => 'That machine is on another farm.']);
        }
        if ($machine->status === FarmMachine::DISPOSED) {
            throw ValidationException::withMessages(['machine_id' => "{$machine->code} has been disposed of."]);
        }
        if ($machine->status === FarmMachine::OUT_OF_SERVICE) {
            throw ValidationException::withMessages(['machine_id' => "{$machine->code} is out of service".($machine->out_of_service_reason ? ": {$machine->out_of_service_reason}" : '.')]);
        }
        if ($to->lte($from)) {
            throw ValidationException::withMessages(['booked_to' => 'The booking must end after it starts.']);
        }
        if ($machine->inspection_required && (! $machine->inspection_expires_on || $machine->inspection_expires_on->lt($to->copy()->startOfDay()))) {
            throw ValidationException::withMessages(['machine_id' => "{$machine->code}'s required inspection ".($machine->inspection_expires_on ? 'lapses on '.$machine->inspection_expires_on->toDateString() : 'is not on record').'; it cannot be booked until it is inspected.']);
        }
        $warnings = [];
        if ($machine->status === FarmMachine::MAINTENANCE) {
            $warnings[] = "{$machine->code} is under maintenance.";
        }
        if ($machine->serviceDue()) {
            $warnings[] = "{$machine->code} is due for a service.";
        }
        foreach (['insurance_expires_on' => 'insurance', 'licence_expires_on' => 'licence'] as $col => $what) {
            if ($machine->{$col} && $machine->{$col}->lt($to->copy()->startOfDay())) {
                $warnings[] = "{$machine->code}'s {$what} lapses on {$machine->{$col}->toDateString()}.";
            }
        }
        $clash = WorkOrderMachine::withoutGlobalScopes()->where('machine_id', $machine->id)->where('work_order_id', '!=', $wo->id)
            ->whereIn('status', ['requested', 'approved'])->where('booked_from', '<', $to)->where('booked_to', '>', $from)
            ->whereHas('workOrder', fn ($q) => $q->withoutGlobalScopes()->whereIn('status', WO::OPEN))->with(['workOrder' => fn ($q) => $q->withoutGlobalScopes()])->first();
        if ($clash) {
            throw ValidationException::withMessages(['machine_id' => "{$machine->code} is already ".($clash->status === 'requested' ? 'requested' : 'booked')
                ." for {$clash->workOrder->reference} ({$clash->booked_from->format('D j M H:i')}–{$clash->booked_to->format('H:i')})."]);
        }
        if ($warnings && ! $acceptWarnings) {
            throw ValidationException::withMessages(['warnings' => implode(' ', $warnings).' Confirm to book anyway.']);
        }

        return WorkOrderMachine::create(['company_id' => $wo->company_id, 'farm_id' => $wo->farm_id, 'work_order_id' => $wo->id, 'machine_id' => $machine->id,
            'booked_from' => $from, 'booked_to' => $to, 'planned_hours' => $hours, 'warnings' => $warnings ? implode(' ', $warnings) : null,
            'status' => $status, 'requested_by' => $actor?->id, 'operator_id' => $operatorId,
            'approved_by' => $status === 'approved' ? $actor?->id : null, 'approved_at' => $status === 'approved' ? now() : null]);
    }

    /** Approve or turn down a booking request. Approval checks the machine again: it may have stopped since. */
    public function decideBooking(WorkOrderMachine $b, bool $approve, User $actor): WorkOrderMachine
    {
        if ($b->status !== 'requested') {
            throw ValidationException::withMessages(['booking' => "That booking is {$b->status}."]);
        }
        $machine = FarmMachine::withoutGlobalScopes()->findOrFail($b->machine_id);
        if ($approve && $machine->isUnavailable() && $machine->status !== FarmMachine::MAINTENANCE) {
            throw ValidationException::withMessages(['booking' => "{$machine->code} is ".str_replace('_', ' ', $machine->status).'; the request cannot be approved.']);
        }
        if ($approve && $machine->inspection_required && (! $machine->inspection_expires_on || $machine->inspection_expires_on->lt($b->booked_to->copy()->startOfDay()))) {
            throw ValidationException::withMessages(['booking' => "{$machine->code}'s required inspection lapses before the booking ends."]);
        }
        $b->update($approve ? ['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()] : ['status' => 'rejected']);

        return $b;
    }

    /** Orders whose booked machine can no longer be used — what a breakdown affects (§7 acceptance). */
    public function affectedBy(FarmMachine $machine): array
    {
        return WorkOrderMachine::withoutGlobalScopes()->where('machine_id', $machine->id)->where('booked_to', '>', now())->whereIn('status', ['requested', 'approved'])
            ->whereHas('workOrder', fn ($q) => $q->withoutGlobalScopes()->whereIn('status', WO::OPEN))
            ->with(['workOrder' => fn ($q) => $q->withoutGlobalScopes()])->get()
            ->map(fn ($b) => ['id' => $b->workOrder->uuid, 'reference' => $b->workOrder->reference, 'title' => $b->workOrder->title,
                'booked_from' => $b->booked_from->format('Y-m-d H:i'), 'booking' => $b->uuid, 'status' => $b->status])->all();
    }

    /** @return array{warnings: list<string>} */
    public function transition(WO $wo, string $to, ?string $reason, User $actor, array $data = []): array
    {
        if (! in_array($to, self::MOVES[$wo->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => "A {$wo->status} job cannot become ".str_replace('_', ' ', $to).'.']);
        }
        if ((in_array($to, self::NEEDS_REASON, true) || $wo->status === WO::COMPLETED) && blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'Give a reason.']);
        }
        $warnings = [];

        DB::transaction(function () use ($wo, $to, $reason, $actor, $data, &$warnings) {
            $from = $wo->status;
            switch ($to) {
                case WO::APPROVED:
                    $this->registry->assertOperational($wo->farm);
                    if ((int) $wo->created_by === (int) $actor->id && ! $this->access->isUnrestricted($actor)) {
                        throw ValidationException::withMessages(['status' => 'You created this job, so somebody else must approve it.']);
                    }
                    foreach ($wo->materials()->get() as $m) {
                        if ($w = $this->reserve($wo, $m)) {
                            $warnings[] = $w;
                        }
                    }
                    $wo->update(['status' => WO::APPROVED, 'approved_by' => $actor->id, 'approved_at' => now()]);
                    break;
                case WO::IN_PROGRESS:
                    if ($from === WO::COMPLETED) {
                        // Reopened. What was issued and logged stays booked; completing again books
                        // only what is added, so nothing is counted twice.
                        $wo->update(['status' => WO::IN_PROGRESS, 'reopen_count' => $wo->reopen_count + 1, 'completed_at' => null,
                            'verified_by' => null, 'verified_at' => null, 'submitted_by' => null, 'submitted_at' => null]);
                        $this->log($wo, ['kind' => 'reopen', 'note' => $reason], $actor);
                        break;
                    }
                    // §12.2: risky work starts only once its approved safety checklist is passed.
                    if ($from !== WO::PAUSED) {
                        app(FarmSafety::class)->assertMayStart($wo);
                    }
                    $wo->update(['status' => WO::IN_PROGRESS, 'started_at' => $wo->started_at ?? now()]);
                    $this->log($wo, ['kind' => $from === WO::PAUSED ? 'resume' : 'start', 'note' => $reason], $actor);
                    break;
                case WO::PAUSED:
                    $wo->update(['status' => WO::PAUSED]);
                    $this->log($wo, ['kind' => 'pause', 'note' => $reason], $actor);
                    break;
                case WO::VERIFICATION:
                    $wo->update(['status' => WO::VERIFICATION, 'submitted_by' => $actor->id, 'submitted_at' => now(),
                        'actual_output' => $data['actual_output'] ?? $wo->actual_output]);
                    $this->log($wo, ['kind' => 'submit', 'note' => $reason, 'quantity' => $data['actual_output'] ?? null], $actor);
                    break;
                case WO::COMPLETED:
                case WO::REJECTED:
                    $doers = $wo->workers()->with('worker.person')->get()->pluck('worker.person.user_id')->filter()->map(fn ($v) => (int) $v);
                    if ((int) $wo->submitted_by === (int) $actor->id || $doers->contains((int) $actor->id)) {
                        throw ValidationException::withMessages(['status' => 'You worked on this job, so somebody else must verify it.']);
                    }
                    $wo->update(['verified_by' => $actor->id, 'verified_at' => now(), 'verification_note' => $reason,
                        'actual_output' => $data['actual_output'] ?? $wo->actual_output]);
                    if ($to === WO::REJECTED) {
                        $wo->update(['status' => WO::REJECTED]);
                    } else {
                        $this->complete($wo, $actor);
                    }
                    break;
                case WO::CANCELLED:
                    $this->stock->releaseFor('agri_work_order', $wo->id, 'Work order cancelled');
                    $wo->update(['status' => WO::CANCELLED]);
                    break;
                case WO::DRAFT:
                    $this->stock->releaseFor('agri_work_order', $wo->id, 'Work order back to draft');
                    $wo->update(['status' => WO::DRAFT, 'approved_by' => null, 'approved_at' => null]);
                    break;
            }
            $this->moved($wo, $from, $to, $reason, $actor);
        });

        return ['warnings' => $warnings];
    }

    /**
     * Progress from the field: time, quantities, materials and machine hours actually used, problems,
     * requests, a photo. Offline entries carry the phone's id and are recorded once.
     */
    public function log(WO $wo, array $data, User $actor): WorkOrderLog
    {
        if (! empty($data['client_id'])) {
            $seen = WorkOrderLog::withoutGlobalScopes()->where('company_id', $wo->company_id)->where('client_id', $data['client_id'])->first();
            if ($seen) {
                return $seen;
            }
        }
        if (in_array($data['kind'], ['progress', 'material', 'machine', 'issue', 'request'], true) && ! in_array($wo->status, [WO::IN_PROGRESS, WO::PAUSED, WO::REJECTED], true)) {
            throw ValidationException::withMessages(['status' => 'Start the job before recording work on it.']);
        }
        if ($data['kind'] === 'material' && ! empty($data['material_id'])) {
            $m = WorkOrderMaterial::withoutGlobalScopes()->where('work_order_id', $wo->id)->findOrFail($data['material_id']);
            $m->update(['actual_quantity' => (float) ($m->actual_quantity ?? 0) + (float) $data['quantity']]);
            $data['stock_item_id'] = $m->stock_item_id;
        }
        if ($data['kind'] === 'machine' && ! empty($data['machine_id'])) {
            $b = WorkOrderMachine::withoutGlobalScopes()->where('work_order_id', $wo->id)->where('machine_id', $data['machine_id'])->firstOrFail();
            $b->update(['actual_hours' => (float) ($b->actual_hours ?? 0) + (float) $data['machine_hours']]);
        }
        $worker = FarmWorker::withoutGlobalScopes()->where('company_id', $wo->company_id)
            ->whereHas('person', fn ($q) => $q->withoutGlobalScopes()->where('user_id', $actor->id))->first();
        $issue = $data['kind'] === 'issue' ? ($data['issue_type'] ?? 'other') : null;
        if ($issue !== null && ! in_array($issue, self::ISSUE_TYPES, true)) {
            throw ValidationException::withMessages(['issue_type' => 'Unknown kind of problem.']);
        }
        $severity = $issue ? ($data['severity'] ?? (in_array($issue, ['incident', 'hazard', 'equipment_failure'], true) ? 'high' : 'medium')) : null;

        $entry = WorkOrderLog::create(['company_id' => $wo->company_id, 'farm_id' => $wo->farm_id, 'work_order_id' => $wo->id,
            'worker_id' => $data['worker_id'] ?? $worker?->id, 'kind' => $data['kind'], 'minutes' => $data['minutes'] ?? null,
            'quantity' => $data['quantity'] ?? null, 'stock_item_id' => $data['stock_item_id'] ?? null, 'machine_id' => $data['machine_id'] ?? null,
            'machine_hours' => $data['machine_hours'] ?? null, 'note' => $data['note'] ?? null,
            'latitude' => $data['latitude'] ?? null, 'longitude' => $data['longitude'] ?? null,
            'photo_name' => $data['photo_name'] ?? null, 'photo_mime' => $data['photo_mime'] ?? null, 'photo_data' => $data['photo_data'] ?? null,
            'logged_at' => $data['logged_at'] ?? now(), 'client_id' => $data['client_id'] ?? null, 'recorded_by' => $actor->id,
            'issue_type' => $issue, 'severity' => $severity]);

        if ($issue) {
            $this->followUpIssue($wo, $entry, $actor);
        }
        if (in_array($data['kind'], ['issue', 'request'], true)) {
            app(AgriNotifier::class)->fieldReport($wo, $entry, $worker?->displayName() ?? $actor->name);
        }

        return $entry;
    }

    /**
     * A problem reported from the field becomes the record people look for: disease or pest a crop
     * observation on the field; equipment failure takes the machine out of service. Incidents and
     * hazards stay open on the farm's alerts until somebody resolves them.
     */
    private function followUpIssue(WO $wo, WorkOrderLog $entry, User $actor): void
    {
        if (in_array($entry->issue_type, ['disease', 'pest'], true) && ($wo->field_id || $wo->crop_plan_id)) {
            $plan = $wo->crop_plan_id ? CropPlan::withoutGlobalScopes()->find($wo->crop_plan_id) : null;
            $obs = \App\Modules\Agri\Models\CropObservation::create(['company_id' => $wo->company_id, 'farm_id' => $wo->farm_id,
                'field_id' => $wo->field_id ?? $plan?->field_id, 'crop_plan_id' => $plan?->id, 'observed_on' => ($entry->logged_at ?? now())->toDateString(),
                'kind' => $entry->issue_type, 'severity' => $entry->severity === 'high' ? 'high' : 'medium',
                'description' => trim("{$wo->reference}: ".($entry->note ?: 'Reported from the field.')),
                'action_required' => $entry->severity === 'high', 'status' => 'open', 'latitude' => $entry->latitude, 'longitude' => $entry->longitude,
                'recorded_by' => $actor->id]);
            $entry->update(['observation_id' => $obs->id]);
        }
        if ($entry->issue_type === 'equipment_failure' && $entry->machine_id) {
            // §7.3: a fault on the machine, which stops it and flags the jobs booked on it.
            $machine = FarmMachine::withoutGlobalScopes()->find($entry->machine_id);
            if ($machine && $machine->status !== FarmMachine::DISPOSED) {
                app(MachineMaintenance::class)->report($machine, $entry->severity === 'high' ? 'major' : 'minor',
                    "{$wo->reference}: ".($entry->note ?: 'Equipment failure reported from the field.'), true, $actor, 'work_order');
            }
        }
    }

    public function resolveIssue(WorkOrderLog $entry, string $resolution, User $actor): WorkOrderLog
    {
        if ($entry->kind !== 'issue' || $entry->resolved_at) {
            throw ValidationException::withMessages(['issue' => 'That is not an open problem.']);
        }
        $entry->update(['resolved_at' => now(), 'resolved_by' => $actor->id, 'resolution' => mb_strimwidth($resolution, 0, 500)]);

        return $entry;
    }

    /** Close the job: issue, log and cost everything once. */
    private function complete(WO $wo, User $actor): void
    {
        $farm = $wo->farm;
        $plan = $wo->crop_plan_id ? CropPlan::withoutGlobalScopes()->find($wo->crop_plan_id) : null;
        $group = $wo->livestock_group_id ? LivestockGroup::withoutGlobalScopes()->find($wo->livestock_group_id) : null;
        $field = $wo->field_id ? \App\Modules\Agri\Models\FarmField::withoutGlobalScopes()->find($wo->field_id) : $plan?->field;
        $enterprise = $plan ?? $group;
        $logs = $wo->logs()->get();
        $machines = $wo->machines()->with('machine')->get();

        // The work as a field activity on the crop or herd, so its history and withholding periods
        // are where people look for them. It records the inputs issued below; it issues nothing itself.
        $activity = $wo->field_activity_id ? FieldActivity::withoutGlobalScopes()->find($wo->field_activity_id) : null;
        if ($activity) {
            // Completed again after a reopen: the same activity, brought up to date.
            $activity->update(['completed_at' => now(), 'labour_hours' => round($logs->sum('minutes') / 60, 2) ?: null,
                'machine_hours' => $machines->sum(fn ($m) => (float) ($m->actual_hours ?? 0)) ?: null, 'notes' => $wo->verification_note, 'completed_by' => $actor->id]);
        } elseif ($plan || $group || $field) {
            $activity = FieldActivity::create([
                'company_id' => $wo->company_id, 'farm_id' => $wo->farm_id, 'field_id' => $field?->id, 'crop_plan_id' => $plan?->id,
                'livestock_group_id' => $group?->id, 'activity_type' => $this->activityType($wo->category), 'title' => "{$wo->reference}: {$wo->title}",
                'planned_on' => $wo->planned_start->toDateString(), 'completed_at' => now(), 'status' => FieldActivity::COMPLETED,
                'labour_hours' => round($logs->sum('minutes') / 60, 2) ?: null, 'machine_id' => $machines->first()?->machine_id,
                'machine_hours' => $machines->sum(fn ($m) => (float) ($m->actual_hours ?? 0)) ?: null,
                'notes' => $wo->verification_note, 'created_by' => $wo->created_by, 'completed_by' => $actor->id,
            ]);
        }

        $account = $group ? config('agri.accounts.animal_health') : config('agri.accounts.inputs');
        // §7.3: a repair job's parts are the machine's repairs, from the workshop store when there is one.
        $asset = $wo->machine_id ? ['agri_machine' => (string) $wo->machine_id] : [];
        if ($asset) {
            $account = config('finance.repairs_account', '6400');
        }
        foreach ($wo->materials()->get() as $m) {
            $qty = (float) ($m->actual_quantity ?? $m->planned_quantity);
            $item = FinStockItem::withoutGlobalScopes()->findOrFail($m->stock_item_id);
            // Only what has not been issued before: on a reopened job, the extra used since.
            $delta = round($qty - (float) $m->issued_quantity, 4);
            if ($delta < -0.0001) {
                throw ValidationException::withMessages(['materials' => "{$item->name}: ".round((float) $m->issued_quantity, 4)." {$item->uom} was already issued to this job. "
                    .'Return the surplus through the farm store rather than lowering what was used.']);
            }
            if ($delta <= 0) {
                continue;
            }
            try {
                $issued = $this->stores->issue($farm, $item, $delta, [
                    'source_type' => 'agri_work_order', 'source_id' => $wo->id,
                    'idempotency_key' => "agri-wo-{$wo->uuid}-{$m->id}".($wo->reopen_count ? "-r{$wo->reopen_count}" : ''),
                    'reference' => "{$wo->reference} {$wo->title}", 'expense_account' => $account, 'reason_code' => 'AGRI_USE', 'actor' => $actor,
                    'dimensions' => AgriLedger::dimensions($farm, $field, $enterprise, $asset ? 'repairs' : 'inputs') + ['agri_work_order' => (string) $wo->id] + $asset,
                ] + ($asset ? ['warehouse' => $this->storeForJob($wo)] : []));
            } catch (PostingException $e) {
                throw ValidationException::withMessages(['materials' => $e->getMessage()]);
            }
            $movement = $issued['movement'];
            $cost = $issued['value'];
            $m->update(['actual_quantity' => $qty, 'issued_quantity' => $qty, 'cost' => round((float) $m->cost + $cost, 2)]);
            if ($activity) {
                ActivityInput::create(['company_id' => $wo->company_id, 'farm_id' => $wo->farm_id, 'activity_id' => $activity->id, 'stock_item_id' => $item->id,
                    'warehouse_id' => $movement->warehouse_id, 'quantity' => $delta, 'uom' => $item->uom, 'rate_per_ha' => $m->rate_per_ha,
                    'withholding_days' => $m->withholding_days, 'unit_cost' => round($cost / $delta, 4), 'total_cost' => $cost,
                    'stock_movement_id' => $movement->id, 'status' => ActivityInput::ISSUED, 'issued_at' => now(), 'issued_by' => $actor->id]);
            }
        }
        $this->stock->releaseFor('agri_work_order', $wo->id, 'Work order completed');
        $materialCost = (float) $wo->materials()->sum('cost');

        foreach ($machines as $b) {
            $hours = (float) ($b->actual_hours ?? 0);
            $delta = round($hours - (float) $b->logged_hours, 2);   // only hours not logged before
            if ($delta > 0 && $b->machine && $b->machine->status !== FarmMachine::DISPOSED) {
                $this->machinery->logUse($b->machine, ['used_on' => today()->toDateString(), 'hours' => $delta, 'activity_id' => $activity?->id,
                    'notes' => $wo->reference], $actor);
                $b->update(['logged_hours' => $hours, 'cost' => $b->machine->hourly_rate ? round($hours * (float) $b->machine->hourly_rate, 2) : 0]);
            }
        }
        $machineCost = (float) $wo->machines()->sum('cost');

        // Labour: approved time and piecework on this job. Logged minutes are productivity, not cost.
        $labour = (float) FarmTimesheet::withoutGlobalScopes()->where('work_order_id', $wo->id)->where('status', FarmTimesheet::APPROVED)->sum('cost_amount')
            + (float) PieceworkEntry::withoutGlobalScopes()->where('work_order_id', $wo->id)->where('status', 'approved')->sum('amount');

        $wo->update(['status' => WO::COMPLETED, 'completed_at' => now(), 'field_activity_id' => $activity?->id,
            'actual_labour_minutes' => (int) $logs->sum('minutes'), 'actual_labour_cost' => round($labour, 2),
            'actual_material_cost' => round($materialCost, 2), 'actual_machine_cost' => round($machineCost, 2)]);
        if ($wo->machine_id) {
            app(MachineMaintenance::class)->repairDone($wo);
        }
    }

    /** Where a job's materials come from: a repair job's from the farm's workshop store, if it has one; otherwise the main store. */
    private function storeForJob(WO $wo): \App\Modules\Finance\Accounting\Models\FinWarehouse
    {
        $farm = Farm::withoutGlobalScopes()->findOrFail($wo->farm_id);
        if ($wo->machine_id && ($ws = \App\Modules\Agri\Models\FarmStore::withoutGlobalScopes()->where('farm_id', $farm->id)->where('kind', 'workshop')->where('is_active', true)->value('warehouse_id'))) {
            return \App\Modules\Finance\Accounting\Models\FinWarehouse::withoutGlobalScopes()->findOrFail($ws);
        }

        return $this->stores->storeFor($farm);
    }

    /** Reserve a material in the farm store; returns a warning when only part could be reserved. */
    private function reserve(WO $wo, WorkOrderMaterial $m): ?string
    {
        $item = FinStockItem::withoutGlobalScopes()->findOrFail($m->stock_item_id);
        $farm = Farm::withoutGlobalScopes()->findOrFail($wo->farm_id);
        if (! $farm->warehouse_id) {
            return "{$item->name}: the farm has no store yet, so nothing is reserved.";
        }
        $store = $this->storeForJob($wo);
        $free = $this->stock->available($item, $store->id, null, null, null, ['agri_work_order', $wo->id]);
        $qty = min((float) $m->planned_quantity, max(0, $free));
        if ($qty > 0) {
            $r = $this->stock->reserve($item, $store, $qty, 'agri_work_order', $wo->id);
            $m->update(['reservation_id' => $r->id]);
        }
        $short = round((float) $m->planned_quantity - $qty, 4);

        return $short > 0 ? "{$item->name}: {$short} {$item->uom} short in the farm store — raise a requisition." : null;
    }

    private function moved(WO $wo, ?string $from, string $to, ?string $reason, User $actor): void
    {
        WorkOrderStatusChange::create(['company_id' => $wo->company_id, 'farm_id' => $wo->farm_id, 'work_order_id' => $wo->id,
            'from_status' => $from, 'to_status' => $to, 'reason' => $reason, 'changed_by' => $actor->id]);
    }

    private function assertNotClosed(WO $wo): void
    {
        if (in_array($wo->status, [WO::COMPLETED, WO::CANCELLED, WO::VERIFICATION], true)) {
            throw ValidationException::withMessages(['status' => "This job is {$wo->status}."]);
        }
    }

    private function assertLinks(Farm $farm, array $data): void
    {
        foreach (['field_id' => \App\Modules\Agri\Models\FarmField::class, 'crop_plan_id' => CropPlan::class, 'livestock_group_id' => LivestockGroup::class,
            'location_id' => \App\Modules\Agri\Models\FarmLocation::class, 'machine_id' => FarmMachine::class] as $key => $class) {
            if (! empty($data[$key]) && ! $class::withoutGlobalScopes()->whereKey($data[$key])->where('farm_id', $farm->id)->exists()) {
                throw ValidationException::withMessages([$key => 'That is not on this farm.']);
            }
        }
    }

    private function activityType(string $category): string
    {
        return match ($category) {
            'land_preparation' => 'soil_preparation', 'planting' => 'planting', 'fertilising' => 'fertilising', 'spraying' => 'spraying',
            'weeding' => 'weeding', 'pruning' => 'pruning', 'irrigation' => 'irrigation', 'harvesting', 'grading', 'packing' => 'harvesting',
            'feeding' => 'feeding', default => 'other',
        };
    }
}
