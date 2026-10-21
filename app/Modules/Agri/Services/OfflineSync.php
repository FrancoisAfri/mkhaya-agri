<?php

namespace App\Modules\Agri\Services;

use App\Modules\HR\Hr\Attendance\Services\Attendance;
use App\Modules\HR\Hr\Attendance\Services\Piecework;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\CropObservation;
use App\Modules\Agri\Models\FarmTask;
use App\Modules\Agri\Models\FieldActivity;
use App\Modules\Agri\Support\RoleCatalog as R;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Applies changes captured offline on "My Work", each exactly once (spec Module K).
 *
 * Every change goes through the same services and permission checks as the screens: being offline
 * never lets anybody do something they could not do online. A change that the rules refuse is
 * recorded as rejected with the reason, so the phone can tell its user what did not go through
 * instead of retrying it for ever.
 */
class OfflineSync
{
    public const OPS = ['task.start', 'task.submit', 'activity.start', 'activity.complete', 'observation.create',
        'clock.in', 'clock.out', 'wo.start', 'wo.pause', 'wo.log', 'wo.submit', 'piecework.submit',
        // Phase 2 §15.4: the field records added since — all checked again on the server when they arrive.
        'safety.report', 'prestart.record', 'storage.reading', 'checklist.complete'];

    /**
     * §15.4: what is never done offline, because it moves money, stock or someone's pay, or needs an
     * approval that must see the current state: these are refused by the phone and done online.
     */
    public const ONLINE_ONLY = ['approvals of any kind', 'fuel and stock issues', 'harvest into store', 'payroll handoff', 'sales, dispatch and delivery',
        'budget and contract changes', 'return of a machine to service'];

    public function __construct(
        private readonly FarmTasks $tasks,
        private readonly FieldWork $work,
        private readonly FarmAccess $access,
        private readonly FarmRegistry $registry,
    ) {}

    /**
     * @param  list<array{client_id: string, op: string, payload: array, captured_at?: string}>  $ops
     * @return list<array{client_id: string, status: string, message: ?string}>
     */
    public function apply(array $ops, User $user): array
    {
        $results = [];
        foreach ($ops as $op) {
            $results[] = $this->one($op, $user);
        }

        return $results;
    }

