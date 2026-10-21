<?php

namespace App\Modules\Operations\Manufacturing\Services\Agriculture;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\FarmTask;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Device;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Recommendation;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What the agents propose, and what people decide about it.
 *
 * A recommendation is evidence and a suggested action. Approving it turns a task proposal into a
 * farm task in the farm's own task list, and a command proposal into a command request that then
 * waits for its own approval like any other; rejecting it records why. Nothing an agent proposes
 * happens without a person.
 */
class Recommendations
{
    public function __construct(private readonly Commands $commands) {}

    public function propose(int $companyId, string $agent, string $key, array $attrs): Recommendation
    {
        $open = Recommendation::query()->where('company_id', $companyId)->where('dedupe_key', $key)->whereIn('status', ['proposed', 'approved'])->first();
        if ($open) {
            $open->update(collect($attrs)->only(['rationale', 'evidence', 'confidence', 'priority'])->all());

            return $open;
        }

        return Recommendation::create(['company_id' => $companyId, 'agent' => $agent, 'dedupe_key' => $key, 'status' => 'proposed'] + $attrs);
    }

    public function approve(Recommendation $r, User $actor, ?string $note = null): Recommendation
    {
        if ($r->status !== 'proposed') {
            throw ValidationException::withMessages(['status' => "This recommendation is {$r->status}."]);
        }
        if (! $actor->can(RoleCatalog::DECIDE_RECOMMENDATIONS)) {
            throw ValidationException::withMessages(['status' => "{$actor->name} may not decide on recommendations."]);
        }

        return DB::transaction(function () use ($r, $actor, $note) {
            $r->update(['status' => 'approved', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note]);
            $a = $r->action ?? [];
            if ($r->action_kind === 'task' && $r->farm_id) {
                $task = FarmTask::withoutGlobalScopes()->create(['company_id' => $r->company_id, 'farm_id' => $r->farm_id,
                    'title' => mb_strimwidth($a['title'] ?? $r->title, 0, 200), 'description' => $r->rationale."\n\nFrom the {$r->agent} agent's recommendation, approved by {$actor->name}.",
                    'priority' => in_array($a['priority'] ?? $r->priority, FarmTask::PRIORITIES, true) ? ($a['priority'] ?? $r->priority) : 'normal',
                    'due_on' => today()->addDays((int) ($a['due_in_days'] ?? 1)), 'assigned_to' => $a['assign_to'] ?? null, 'status' => FarmTask::OPEN,
                    'requires_approval' => false, 'created_by' => $actor->id]);
                $r->update(['task_id' => $task->id]);
            } elseif ($r->action_kind === 'command' && ! empty($a['device_id'])) {
                $device = Device::query()->findOrFail($a['device_id']);
                $command = $this->commands->request($device, $a['command'], (array) ($a['parameters'] ?? []), $actor, 'Recommended: '.$r->title, $r->fresh(), "rec-{$r->uuid}");
                $r->update(['command_id' => $command->id]);
            }

            return $r->fresh();
        });
    }

    public function reject(Recommendation $r, User $actor, string $why): Recommendation
    {
        if ($r->status !== 'proposed') {
            throw ValidationException::withMessages(['status' => "This recommendation is {$r->status}."]);
        }
        if (trim($why) === '') {
            throw ValidationException::withMessages(['decision_note' => 'Say why, so the agent’s next suggestion can be judged against it.']);
        }
        $r->update(['status' => 'rejected', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $why]);

        return $r;
    }
}
