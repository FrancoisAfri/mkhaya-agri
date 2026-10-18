<?php

namespace App\Modules\Workflow\Services;

use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowForm;
use App\Modules\Workflow\Support\ActionCatalog;
use App\Modules\Workflow\Support\Conditions;
use App\Modules\Workflow\Support\NodeTypes;
use App\Modules\Workflow\Support\WorkflowSettings;
use Illuminate\Support\Facades\DB;

/**
 * Checks a workflow before it can be published (PRS §4.3). Every problem names the node or
 * connection it is on and says how to fix it.
 */
class GraphValidator
{
    public function __construct(private readonly WorkflowSettings $settings, private readonly AssigneeResolver $resolver)
    {
    }

    /**
     * @return list<array{node:?string, edge:?string, message:string, fix:string}>
     */
    public function validate(array $graph, WorkflowDefinition $definition): array
    {
        $errors = [];
        $add = function (?string $node, ?string $edge, string $message, string $fix) use (&$errors) {
            $errors[] = compact('node', 'edge', 'message', 'fix');
        };

        $nodes = [];
        foreach ($graph['nodes'] ?? [] as $n) {
            $key = (string) ($n['key'] ?? '');
            if ($key === '' || ! preg_match('/^[A-Za-z0-9_-]{1,60}$/', $key)) {
                $add(null, null, 'A step has no valid key.', 'Delete the step and add it again.');

                continue;
            }
            if (isset($nodes[$key])) {
                $add($key, null, "Two steps share the key \"{$key}\".", 'Delete one of them.');
            }
            if (! isset(NodeTypes::TYPES[$n['type'] ?? ''])) {
                $add($key, null, 'Unknown step type "' . ($n['type'] ?? '') . '".', 'Replace it with a step from the palette.');

                continue;
            }
            $nodes[$key] = $n;
        }
        $edges = $graph['edges'] ?? [];
        $max = (int) $this->settings->get('max_nodes', $definition->company_id);
        if (count($nodes) > $max) {
            $add(null, null, 'The workflow has ' . count($nodes) . " steps; the company's limit is {$max}.", 'Split it into sub-workflows.');
        }

        $starts = array_keys(array_filter($nodes, fn ($n) => $n['type'] === 'start'));
        $ends = array_keys(array_filter($nodes, fn ($n) => $n['type'] === 'end'));
        if (count($starts) !== 1) {
            $add(null, null, count($starts) === 0 ? 'There is no Start step.' : 'There is more than one Start step.', 'Keep exactly one Start step.');
        }
        if (! $ends) {
            $add(null, null, 'There is no End step.', 'Add an End step and connect the last step to it.');
        }

        $out = $in = [];
        foreach ($edges as $e) {
            $ek = (string) ($e['key'] ?? '?');
            if (! isset($nodes[$e['from'] ?? '']) || ! isset($nodes[$e['to'] ?? ''])) {
                $add($e['from'] ?? null, $ek, 'A connection points to a step that does not exist.', 'Delete the connection and draw it again.');

                continue;
            }
            if ($e['from'] === $e['to']) {
                $add($e['from'], $ek, 'A step is connected to itself.', 'Remove the loop.');
            }
            $out[$e['from']][] = $e;
            $in[$e['to']][] = $e;
            foreach (Conditions::problems($e['condition'] ?? null) as $p) {
                $add($e['from'], $ek, 'Condition on the connection to "' . ($nodes[$e['to']]['name'] ?? $e['to']) . "\": {$p}", 'Edit the connection\'s condition.');
            }
        }

        foreach ($nodes as $key => $n) {
            $name = $n['name'] ?? $key;
            $type = $n['type'];
            $cfg = $n['config'] ?? [];
            $outs = $out[$key] ?? [];
            if ($type === 'start' && ! empty($in[$key])) {
                $add($key, null, 'The Start step has an incoming connection.', 'Connect to the step after Start instead.');
            }
            if ($type === 'end' && $outs) {
                $add($key, null, "End step \"{$name}\" has an outgoing connection.", 'Remove it; nothing runs after an End.');
            }
            if ($type !== 'end' && ! $outs) {
                $add($key, null, "\"{$name}\" leads nowhere.", 'Connect it to the next step or to an End step.');
            }
            if ($type === 'end' && ! in_array($cfg['outcome'] ?? 'completed', ['completed', 'rejected', 'cancelled'], true)) {
                $add($key, null, "End \"{$name}\" has an unknown outcome.", 'Choose completed, rejected or cancelled.');
            }

            // Routing by outcome: every outgoing connection names an outcome the step can finish with,
            // and the outcomes it can finish with each have somewhere to go.
            if (NodeTypes::routesByOutcome($type)) {
                $needed = match ($type) {
                    'approval' => array_merge(['approved', 'rejected'], ! empty($cfg['allow_return']) ? ['returned'] : []),
                    'user_task', 'review' => array_merge(['completed'], ! empty($cfg['allow_return']) ? ['returned'] : []),
                    'subworkflow' => ['completed', 'rejected'],
                    'exception' => ['resolved', 'cancelled'],
                };
                $labels = array_map(fn ($e) => $e['outcome'] ?? null, $outs);
                foreach ($outs as $e) {
                    $o = $e['outcome'] ?? null;
                    if ($o === null && count($outs) === 1 && in_array($type, ['user_task', 'review'], true)) {
                        continue; // a single plain connection out of a task means "completed"
                    }
                    if (! in_array($o, NodeTypes::outcomes($type), true)) {
                        $add($key, $e['key'] ?? null, "A connection from \"{$name}\" does not say which outcome it is for.", 'Set its outcome to one of: ' . implode(', ', NodeTypes::outcomes($type)) . '.');
                    }
                }
                foreach ($needed as $o) {
                    $covered = in_array($o, $labels, true) || ($o === 'completed' && count($outs) === 1 && $labels === [null]);
                    if (! $covered) {
                        $add($key, null, "\"{$name}\" has no route for the outcome \"{$o}\".", "Draw a connection from it and set its outcome to \"{$o}\".");
                    }
                }
            }

            if ($type === 'decision') {
                if (count($outs) < 2) {
                    $add($key, null, "Decision \"{$name}\" needs at least two routes.", 'Add another connection, with a condition or as the default.');
                }
                $defaults = array_filter($outs, fn ($e) => ! empty($e['is_default']));
                if (count($defaults) !== 1) {
                    $add($key, null, "Decision \"{$name}\" needs exactly one default route.", 'Mark one connection as the default (taken when no condition matches).');
                }
                foreach ($outs as $e) {
                    if (empty($e['is_default']) && empty($e['condition'])) {
                        $add($key, $e['key'] ?? null, "A route out of decision \"{$name}\" has no condition.", 'Give it a condition or make it the default.');
                    }
                }
            }

            if ($type === 'parallel') {
                if (count($outs) < 2) {
                    $add($key, null, "Parallel split \"{$name}\" needs at least two branches.", 'Connect it to two or more steps.');
                } elseif (! $this->branchesJoin($key, $nodes, $out)) {
                    $add($key, null, "The branches of \"{$name}\" do not all reach the same Join.", 'Add a Join step and connect the end of every branch to it.');
                }
            }
            if ($type === 'join') {
                $incoming = count($in[$key] ?? []);
                if ($incoming < 2) {
                    $add($key, null, "Join \"{$name}\" has fewer than two incoming branches.", 'Connect each parallel branch to it.');
                }
                $mode = $cfg['mode'] ?? 'all';
                if (! in_array($mode, ['all', 'any', 'quorum'], true)) {
                    $add($key, null, "Join \"{$name}\" has an unknown rule.", 'Choose all, any or quorum.');
                } elseif ($mode === 'quorum' && ((int) ($cfg['quorum'] ?? 0) < 1 || (int) ($cfg['quorum'] ?? 0) > $incoming)) {
                    $add($key, null, "Join \"{$name}\" needs a quorum between 1 and {$incoming}.", 'Set how many branches must finish.');
                }
            }

            if (in_array($type, NodeTypes::HUMAN, true) || $type === 'notification') {
                $spec = $type === 'notification' ? ($cfg['to'] ?? null) : ($cfg['assignee'] ?? null);
                if ($type === 'exception' && ! $spec) {
                    $spec = ['type' => 'process_owner'];
                }
                foreach ($this->assigneeProblems($spec, $definition, $graph) as $p) {
                    $add($key, null, "\"{$name}\": {$p}", $type === 'notification' ? 'Choose who is notified.' : 'Choose who the task goes to.');
                }
            }
            if ($type === 'approval') {
                $mode = $cfg['mode'] ?? 'any';
                if (! in_array($mode, ['any', 'all', 'quorum'], true)) {
                    $add($key, null, "Approval \"{$name}\" has an unknown approval rule.", 'Choose any one, all, or a quorum.');
                } elseif ($mode === 'quorum' && (int) ($cfg['quorum'] ?? 0) < 1) {
                    $add($key, null, "Approval \"{$name}\" needs a quorum of at least 1.", 'Set how many approvals are needed.');
                }
                if (! empty($cfg['allow_self_approval']) && $this->settings->get('self_approval', $definition->company_id) === 'deny') {
                    $add($key, null, "Approval \"{$name}\" allows self-approval, which company policy forbids.", 'Untick "allow self-approval".');
                }
            }
            if (! empty($cfg['sla'])) {
                $h = $cfg['sla']['hours'] ?? null;
                if (! is_numeric($h) || $h <= 0 || $h > 8760) {
                    $add($key, null, "\"{$name}\" has an SLA without a valid duration.", 'Set the target in hours (1–8760).');
                }
                $esc = $cfg['sla']['escalation'] ?? null;
                if ($esc !== null && ! isset(NodeTypes::ESCALATIONS[$esc])) {
                    $add($key, null, "\"{$name}\" has an unknown escalation.", 'Choose an escalation from the list.');
                }
            }
            if (! empty($cfg['form_id']) && ! WorkflowForm::query()->whereKey($cfg['form_id'])->whereNotNull('current_version_id')->exists()) {
                $add($key, null, "\"{$name}\" uses a form that does not exist or has no saved version.", 'Pick another form or save the form first.');
            }
            if ($type === 'delay') {
                if (! is_numeric($cfg['hours'] ?? null) && blank($cfg['until_field'] ?? null)) {
                    $add($key, null, "Wait \"{$name}\" has no duration.", 'Set hours, or a date field to wait until.');
                }
            }
            if ($type === 'notification' && blank($cfg['message'] ?? null)) {
                $add($key, null, "Notification \"{$name}\" has no message.", 'Write the message.');
            }
            if ($type === 'action') {
                $action = (string) ($cfg['action'] ?? '');
                if (! ActionCatalog::exists($action)) {
                    $add($key, null, "\"{$name}\" uses an action that is not available.", 'Choose an action from the list.');
                } else {
                    if (! ActionCatalog::permitted($action, (array) $this->settings->get('permitted_actions', $definition->company_id))) {
                        $add($key, null, "\"{$name}\" uses \"" . ActionCatalog::ACTIONS[$action]['label'] . '", which this company has not permitted.', 'An administrator must permit it under Workflow administration, or remove the step.');
                    }
                    foreach (ActionCatalog::ACTIONS[$action]['inputs'] as $input) {
                        if (! empty($input['required']) && blank($cfg['input'][$input['key']] ?? null)) {
                            $add($key, null, "\"{$name}\" is missing \"{$input['label']}\".", 'Fill it in on the step.');
                        }
                    }
                    if (ActionCatalog::isMaterial($action) && $starts && $this->reachableAvoiding($starts[0], $key, $nodes, $out, 'approval')) {
                        $add($key, null, "\"{$name}\" changes a business record but can be reached without an approval.", 'Put an Approval step on every path before it.');
                    }
                    $attempts = (int) ($cfg['max_attempts'] ?? 3);
                    if ($attempts < 1 || $attempts > 10) {
                        $add($key, null, "\"{$name}\" has an invalid retry limit.", 'Allow between 1 and 10 attempts.');
                    }
                }
            }
            if ($type === 'subworkflow') {
                $child = WorkflowDefinition::query()->where('key', (string) ($cfg['definition_key'] ?? ''))->first();
                if (! $child || ! $child->current_version_id) {
                    $add($key, null, "Sub-workflow \"{$name}\" does not name a published workflow.", 'Choose a published workflow.');
                } elseif ($child->id === $definition->id) {
                    $add($key, null, "Sub-workflow \"{$name}\" starts this same workflow.", 'Choose a different workflow.');
                }
            }
            if ($type === 'document' && blank($cfg['body'] ?? null)) {
                $add($key, null, "Document \"{$name}\" has no content.", 'Write the document template.');
            }
        }

        // Reachability: every step can be reached from Start and can reach an End (§4.3: no dead ends).
        if (count($starts) === 1) {
            $reached = $this->reach([$starts[0]], $out);
            foreach (array_diff(array_keys($nodes), $reached) as $orphan) {
                $add($orphan, null, '"' . ($nodes[$orphan]['name'] ?? $orphan) . '" can never be reached.', 'Connect it from an earlier step, or delete it.');
            }
            $reverse = [];
            foreach ($edges as $e) {
                if (isset($nodes[$e['from'] ?? ''], $nodes[$e['to'] ?? ''])) {
                    $reverse[$e['to']][] = ['to' => $e['from']];
                }
            }
            $canFinish = $this->reach($ends, $reverse);
            foreach (array_diff($reached, $canFinish) as $stuck) {
                $add($stuck, null, 'From "' . ($nodes[$stuck]['name'] ?? $stuck) . '" there is no way to reach an End.', 'Connect its path to an End step.');
            }
        }

        // One message per problem: the same check can trip twice through different routes.
        return array_values(array_map('unserialize', array_unique(array_map('serialize', $errors))));
    }

