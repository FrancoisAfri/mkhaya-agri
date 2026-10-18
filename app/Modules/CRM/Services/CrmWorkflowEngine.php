<?php

namespace App\Modules\CRM\Services;

use App\Core\User\Models\User;
use App\Modules\CRM\Models\CrmAccount;
use App\Modules\CRM\Models\CrmActivity;
use App\Modules\CRM\Models\CrmContact;
use App\Modules\CRM\Models\CrmDeal;
use App\Modules\CRM\Models\CrmEmailTemplate;
use App\Modules\CRM\Models\CrmFlowRun;
use App\Modules\CRM\Models\CrmLead;
use App\Modules\CRM\Models\CrmPipeline;
use App\Modules\CRM\Models\CrmWorkflow;
use App\Modules\CRM\Models\CrmWorkflowRun;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * CRM automation engine (spec section 3).
 *
 * dispatch($trigger, $entity) finds active workflows for the trigger, evaluates
 * their conditions against the entity, and runs their actions — recording each
 * execution in crm_workflow_runs. Trigger-based (stage change, new lead, …) is
 * driven from the controllers; time-based triggers (inactivity, SLA) are driven
 * by the crm:run-automations command.
 */
class CrmWorkflowEngine
{
    public function __construct(
        private CrmMailService $mail,
        private CrmWhatsAppService $whatsapp,
        private CrmNotifier $notifier,
        private CrmAiService $ai,
    ) {
    }

    /**
     * @return array<int, array{workflow:string, status:string}>  what ran
     */
    public function dispatch(string $trigger, Model $entity, array $context = []): array
    {
        $entityType = $this->entityType($entity);

        $workflows = CrmWorkflow::where('trigger', $trigger)
            ->where('is_active', true)
            ->orderBy('run_order')
            ->get();

        $results = [];

        foreach ($workflows as $workflow) {
            // Trigger-config gate (e.g. only when moved INTO a specific stage).
            if (! $this->triggerConfigMatches($workflow, $entity, $context)) {
                continue;
            }

            if (! $this->conditionsMatch($workflow, $entity)) {
                $this->record($workflow, $entityType, $entity->getKey(), 'skipped', ['reason' => 'conditions not met']);
                $results[] = ['workflow' => $workflow->name, 'status' => 'skipped'];
                continue;
            }

            try {
                if (! empty($workflow->graph['nodes'] ?? null)) {
                    // Visual canvas flow (graph of nodes/edges).
                    $this->startFlowGraph($workflow, $entity);
                    $results[] = ['workflow' => $workflow->name, 'status' => 'flow_started'];
                } elseif (! empty($workflow->steps)) {
                    // Multi-step flow: enter it (runs immediate steps, pauses at waits).
                    $this->startFlow($workflow, $entity);
                    $results[] = ['workflow' => $workflow->name, 'status' => 'flow_started'];
                } else {
                    // Legacy flat workflow: run all actions immediately.
                    $executed = $this->runActions($workflow, $entity, $entityType);
                    $this->record($workflow, $entityType, $entity->getKey(), 'success', ['actions' => $executed]);
                    $results[] = ['workflow' => $workflow->name, 'status' => 'success'];
                }
                $workflow->forceFill([
                    'last_run_at' => now(),
                    'run_count'   => $workflow->run_count + 1,
                ])->saveQuietly();
            } catch (\Throwable $e) {
                Log::warning('CRM workflow failed', ['workflow' => $workflow->id, 'error' => $e->getMessage()]);
                $this->record($workflow, $entityType, $entity->getKey(), 'failed', ['error' => $e->getMessage()]);
                $results[] = ['workflow' => $workflow->name, 'status' => 'failed'];
            }
        }

        return $results;
    }

    /**
     * Evaluate a workflow's conditions against an entity without side effects —
     * used by the "test" button in the UI.
     */
    public function evaluate(CrmWorkflow $workflow, Model $entity): bool
    {
        return $this->conditionsMatch($workflow, $entity);
    }

    // ── Trigger config ─────────────────────────────────────────────────────────