    private function one(array $op, User $user): array
    {
        $existing = DB::table('agri_offline_ops')->where('company_id', $user->company_id)->where('user_id', $user->id)
            ->where('client_id', $op['client_id'])->first();
        if ($existing) {
            return ['client_id' => $op['client_id'], 'status' => $existing->status, 'message' => $existing->message, 'duplicate' => true];
        }

        try {
            $message = DB::transaction(fn () => $this->run($op['op'], $op['payload'] ?? [], $user, $op['captured_at'] ?? null));
            $status = 'applied';
        } catch (ValidationException $e) {
            $status = 'rejected';
            $message = collect($e->errors())->flatten()->implode(' ');
        } catch (AuthorizationException|\Symfony\Component\HttpKernel\Exception\HttpException|\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            $status = 'rejected';
            $message = $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
                ? 'That record no longer exists or is not on a farm you can reach.'
                : ($e->getMessage() ?: 'You are not allowed to do that.');
        }

        DB::table('agri_offline_ops')->insertOrIgnore([
            'company_id' => $user->company_id, 'user_id' => $user->id, 'client_id' => $op['client_id'], 'op' => $op['op'],
            'payload' => json_encode($op['payload'] ?? []), 'captured_at' => isset($op['captured_at']) ? Carbon::parse($op['captured_at']) : null,
            'status' => $status, 'message' => $message ? mb_strimwidth($message, 0, 500) : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['client_id' => $op['client_id'], 'status' => $status, 'message' => $message, 'duplicate' => false];
    }

    private function run(string $op, array $p, User $user, ?string $capturedAt): ?string
    {
        $when = $capturedAt ? Carbon::parse($capturedAt) : now();
        if ($when->isAfter(now()->addMinutes(10))) {
            $when = now(); // a phone clock running ahead is not allowed to date work in the future
        }

        switch ($op) {
            case 'task.start':
            case 'task.submit':
                $this->need($user, R::VIEW_TASKS);
                $task = FarmTask::query()->where('uuid', $p['id'] ?? '')->firstOrFail();
                $op === 'task.start' ? $this->tasks->start($task, $user)
                    : $this->tasks->submit($task, ['labour_hours' => $p['labour_hours'] ?? null, 'completion_notes' => $p['completion_notes'] ?? null], $user);

                return "Task \"{$task->title}\" ".($op === 'task.start' ? 'started.' : 'submitted.');

            case 'activity.start':
            case 'activity.complete':
                $this->need($user, R::RECORD_ACTIVITIES);
                $activity = FieldActivity::query()->where('uuid', $p['id'] ?? '')->firstOrFail();
                $this->writable($user, $activity->farm_id);
                if ($op === 'activity.start') {
                    $this->work->start($activity);
                } else {
                    $this->work->complete($activity, [
                        'completed_at' => $when->toDateTimeString(), 'area_covered' => $p['area_covered'] ?? null,
                        'labour_hours' => $p['labour_hours'] ?? null, 'machine_hours' => $p['machine_hours'] ?? null,
                        'weather' => $p['weather'] ?? null, 'notes' => $p['notes'] ?? null,
                    ], $user);
                }

                return "Activity \"{$activity->title}\" ".($op === 'activity.start' ? 'started.' : 'completed.');

            case 'observation.create':
                $this->need($user, R::RECORD_ACTIVITIES);
                $farm = \App\Modules\Agri\Models\Farm::query()->where('uuid', $p['farm_id'] ?? '')->firstOrFail();
                $this->writable($user, $farm->id);
                $this->registry->assertOperational($farm);
                $data = validator($p, [
                    'kind' => ['required', 'in:'.implode(',', CropObservation::KINDS)],
                    'severity' => ['required', 'in:'.implode(',', CropObservation::SEVERITIES)],
                    'description' => ['required', 'string', 'max:3000'],
                    'latitude' => ['nullable', 'numeric', 'between:-90,90'],
                    'longitude' => ['nullable', 'numeric', 'between:-180,180'],
                ])->validate();
                $field = ! empty($p['field_id'])
                    ? \App\Modules\Agri\Models\FarmField::query()->where('uuid', $p['field_id'])->where('farm_id', $farm->id)->firstOrFail() : null;
                CropObservation::create([
                    'company_id' => $farm->company_id, 'farm_id' => $farm->id, 'field_id' => $field?->id,
                    'observed_on' => $when->toDateString(), 'kind' => $data['kind'], 'severity' => $data['severity'],
                    'description' => $data['description'], 'latitude' => $data['latitude'] ?? null, 'longitude' => $data['longitude'] ?? null,
                    'action_required' => in_array($data['severity'], ['high', 'critical'], true), 'status' => 'open', 'recorded_by' => $user->id,
                ]);

                return 'Observation recorded.';

            case 'clock.in':
            case 'clock.out':
                // Yourself, from your phone; or somebody else (by badge), from a supervisor's device.
                $worker = $this->workerFor($user, $p);
                $event = app(Attendance::class)->clock($worker, $op === 'clock.in' ? 'in' : 'out', $when, $p['method'] ?? 'mobile', [
                    'client_id' => $p['event_id'] ?? null, 'offline' => (bool) ($p['offline'] ?? true), 'latitude' => $p['latitude'] ?? null,
                    'longitude' => $p['longitude'] ?? null, 'device_ref' => $p['device_ref'] ?? null,
                ], $user);

                return "{$worker->displayName()} clocked ".($op === 'clock.in' ? 'in' : 'out').' at '.$event->occurred_at->format('H:i').'.'
                    .($event->verification !== 'verified' ? ' Flagged: '.$event->verification_note : '');

            case 'wo.start':
            case 'wo.pause':
            case 'wo.submit':
                $wo = \App\Modules\Agri\Models\FarmWorkOrder::query()->where('uuid', $p['id'] ?? '')->firstOrFail();
                $this->onJob($user, $wo);
                $to = ['wo.start' => \App\Modules\Agri\Models\FarmWorkOrder::IN_PROGRESS, 'wo.pause' => \App\Modules\Agri\Models\FarmWorkOrder::PAUSED,
                    'wo.submit' => \App\Modules\Agri\Models\FarmWorkOrder::VERIFICATION][$op];
                app(WorkOrders::class)->transition($wo, $to, $p['note'] ?? ($op === 'wo.pause' ? 'Paused in the field' : null), $user,
                    ['actual_output' => $p['actual_output'] ?? null]);

                return "Job {$wo->reference} ".str_replace('_', ' ', $to).'.';

            case 'wo.log':
                $wo = \App\Modules\Agri\Models\FarmWorkOrder::query()->where('uuid', $p['id'] ?? '')->firstOrFail();
                $this->onJob($user, $wo);
                if (! empty($p['photo_data'])) {
                    // Files from a phone: pictures and everyday documents only, up to 8 MB.
                    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'application/pdf'];
                    if (! in_array($p['photo_mime'] ?? '', $allowed, true) || strlen((string) $p['photo_data']) > 11_200_000 || base64_decode((string) $p['photo_data'], true) === false) {
                        throw ValidationException::withMessages(['photo' => 'Only a picture or a PDF of up to 8 MB can be attached.']);
                    }
                }
                app(WorkOrders::class)->log($wo, array_intersect_key($p, array_flip(['kind', 'minutes', 'quantity', 'note', 'material_id', 'machine_id',
                    'machine_hours', 'latitude', 'longitude', 'photo_name', 'photo_mime', 'photo_data', 'issue_type', 'severity'])) + ['kind' => $p['kind'] ?? 'progress',
                    'logged_at' => $when, 'client_id' => $p['log_id'] ?? null], $user);

                return "Recorded on job {$wo->reference}.";

            case 'piecework.submit':
                $farm = \App\Modules\Agri\Models\Farm::query()->where('uuid', $p['farm_id'] ?? '')->firstOrFail();
                $this->writable($user, $farm->id);
                $worker = $this->workerFor($user, $p);
                app(Piecework::class)->submit($farm, ['work_date' => $when->toDateString(), 'activity' => $p['activity'] ?? 'harvesting',
                    'unit' => $p['unit'] ?? 'kg', 'measured_quantity' => (float) ($p['quantity'] ?? 0), 'worker_id' => $worker->id,
                    'client_id' => $p['entry_id'] ?? null], $user);

                return "{$p['quantity']} {$p['unit']} recorded for {$worker->displayName()}, waiting for approval.";

            case 'safety.report':
                // Anyone who can open the farm may report a danger; it goes into SHEQ with the phone's id, so a resend files nothing twice.
                $farm = \App\Modules\Agri\Models\Farm::query()->where('uuid', $p['farm_id'] ?? '')->firstOrFail();
                $r = app(FarmSafety::class)->report($farm, ['kind' => $p['kind'] ?? 'hazard', 'description' => (string) ($p['description'] ?? ''), 'occurred_at' => $when->toIso8601String(),
                    'location' => $p['location'] ?? null, 'latitude' => $p['latitude'] ?? null, 'longitude' => $p['longitude'] ?? null, 'severity' => $p['severity'] ?? null,
                    'immediate_action' => $p['immediate_action'] ?? null, 'machine_id' => $p['machine_id'] ?? null, 'client_id' => $p['report_id'] ?? null], $user);

                return "Reported as {$r['reference']}.";

            case 'prestart.record':
                $this->need($user, R::RECORD_ACTIVITIES);
                $machine = \App\Modules\Agri\Models\FarmMachine::query()->where('uuid', $p['machine_id'] ?? '')->firstOrFail();
                $this->writable($user, $machine->farm_id);
                $check = app(MachineMaintenance::class)->prestart($machine, $p['items'] ?? [], ['checked_on' => $when->toDateString(), 'hours_reading' => $p['hours_reading'] ?? null,
                    'odometer_km' => $p['odometer_km'] ?? null], $user);

                return $check->passed ? "{$machine->code} passed." : "{$machine->code} failed a critical check and is out of service.";

            case 'storage.reading':
                $this->need($user, R::RECORD_HARVEST);
                $store = \App\Modules\Agri\Models\FarmStore::query()->where('uuid', $p['store_id'] ?? '')->firstOrFail();
                $this->writable($user, $store->farm_id);
                $r = app(PostHarvest::class)->reading($store, ['temperature' => $p['temperature'] ?? null, 'humidity' => $p['humidity'] ?? null, 'recorded_at' => $when,
                    'client_id' => $p['reading_id'] ?? null], $user);

                return $r->out_of_range ? "Out of range: {$r->deviation}." : 'Reading recorded.';

            case 'checklist.complete':
                $this->need($user, R::RECORD_ACTIVITIES);
                $wo = \App\Modules\Agri\Models\FarmWorkOrder::query()->where('uuid', $p['work_order_id'] ?? '')->firstOrFail();
                $this->onJob($user, $wo);
                // The gating SHEQ inspection, done on the phone and replayed once.
                $i = app(FarmSafety::class)->inspect($wo, (string) ($p['template_code'] ?? ''), (array) ($p['responses'] ?? []), $user, $p['client_id'] ?? null);

                return $i['passed'] === false ? "{$i['reference']} did not pass; the job may not start." : "{$i['reference']} recorded; {$wo->reference} may start once every check passes.";
        }

        throw ValidationException::withMessages(['op' => "Unknown change type {$op}."]);
    }

    /** The person a clock or piecework entry is for: yourself, or — with the right — a worker by badge. */
    private function workerFor(User $user, array $p): \App\Modules\HR\Hr\Attendance\Models\Worker
    {
        if (! empty($p['badge'])) {
            $this->need($user, R::RECORD_ATTENDANCE);
            $worker = \App\Modules\HR\Hr\Attendance\Models\Worker::query()->where('badge_token', $p['badge'])->first();
            if (! $worker) {
                throw ValidationException::withMessages(['badge' => 'That badge is not a worker on a farm you can reach.']);
            }

            return $worker;
        }
        $worker = \App\Modules\HR\Hr\Attendance\Models\Worker::query()->whereHas('person', fn ($q) => $q->withoutGlobalScopes()->where('user_id', $user->id))->first();
        if (! $worker) {
            throw ValidationException::withMessages(['worker' => 'Your login is not linked to a farm worker record.']);
        }

        return $worker;
    }

    /** Working a job needs the right to, or being one of its crew. */
    private function onJob(User $user, \App\Modules\Agri\Models\FarmWorkOrder $wo): void
    {
        if ($user->can(R::MANAGE_WORK_ORDERS)) {
            $this->writable($user, $wo->farm_id);

            return;
        }
        $mine = $wo->workers()->whereHas('worker.person', fn ($q) => $q->withoutGlobalScopes()->where('user_id', $user->id))->exists();
        if (! $mine) {
            throw new AuthorizationException('You are not on this job.');
        }
    }

    private function need(User $user, string $permission): void
    {
        if (! $user->can($permission)) {
            throw new AuthorizationException('You do not have permission to do that.');
        }
    }

    private function writable(User $user, int $farmId): void
    {
        if (! $this->access->canWrite($user, $farmId)) {
            throw new AuthorizationException('Your access to this farm is read-only.');
        }
    }
}