    private function assigneeProblems(?array $spec, WorkflowDefinition $definition, array $graph): array
    {
        if (! $spec || empty($spec['type'])) {
            return ['nobody is assigned.'];
        }
        $type = $spec['type'];
        $value = $spec['value'] ?? null;
        if (! isset(NodeTypes::ASSIGNEE_TYPES[$type])) {
            return ['the assignment type is not recognised.'];
        }
        if ($type === 'user') {
            $ids = array_filter(array_map('intval', (array) $value));

            return $ids && count($this->resolver->active($ids, $definition->company_id)) === count($ids) ? [] : ['the named person is not an active user of this company.'];
        }
        if ($type === 'role') {
            return $value && DB::table('roles')->where('name', $value)->exists() ? [] : ['the role does not exist.'];
        }
        if ($type === 'field' && blank($value)) {
            return ['no form field is chosen for the person.'];
        }
        if (in_array($type, ['record_owner', 'budget_holder'], true) && ($definition->module ?? 'generic') === 'generic') {
            return ['this workflow has no business record, so there is no record owner or budget holder.'];
        }
        if ($type === 'process_owner' && ! $definition->process_owner_id) {
            return ['the workflow has no process owner.'];
        }

        return [];
    }

    /** Every branch of a split reaches one common Join. */
    private function branchesJoin(string $split, array $nodes, array $out): bool
    {
        $common = null;
        foreach ($out[$split] ?? [] as $e) {
            $joins = array_filter($this->reach([$e['to']], $out), fn ($k) => ($nodes[$k]['type'] ?? null) === 'join');
            $common = $common === null ? $joins : array_intersect($common, $joins);
        }

        return ! empty($common);
    }

    /** Whether $target is reachable from $from without passing through a node of $avoidType. */
    private function reachableAvoiding(string $from, string $target, array $nodes, array $out, string $avoidType): bool
    {
        $seen = [$from => true];
        $stack = [$from];
        while ($stack) {
            $k = array_pop($stack);
            if ($k === $target) {
                return true;
            }
            if ($k !== $from && ($nodes[$k]['type'] ?? null) === $avoidType) {
                continue;
            }
            foreach ($out[$k] ?? [] as $e) {
                if (! isset($seen[$e['to']])) {
                    $seen[$e['to']] = true;
                    $stack[] = $e['to'];
                }
            }
        }

        return false;
    }

    /** @return list<string> */
    private function reach(array $from, array $out): array
    {
        $seen = array_fill_keys($from, true);
        $stack = $from;
        while ($stack) {
            $k = array_pop($stack);
            foreach ($out[$k] ?? [] as $e) {
                if (! isset($seen[$e['to']])) {
                    $seen[$e['to']] = true;
                    $stack[] = $e['to'];
                }
            }
        }

        return array_keys($seen);
    }
}