    private function triggerConfigMatches(CrmWorkflow $workflow, Model $entity, array $context): bool
    {
        $cfg = $workflow->trigger_config ?? [];

        // deal.stage_changed can be scoped to a destination stage.
        if ($workflow->trigger === 'deal.stage_changed' && ! empty($cfg['stage_id'])) {
            return (int) $entity->getAttribute('stage_id') === (int) $cfg['stage_id'];
        }

        // *.updated can be scoped to "only when this field changed".
        if (str_ends_with($workflow->trigger, '.updated') && ! empty($cfg['field'])) {
            return in_array($cfg['field'], $context['changed'] ?? [], true);
        }

        // date_reached fires only on the exact target day (field date ± offset).
        if ($workflow->trigger === 'date_reached') {
            return $this->dateMatches($workflow, $entity);
        }

        return true;
    }

    /**
     * True when the entity's configured date field equals today + days_before
     * (positive days_before = that many days *before* the date; negative = after).
     */
    public function dateMatches(CrmWorkflow $workflow, Model $entity): bool
    {
        $cfg   = $workflow->trigger_config ?? [];
        $field = $cfg['field'] ?? null;
        if (! $field) return false;

        $date = $entity->getAttribute($field);
        if (! $date) return false;

        $target = now()->startOfDay()->addDays((int) ($cfg['days_before'] ?? 0));

        return \Illuminate\Support\Carbon::parse($date)->isSameDay($target);
    }

    /**
     * Run a single workflow against an entity (used by the time-based command for
     * date_reached, after it has queried the matching records).
     */
    public function runForWorkflow(CrmWorkflow $workflow, Model $entity): void
    {
        $entityType = $this->entityType($entity);

        if (! $this->conditionsMatch($workflow, $entity)) {
            $this->record($workflow, $entityType, $entity->getKey(), 'skipped', ['reason' => 'conditions not met']);
            return;
        }

        try {
            $executed = $this->runActions($workflow, $entity, $entityType);
            $this->record($workflow, $entityType, $entity->getKey(), 'success', ['actions' => $executed]);
            $workflow->forceFill(['last_run_at' => now(), 'run_count' => $workflow->run_count + 1])->saveQuietly();
        } catch (\Throwable $e) {
            Log::warning('CRM workflow failed', ['workflow' => $workflow->id, 'error' => $e->getMessage()]);
            $this->record($workflow, $entityType, $entity->getKey(), 'failed', ['error' => $e->getMessage()]);
        }
    }

    // ── Conditions ─────────────────────────────────────────────────────────────

    private function conditionsMatch(CrmWorkflow $workflow, Model $entity): bool
    {
        $conditions = $workflow->conditions ?? [];
        if (empty($conditions)) {
            return true;
        }

        $matchAll = ($workflow->match_type ?? 'all') === 'all';

        foreach ($conditions as $c) {
            $ok = $this->evaluateCondition($entity, $c['field'] ?? '', $c['operator'] ?? '=', $c['value'] ?? null);

            if ($matchAll && ! $ok) return false;
            if (! $matchAll && $ok) return true;
        }

        return $matchAll; // all passed (AND) / none passed (OR)
    }

    private function evaluateCondition(Model $entity, string $field, string $operator, $value): bool
    {
        if ($field === '') return false;

        $actual = $this->resolveValue($entity, $field);

        return match ($operator) {
            '=', '=='   => $this->loose($actual) == $this->loose($value),
            '!='        => $this->loose($actual) != $this->loose($value),
            '>'         => is_numeric($actual) && is_numeric($value) && $actual > $value,
            '>='        => is_numeric($actual) && is_numeric($value) && $actual >= $value,
            '<'         => is_numeric($actual) && is_numeric($value) && $actual < $value,
            '<='        => is_numeric($actual) && is_numeric($value) && $actual <= $value,
            'contains'  => $actual !== null && str_contains(Str::lower((string) $actual), Str::lower((string) $value)),
            'is_empty'  => $actual === null || $actual === '',
            'not_empty' => $actual !== null && $actual !== '',
            'in'        => in_array($actual, is_array($value) ? $value : explode(',', (string) $value)),
            default     => false,
        };
    }

    private function loose($v)
    {
        if (is_bool($v)) return $v ? '1' : '0';
        return $v;
    }

    // ── Actions ────────────────────────────────────────────────────────────────

    private function runActions(CrmWorkflow $workflow, Model $entity, string $entityType): array
    {
        $executed = [];
        foreach ($workflow->actions ?? [] as $action) {
            if ($label = $this->runSingleAction($action, $entity, $entityType)) {
                $executed[] = $label;
            }
        }

        return $executed;
    }

    /** Execute a single action. Returns a short label, or null if it didn't run. */
    private function runSingleAction(array $action, Model $entity, string $entityType): ?string
    {
        switch ($action['type'] ?? '') {
            case 'create_task':
                $this->createTask($entity, $entityType, $action);
                return 'create_task: ' . ($action['subject'] ?? 'Task');

            case 'add_note':
                $this->addNote($entity, $entityType, $action);
                return 'add_note';

            case 'update_field':
                if (! empty($action['field']) && array_key_exists($action['field'], $entity->getAttributes())) {
                    $entity->forceFill([$action['field'] => $action['value'] ?? null])->saveQuietly();
                    return "update_field: {$action['field']}";
                }
                return null;

            case 'assign_owner':
                if (! empty($action['user_id']) && in_array('owner_id', $entity->getFillable())) {
                    $entity->forceFill(['owner_id' => (int) $action['user_id']])->saveQuietly();
                    return 'assign_owner';
                }
                return null;

            case 'escalate':
                if (! empty($action['user_id']) && in_array('owner_id', $entity->getFillable())) {
                    $entity->forceFill(['owner_id' => (int) $action['user_id']])->saveQuietly();
                }
                $this->createTask($entity, $entityType, [
                    'subject'     => $action['subject'] ?? 'Escalation — needs attention',
                    'due_in_days' => 1,
                    'assign_to'   => 'owner',
                ]);
                return 'escalate';

            case 'send_email':
                return $this->sendEmail($entity, $entityType, $action) ? 'send_email' : null;

            case 'send_whatsapp':
                return $this->sendWhatsApp($entity, $entityType, $action) ? 'send_whatsapp' : null;

            case 'notify_user':
                $uid = $this->resolveOwner($entity, $action['assign_to'] ?? 'owner', $action['user_id'] ?? null);
                if ($uid) {
                    $this->notifier->notify($uid, 'workflow', $action['title'] ?? 'Automation alert', $action['body'] ?? null, null, $entityType, $entity->getKey());
                    return 'notify_user';
                }
                return null;

            case 'ai_action':
                return ($this->ai->isConfigured() && $this->runAiAction($entity, $entityType, $action['ai'] ?? ''))
                    ? 'ai_action: ' . ($action['ai'] ?? '')
                    : null;

            case 'create_deal':
                return $this->createDeal($entity, $entityType, $action) ? 'create_deal' : null;

            case 'http_request':
                return $this->doHttpRequest($entity, $entityType, $action) ? 'http_request' : null;
        }

        return null;
    }

    // ── Multi-step flows (Phase 3) ───────────────────────────────────────────────

    /** Enter a multi-step flow: create a run and process from step 0. */
    private function startFlow(CrmWorkflow $workflow, Model $entity): void
    {
        $run = CrmFlowRun::create([
            'workflow_id'  => $workflow->id,
            'entity_type'  => $this->entityType($entity),
            'entity_id'    => $entity->getKey(),
            'current_step' => 0,
            'status'       => 'active',
            'next_run_at'  => now(),
            'started_at'   => now(),
        ]);

        $this->advanceRun($run);
    }

    /**
     * Advance a flow run: execute steps in order until a wait (pauses), a failed
     * gate (exits) or the end. Safe to call repeatedly from the scheduler.
     */
    public function advanceRun(CrmFlowRun $run): void
    {
        $workflow = $run->workflow ?: CrmWorkflow::find($run->workflow_id);
        if (! $workflow) {
            $run->forceFill(['status' => 'cancelled', 'finished_at' => now()])->save();
            return;
        }

        $modelClass = $this->entityModel($run->entity_type);
        $entity     = $modelClass ? $modelClass::find($run->entity_id) : null;
        if (! $entity) {
            $run->forceFill(['status' => 'cancelled', 'finished_at' => now()])->save();
            return;
        }

        // Compile the (possibly branching) step tree into a flat ops list with
        // jump targets, then run it with current_step as the program counter.
        // Linear flows compile 1:1, so current_step still maps to step index.
        $ops = $this->compile($workflow->steps ?? []);
        $pc  = $run->current_step;

        try {
            while (true) {
                $op = $ops[$pc] ?? null;

                if ($op === null) { // past the end
                    $run->forceFill(['current_step' => $pc, 'status' => 'completed', 'finished_at' => now()])->save();
                    return;
                }

                switch ($op['op']) {
                    case 'wait':
                        $amount = max(0, (int) ($op['amount'] ?? 0));
                        $unit   = in_array($op['unit'] ?? 'days', ['minutes', 'hours', 'days'], true) ? $op['unit'] : 'days';
                        $run->forceFill([
                            'current_step' => $pc + 1,
                            'next_run_at'  => now()->add($unit, $amount),
                        ])->save();
                        return; // pause — the scheduler resumes when due

                    case 'gate': // condition gate: exit the flow if not met
                        if (! $this->evaluateStepConditions($op, $entity)) {
                            $this->record($workflow, $run->entity_type, $run->entity_id, 'skipped', ['reason' => 'gate not met']);
                            $run->forceFill(['current_step' => $pc, 'status' => 'completed', 'finished_at' => now()])->save();
                            return;
                        }
                        $pc++;
                        break;

                    case 'branch': // if/else: continue into YES path, or jump to NO path
                        $pc = $this->evaluateStepConditions($op, $entity) ? $pc + 1 : (int) $op['else'];
                        break;

                    case 'jump': // end of a YES path — skip over the NO path
                        $pc = (int) $op['to'];
                        break;

                    case 'action':
                        $action = array_merge($op, ['type' => $op['action'] ?? '']);
                        $label  = $this->runSingleAction($action, $entity, $run->entity_type);
                        $this->record($workflow, $run->entity_type, $run->entity_id, 'success', ['step' => $pc, 'action' => $label]);
                        $pc++;
                        break;

                    default: // unknown op — skip
                        $pc++;
                        break;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('CRM flow run failed', ['run' => $run->id, 'error' => $e->getMessage()]);
            $run->forceFill(['current_step' => $pc, 'status' => 'failed', 'last_error' => $e->getMessage(), 'finished_at' => now()])->save();
            $this->record($workflow, $run->entity_type, $run->entity_id, 'failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Flatten a (possibly branching) step tree into a linear ops list with
     * absolute jump targets. Backward compatible: a linear flow compiles 1:1.
     */
    private function compile(array $steps): array
    {
        $ops = [];

        $emit = function (array $steps) use (&$emit, &$ops) {
            foreach ($steps as $step) {
                switch ($step['type'] ?? 'action') {
                    case 'wait':
                        $ops[] = ['op' => 'wait', 'amount' => $step['amount'] ?? 0, 'unit' => $step['unit'] ?? 'days'];
                        break;

                    case 'condition':
                        $ops[] = ['op' => 'gate', 'match_type' => $step['match_type'] ?? 'all', 'conditions' => $step['conditions'] ?? []];
                        break;

                    case 'branch':
                        $branchIdx = count($ops);
                        $ops[] = ['op' => 'branch', 'match_type' => $step['match_type'] ?? 'all', 'conditions' => $step['conditions'] ?? [], 'else' => null];
                        $emit($step['then'] ?? []);                 // YES path
                        $jumpIdx = count($ops);
                        $ops[] = ['op' => 'jump', 'to' => null];
                        $ops[$branchIdx]['else'] = count($ops);     // NO path starts here
                        $emit($step['else'] ?? []);
                        $ops[$jumpIdx]['to'] = count($ops);         // past the NO path
                        break;

                    default: // action
                        $ops[] = array_merge(['op' => 'action'], $step);
                        break;
                }
            }
        };

        $emit($steps);

        return $ops;
    }

    /** Resume all flow runs whose wait has elapsed. Returns how many advanced. */
    public function processDueFlows(): int
    {
        $count = 0;
        CrmFlowRun::with('workflow')->where('status', 'active')->where('next_run_at', '<=', now())
            ->chunkById(100, function ($runs) use (&$count) {
                foreach ($runs as $run) {
                    // Node cursor → graph runner; otherwise the legacy step runner.
                    $run->current_node ? $this->advanceGraphRun($run) : $this->advanceRun($run);
                    $count++;
                }
            });

        return $count;
    }

    // ── Visual canvas flows (Phase 5: node/edge graph) ───────────────────────────

    /** Enter a graph flow at the node the trigger node points to. */
    private function startFlowGraph(CrmWorkflow $workflow, Model $entity): void
    {
        $graph = $workflow->graph ?? [];
        $start = $this->graphStart($graph);
        if (! $start) {
            return; // nothing wired up after the trigger
        }

        $run = CrmFlowRun::create([
            'workflow_id'  => $workflow->id,
            'entity_type'  => $this->entityType($entity),
            'entity_id'    => $entity->getKey(),
            'current_node' => $start,
            'status'       => 'active',
            'next_run_at'  => now(),
            'started_at'   => now(),
        ]);

        $this->advanceGraphRun($run);
    }

    /**
     * Walk the graph from the run's current node: execute each node and follow the
     * edge to the next, pausing at waits. A hop cap guards against cycles.
     */
    public function advanceGraphRun(CrmFlowRun $run): void
    {
        $workflow = $run->workflow ?: CrmWorkflow::find($run->workflow_id);
        $graph    = $workflow?->graph ?? [];
        if (! $workflow || empty($graph['nodes'] ?? null)) {
            $run->forceFill(['status' => 'cancelled', 'finished_at' => now()])->save();
            return;
        }

        $modelClass = $this->entityModel($run->entity_type);
        $entity     = $modelClass ? $modelClass::find($run->entity_id) : null;
        if (! $entity) {
            $run->forceFill(['status' => 'cancelled', 'finished_at' => now()])->save();
            return;
        }

        $nodes = collect($graph['nodes'])->keyBy('id');
        $edges = $graph['edges'] ?? [];

        try {
            $hops = 0;
            $nodeId = $run->current_node;

            while ($nodeId !== null) {
                if (++$hops > 500) { // cycle / runaway guard
                    throw new \RuntimeException('Flow exceeded the maximum number of steps (possible loop).');
                }

                $node = $nodes->get($nodeId);
                if (! $node) {
                    break; // dangling edge → end
                }

                $data = $node['data'] ?? [];

                switch ($node['type'] ?? '') {
                    case 'wait':
                        $amount = max(0, (int) ($data['amount'] ?? 0));
                        $unit   = in_array($data['unit'] ?? 'days', ['minutes', 'hours', 'days'], true) ? $data['unit'] : 'days';
                        $next   = $this->graphNext($edges, $nodeId);
                        $run->forceFill(['current_node' => $next, 'next_run_at' => now()->add($unit, $amount)])->save();
                        return; // pause

                    case 'condition': // gate — exit if not met
                        if (! $this->evaluateStepConditions($data, $entity)) {
                            $this->record($workflow, $run->entity_type, $run->entity_id, 'skipped', ['reason' => 'gate not met']);
                            $nodeId = null;
                            break;
                        }
                        $nodeId = $this->graphNext($edges, $nodeId);
                        break;

                    case 'branch':
                        $nodeId = $this->graphNext($edges, $nodeId, $this->evaluateStepConditions($data, $entity) ? 'yes' : 'no');
                        break;

                    case 'action':
                        $action = array_merge($data, ['type' => $data['action'] ?? '']);
                        $label  = $this->runSingleAction($action, $entity, $run->entity_type);
                        $this->record($workflow, $run->entity_type, $run->entity_id, 'success', ['node' => $nodeId, 'action' => $label]);
                        $nodeId = $this->graphNext($edges, $nodeId);
                        break;

                    default: // trigger or unknown → follow its edge
                        $nodeId = $this->graphNext($edges, $nodeId);
                        break;
                }
            }

            $run->forceFill(['current_node' => null, 'status' => 'completed', 'finished_at' => now()])->save();
        } catch (\Throwable $e) {
            Log::warning('CRM graph flow failed', ['run' => $run->id, 'error' => $e->getMessage()]);
            $run->forceFill(['status' => 'failed', 'last_error' => $e->getMessage(), 'finished_at' => now()])->save();
            $this->record($workflow, $run->entity_type, $run->entity_id, 'failed', ['error' => $e->getMessage()]);
        }
    }

    /** The node the trigger node connects to (or the first node with no inbound edge). */
    private function graphStart(array $graph): ?string
    {
        $nodes = $graph['nodes'] ?? [];
        $edges = $graph['edges'] ?? [];

        $trigger = collect($nodes)->firstWhere('type', 'trigger');
        if ($trigger) {
            $next = $this->graphNext($edges, $trigger['id']);
            if ($next) {
                return $next;
            }
        }

        // Fallback: a non-trigger node with no incoming edge.
        $targets = collect($edges)->pluck('target')->all();
        foreach ($nodes as $n) {
            if (($n['type'] ?? '') !== 'trigger' && ! in_array($n['id'], $targets, true)) {
                return $n['id'];
            }
        }

        return null;
    }

    /** Target of the edge leaving $nodeId (optionally on a specific handle). */
    private function graphNext(array $edges, string $nodeId, ?string $handle = null): ?string
    {
        foreach ($edges as $e) {
            if (($e['source'] ?? null) !== $nodeId) {
                continue;
            }
            if ($handle !== null && ($e['sourceHandle'] ?? null) !== $handle) {
                continue;
            }
            if ($handle === null && ! empty($e['sourceHandle']) && ! in_array($e['sourceHandle'], [null, 'out', 'source'], true)) {
                continue; // skip labelled (yes/no) handles when no specific handle requested
            }

            return $e['target'] ?? null;
        }

        return null;
    }

    /** Public: evaluate a set of conditions against an entity (reused by Sequences). */
    public function conditionsPass(Model $entity, array $conditions, string $matchType = 'all'): bool
    {
        return $this->evaluateStepConditions(['conditions' => $conditions, 'match_type' => $matchType], $entity);
    }

    /** Evaluate a condition-step's conditions (mirrors conditionsMatch). */
    private function evaluateStepConditions(array $step, Model $entity): bool
    {
        $conditions = $step['conditions'] ?? [];
        if (empty($conditions)) {
            return true;
        }

        $matchAll = ($step['match_type'] ?? 'all') === 'all';
        foreach ($conditions as $c) {
            $ok = $this->evaluateCondition($entity, $c['field'] ?? '', $c['operator'] ?? '=', $c['value'] ?? null);
            if ($matchAll && ! $ok) return false;
            if (! $matchAll && $ok) return true;
        }

        return $matchAll;
    }

    /** Map an entity-type string to its model class. */
    private function entityModel(string $type): ?string
    {
        return match ($type) {
            'lead'     => CrmLead::class,
            'contact'  => CrmContact::class,
            'account'  => CrmAccount::class,
            'deal'     => CrmDeal::class,
            'activity' => CrmActivity::class,
            default    => null,
        };
    }

    /** Create a deal, linking the account/contact from the source record. */
    private function createDeal(Model $entity, string $entityType, array $action): bool
    {
        $pipeline = CrmPipeline::where('is_default', true)->with('stages')->first()
            ?? CrmPipeline::with('stages')->first();
        $stage = $pipeline?->stages->first();
        if (! $pipeline || ! $stage) {
            return false; // no pipeline configured
        }

        $c     = $this->entityContactData($entity, $entityType);
        $title = CrmEmailTemplate::merge($action['title'] ?? '', $c['merge']) ?: ('New deal — ' . ($c['merge']['company'] ?: ucfirst($entityType)));

        $accountId = match ($entityType) {
            'account' => $entity->getKey(),
            'contact', 'deal' => $entity->getAttribute('account_id'),
            default   => null,
        };
        $contactId = match ($entityType) {
            'contact' => $entity->getKey(),
            'deal'    => $entity->getAttribute('contact_id'),
            default   => null,
        };

        CrmDeal::create([
            'uuid'             => Str::uuid(),
            'title'            => $title,
            'account_id'       => $accountId,
            'contact_id'       => $contactId,
            'pipeline_id'      => $pipeline->id,
            'stage_id'         => $stage->id,
            'amount'           => is_numeric($action['amount'] ?? null) ? $action['amount'] : 0,
            'currency'         => 'ZAR',
            'status'           => 'open',
            'probability'      => $stage->probability,
            'stage_changed_at' => now(),
            'owner_id'         => $entity->getAttribute('owner_id') ?? Auth::id(),
            'created_by'       => Auth::id(),
        ]);

        return true;
    }

    /** Fire an outbound HTTP request with the record payload. */
    private function doHttpRequest(Model $entity, string $entityType, array $action): bool
    {
        $url = trim((string) ($action['url'] ?? ''));
        if ($url === '') return false;

        $method  = strtolower($action['method'] ?? 'post') === 'get' ? 'get' : 'post';
        $payload = [
            'entity' => $entityType,
            'id'     => $entity->getKey(),
            'data'   => $entity->attributesToArray(),
        ];

        try {
            $resp = $method === 'get'
                ? Http::timeout(8)->get($url, ['entity' => $entityType, 'id' => $entity->getKey()])
                : Http::timeout(8)->asJson()->post($url, $payload);

            return $resp->successful();
        } catch (\Throwable $e) {
            Log::warning('CRM workflow http_request failed', ['url' => $url, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /** Resolve a (possibly dotted, e.g. "account.industry") field value. */
    private function resolveValue(Model $entity, string $field)
    {
        if (! str_contains($field, '.')) {
            return $entity->getAttribute($field);
        }

        [$relation, $attr] = explode('.', $field, 2);

        try {
            $related = method_exists($entity, $relation) ? $entity->{$relation} : null;
            return $related instanceof Model ? $this->resolveValue($related, $attr) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Send a templated email to the record's contact. Returns true if sent. */
    private function sendEmail(Model $entity, string $entityType, array $action): bool
    {
        $c = $this->entityContactData($entity, $entityType);
        if (empty($c['email']) || ! $this->mail->hasProvider()) {
            return false;
        }

        $subject = CrmEmailTemplate::merge($action['subject'] ?? '', $c['merge']) ?: 'Message';
        $bodyRaw = CrmEmailTemplate::merge($action['body'] ?? '', $c['merge']);
        $this->mail->sendHtml($c['email'], $subject, nl2br(e($bodyRaw)));
        $this->logSentActivity($entity, $entityType, 'email', $subject, $bodyRaw);

        return true;
    }

    /** Send a WhatsApp message to the record's contact. Returns true if sent. */
    private function sendWhatsApp(Model $entity, string $entityType, array $action): bool
    {
        $c = $this->entityContactData($entity, $entityType);
        if (empty($c['phone']) || ! $this->whatsapp->isConfigured()) {
            return false;
        }

        $body   = CrmEmailTemplate::merge($action['body'] ?? '', $c['merge']);
        $result = $this->whatsapp->sendText($c['phone'], $body);
        if (! ($result['available'] ?? false) || isset($result['error'])) {
            return false;
        }
        $this->logSentActivity($entity, $entityType, 'whatsapp', 'WhatsApp message', $body);

        return true;
    }

    /** Run a curated AI action against the record, writing results back. */
    private function runAiAction(Model $entity, string $entityType, string $ai): bool
    {
        if ($ai === 'score_lead' && $entityType === 'lead') {
            $r = $this->ai->scoreLead([
                'name' => $entity->full_name, 'company' => $entity->company, 'email' => $entity->email,
                'source' => $entity->source, 'status' => $entity->status, 'notes' => $entity->notes,
            ]);
            if (($r['available'] ?? false) && ! isset($r['error'])) {
                $entity->forceFill([
                    'score'        => max(0, min(100, (int) ($r['score'] ?? 0))),
                    'score_reason' => $r['reason'] ?? null,
                ])->saveQuietly();
                return true;
            }
            return false;
        }

        if ($ai === 'assess_deal_risk' && $entityType === 'deal') {
            $entity->loadMissing('stage');
            $r = $this->ai->assessDealRisk([
                'title' => $entity->title, 'stage' => $entity->stage?->name, 'amount' => $entity->amount,
                'age_days' => $entity->created_at?->diffInDays(now()),
                'days_in_stage' => $entity->stage_changed_at?->diffInDays(now()),
                'expected_close_date' => $entity->expected_close_date?->toDateString(),
            ]);
            if (($r['available'] ?? false) && ! isset($r['error'])) {
                $entity->forceFill(['risk_level' => $r['risk_level'] ?? null, 'risk_reason' => $r['reason'] ?? null])->saveQuietly();
                return true;
            }
            return false;
        }

        if ($ai === 'draft_follow_up') {
            $c = $this->entityContactData($entity, $entityType);
            $r = $this->ai->draftFollowUp(['name' => $c['merge']['full_name'] ?? '', 'company' => $c['merge']['company'] ?? '', 'context' => $entityType], 'email');
            if (($r['available'] ?? false) && ! isset($r['error'])) {
                $draft = trim(($r['subject'] ?? '') . "\n\n" . ($r['body'] ?? ''));
                $this->addNote($entity, $entityType, ['subject' => 'AI follow-up draft', 'body' => $draft]);
                return true;
            }
            return false;
        }

        return false;
    }

    private function createTask(Model $entity, string $entityType, array $action): void
    {
        $ownerId = $this->resolveOwner($entity, $action['assign_to'] ?? 'owner', $action['user_id'] ?? null);

        CrmActivity::create([
            'uuid'         => Str::uuid(),
            'type'         => 'task',
            'subject'      => $action['subject'] ?? 'Automated task',
            'description'  => $action['description'] ?? null,
            'related_type' => $entityType,
            'related_id'   => $entity->getKey(),
            'due_at'       => now()->addDays((int) ($action['due_in_days'] ?? 1)),
            'status'       => 'pending',
            'owner_id'     => $ownerId,
            'created_by'   => Auth::id(),
        ]);
    }

    private function addNote(Model $entity, string $entityType, array $action): void
    {
        CrmActivity::create([
            'uuid'         => Str::uuid(),
            'type'         => 'note',
            'subject'      => $action['subject'] ?? 'Automated note',
            'description'  => $action['body'] ?? null,
            'related_type' => $entityType,
            'related_id'   => $entity->getKey(),
            'status'       => 'done',
            'completed_at' => now(),
            'owner_id'     => $this->resolveOwner($entity, 'owner', null),
            'created_by'   => Auth::id(),
        ]);
    }

    private function resolveOwner(Model $entity, string $assignTo, $userId): ?int
    {
        if ($assignTo === 'user' && $userId) return (int) $userId;
        return $entity->getAttribute('owner_id') ?? Auth::id();
    }

    /**
     * Resolve the contact email/phone for the record and the merge-tag data used
     * by send_email / send_whatsapp.
     *
     * @return array{email:?string, phone:?string, merge:array<string,string>}
     */
    private function entityContactData(Model $entity, string $entityType): array
    {
        $ownerId = $entity->getAttribute('owner_id');
        $repName = ($ownerId ? User::find($ownerId)?->person?->first_name : null) ?? '';

        $email = $phone = $first = $last = $full = $org = '';

        switch ($entityType) {
            case 'lead':
                $email = $entity->email; $phone = $entity->phone;
                $first = $entity->first_name; $last = $entity->last_name; $full = $entity->full_name; $org = $entity->company;
                break;
            case 'contact':
                $email = $entity->email; $phone = $entity->phone ?: $entity->mobile;
                $first = $entity->first_name; $last = $entity->last_name; $full = $entity->full_name;
                $org = $entity->account_id ? (CrmAccount::find($entity->account_id)?->name ?? '') : '';
                break;
            case 'account':
                $email = $entity->email; $phone = $entity->phone;
                $full = $entity->name; $org = $entity->name;
                break;
            case 'deal':
                $contact = $entity->contact_id ? CrmContact::find($entity->contact_id) : null;
                $account = $entity->account_id ? CrmAccount::find($entity->account_id) : null;
                $email = $contact?->email ?: $account?->email;
                $phone = $contact?->phone ?: $contact?->mobile;
                $first = $contact?->first_name; $last = $contact?->last_name;
                $full  = $contact?->full_name ?: $entity->title; $org = $account?->name;
                break;
        }

        return [
            'email' => $email ?: null,
            'phone' => $phone ?: null,
            'merge' => [
                'first_name'   => (string) ($first ?? ''),
                'last_name'    => (string) ($last ?? ''),
                'full_name'    => (string) ($full ?? ''),
                'company'      => (string) ($org ?? ''),
                'company_name' => (string) config('app.name'),
                'email'        => (string) ($email ?? ''),
                'phone'        => (string) ($phone ?? ''),
                'rep_name'     => (string) $repName,
            ],
        ];
    }

    /** Log a sent email/WhatsApp as a completed activity for the timeline. */
    private function logSentActivity(Model $entity, string $entityType, string $type, string $subject, ?string $body): void
    {
        CrmActivity::create([
            'uuid'         => Str::uuid(),
            'type'         => $type,
            'subject'      => $subject,
            'description'  => $body,
            'related_type' => $entityType,
            'related_id'   => $entity->getKey(),
            'status'       => 'done',
            'completed_at' => now(),
            'owner_id'     => $this->resolveOwner($entity, 'owner', null),
            'created_by'   => Auth::id(),
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function entityType(Model $entity): string
    {
        return match (class_basename($entity)) {
            'CrmLead'    => 'lead',
            'CrmContact' => 'contact',
            'CrmAccount' => 'account',
            'CrmDeal'    => 'deal',
            default      => Str::snake(class_basename($entity)),
        };
    }

    private function record(CrmWorkflow $workflow, string $entityType, $entityId, string $status, array $detail): void
    {
        try {
            CrmWorkflowRun::create([
                'workflow_id' => $workflow->id,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'status'      => $status,
                'detail'      => $detail,
            ]);
        } catch (\Throwable $e) {
            Log::warning('CRM workflow run log failed', ['error' => $e->getMessage()]);
        }
    }
}
