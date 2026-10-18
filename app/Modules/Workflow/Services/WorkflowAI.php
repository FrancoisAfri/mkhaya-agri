<?php

namespace App\Modules\Workflow\Services;

use App\Core\AI\Base\BaseModuleAI;
use App\Modules\Tenancy\Services\AiQuota;
use App\Modules\Workflow\Models\WorkflowComment;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowTask;
use App\Modules\Workflow\Models\WorkflowVersion;
use App\Modules\Workflow\Support\ActionCatalog;
use App\Modules\Workflow\Support\Conditions;
use App\Modules\Workflow\Support\NodeTypes;
use App\Modules\Workflow\Support\WorkflowSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AI assistance (PRS §15), always advisory:
 *   - a drafted workflow is sanitised against what exists (node types, roles, people, actions) and
 *     saved as a draft that only an authorised person can publish;
 *   - nothing here decides a task, changes a record or sees data the company has not allowed;
 *   - every feature has a non-AI fallback, so the engine works without a provider.
 * Calls are metered through BaseModuleAI and the company's AI quota; uses are audited.
 */
class WorkflowAI extends BaseModuleAI
{
    protected string $module = 'workflow';

    protected function systemPrompt(): string
    {
        return 'You assist with business workflow design in Mkhaya Agri. Use only the node types, assignee types, roles, operators and actions you are given. '
            . 'Never invent people, roles, permissions, endpoints or actions. Be concise and plain.';
    }

    private function enabled(): bool
    {
        return (bool) app(WorkflowSettings::class)->get('ai_enabled');
    }

    /** Try the provider; null when AI is off, over quota or unavailable. */
    private function tryJson(string $prompt, array $context, string $task): ?array
    {
        if (! $this->enabled()) {
            return null;
        }
        try {
            app(AiQuota::class)->ensureWithinQuota();

            return $this->askForJson($prompt, $context, ['task' => $task]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    private function tryText(string $prompt, array $context, string $task): ?string
    {
        if (! $this->enabled()) {
            return null;
        }
        try {
            app(AiQuota::class)->ensureWithinQuota();
            $r = $this->ask($prompt, $context, ['task' => $task]);

            return trim(self::plain($r->content)) ?: null;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /** The screens show plain text: drop markdown emphasis and headings a model may add. */
    public static function plain(string $text): string
    {
        $text = preg_replace('/^\s{0,3}#{1,6}\s*/m', '', $text);
        $text = preg_replace('/(\*\*|__)(.+?)\1/s', '$2', $text);
        $text = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?!\w)/s', '$1', $text);

        return preg_replace('/^\s*[-*]\s+/m', '• ', $text);
    }

    // ── Drafting (WF-018) ───────────────────────────────────────────────────

    /** @return array{graph: array, notes: list<string>, source: string} */
    public function draftGraph(string $description): array
    {
        $roles = DB::table('roles')->orderBy('name')->pluck('name')->all();
        $context = [
            'node_types' => array_keys(NodeTypes::TYPES), 'assignee_types' => array_keys(NodeTypes::ASSIGNEE_TYPES), 'roles' => $roles,
            'operators' => array_keys(Conditions::OPERATORS), 'actions' => array_keys(ActionCatalog::ACTIONS),
            'shape' => '{"nodes":[{"key":"start","type":"start","name":"..","config":{}}],"edges":[{"from":"start","to":"..","outcome":"approved|rejected|returned|completed","condition":{"field":"amount","operator":"gt","value":50000},"is_default":true}]}',
        ];
        $json = $this->tryJson("Draft a workflow graph for this process. Return JSON with keys nodes and edges only.\n\nProcess: {$description}", $context, 'draft');
        $notes = [];
        $source = 'ai';
        if (! $json || empty($json['nodes'])) {
            [$json, $notes] = $this->heuristicDraft($description);
            $source = 'rules';
        }
        [$graph, $more] = $this->sanitise($json, $roles);

        return ['graph' => $graph, 'notes' => array_merge($notes, $more), 'source' => $source];
    }

    /**
     * Keeps only what exists: unknown node types dropped, unknown roles and every named person cleared
     * (a designer chooses them), unknown actions removed, conditions checked, keys made unique.
     *
     * @return array{0: array, 1: list<string>}
     */
    public function sanitise(array $json, array $roles): array
    {
        $notes = [];
        $nodes = [];
        $keys = [];
        $x = 60;
        foreach ((array) ($json['nodes'] ?? []) as $n) {
            $type = (string) ($n['type'] ?? '');
            if (! isset(NodeTypes::TYPES[$type])) {
                $notes[] = "Left out a step of unknown type \"{$type}\".";

                continue;
            }
            $key = Str::slug((string) ($n['key'] ?? $type), '_') ?: $type;
            while (isset($keys[$key])) {
                $key .= '_' . count($keys);
            }
            $keys[$key] = (string) ($n['key'] ?? $key);
            $cfg = is_array($n['config'] ?? null) ? $n['config'] : [];
            $clean = [];
            if (isset($cfg['assignee']['type']) && isset(NodeTypes::ASSIGNEE_TYPES[$cfg['assignee']['type']])) {
                $a = ['type' => $cfg['assignee']['type']];
                if ($a['type'] === 'role') {
                    $a['value'] = in_array($cfg['assignee']['value'] ?? null, $roles, true) ? $cfg['assignee']['value'] : null;
                    if (! $a['value']) {
                        $notes[] = 'Choose the role for "' . ($n['name'] ?? $key) . '": the suggested one does not exist.';
                    }
                } elseif ($a['type'] === 'user') {
                    $a['value'] = null; // never trust a person named by the model
                    $notes[] = 'Choose who "' . ($n['name'] ?? $key) . '" goes to.';
                } elseif (in_array($a['type'], ['field', 'department_head', 'department_members'], true)) {
                    $a['value'] = null;
                }
                $clean['assignee'] = $a;
            } elseif (in_array($type, NodeTypes::HUMAN, true)) {
                $clean['assignee'] = ['type' => 'line_manager'];
                $notes[] = 'Check who "' . ($n['name'] ?? $key) . '" goes to (set to the line manager).';
            }
            if ($type === 'approval') {
                $clean['mode'] = in_array($cfg['mode'] ?? 'any', ['any', 'all', 'quorum'], true) ? ($cfg['mode'] ?? 'any') : 'any';
                if (! empty($cfg['allow_return'])) {
                    $clean['allow_return'] = true;
                }
            }
            if ($type === 'end') {
                $clean['outcome'] = in_array($cfg['outcome'] ?? 'completed', ['completed', 'rejected', 'cancelled'], true) ? ($cfg['outcome'] ?? 'completed') : 'completed';
            }
            if ($type === 'action') {
                if (! ActionCatalog::exists((string) ($cfg['action'] ?? ''))) {
                    $notes[] = 'Left out an automated action that is not available.';

                    continue;
                }
                $clean['action'] = $cfg['action'];
                $clean['input'] = array_intersect_key((array) ($cfg['input'] ?? []), array_flip(array_column(ActionCatalog::ACTIONS[$cfg['action']]['inputs'], 'key')));
            }
            if ($type === 'notification') {
                $clean['to'] = ['type' => 'requester'];
                $clean['message'] = mb_substr((string) ($cfg['message'] ?? ($n['name'] ?? 'Update')), 0, 500);
            }
            if ($type === 'delay') {
                $clean['hours'] = max(1, (float) ($cfg['hours'] ?? 24));
            }
            if (! empty($cfg['sla']['hours']) && is_numeric($cfg['sla']['hours'])) {
                $clean['sla'] = ['hours' => (float) $cfg['sla']['hours'], 'business_hours' => true, 'escalation' => 'notify_manager'];
            }
            $nodes[] = ['key' => $key, 'type' => $type, 'name' => mb_substr((string) ($n['name'] ?? NodeTypes::TYPES[$type]['label']), 0, 120), 'x' => $x, 'y' => 200, 'config' => $clean];
            $x += 220;
        }
        $map = array_flip($keys);
        $edges = [];
        foreach ((array) ($json['edges'] ?? []) as $i => $e) {
            $from = $map[$e['from'] ?? ''] ?? null;
            $to = $map[$e['to'] ?? ''] ?? null;
            if (! $from || ! $to) {
                continue;
            }
            $edge = ['key' => 'e' . ($i + 1), 'from' => $from, 'to' => $to];
            if (! empty($e['outcome']) && is_string($e['outcome'])) {
                $edge['outcome'] = $e['outcome'];
            }
            if (! empty($e['condition']) && is_array($e['condition']) && ! Conditions::problems($e['condition'])) {
                $edge['condition'] = $e['condition'];
            }
            if (! empty($e['is_default'])) {
                $edge['is_default'] = true;
            }
            $edges[] = $edge;
        }
        if (! collect($nodes)->contains('type', 'start') || ! collect($nodes)->contains('type', 'end')) {
            $notes[] = 'The draft was incomplete, so a minimal start-to-end workflow was used instead.';

            return [DefinitionService::emptyGraph(), $notes];
        }

        return [['nodes' => $nodes, 'edges' => $edges], array_values(array_unique($notes))];
    }

    /**
     * A workflow from the description without AI: an amount threshold ("above R50 000") becomes a
     * decision, and the approvers mentioned (manager, finance, HR, director…) become approvals in order.
     *
     * @return array{0: array, 1: list<string>}
     */
    private function heuristicDraft(string $text): array
    {
        $t = mb_strtolower($text);
        $known = ['manager' => ['line_manager', null, 'Manager approval'], 'finance' => ['role', 'Finance', 'Finance approval'], 'hr' => ['role', 'HR', 'HR approval'],
            'director' => ['role', 'Director', 'Director approval'], 'procurement' => ['role', 'Procurement', 'Procurement approval'], 'legal' => ['role', 'Legal', 'Legal review']];
        $found = [];
        foreach ($known as $word => $spec) {
            if (preg_match('/\b' . $word . '\b/', $t, $m, PREG_OFFSET_CAPTURE)) {
                $found[$m[0][1]] = [$word, $spec];
            }
        }
        ksort($found);
        $approvals = array_values($found) ?: [['manager', $known['manager']]];
        $threshold = null;
        if (preg_match('/(?:above|over|exceeds?|more than|greater than)\s*r?\s*([\d][\d\s,\.]*)(k|m)?/u', $t, $m)) {
            $threshold = (float) str_replace([' ', ','], '', $m[1]) * (($m[2] ?? '') === 'k' ? 1000 : (($m[2] ?? '') === 'm' ? 1000000 : 1));
        }
        $nodes = [['key' => 'start', 'type' => 'start', 'name' => 'Request submitted', 'config' => []]];
        $edges = [];
        $prev = 'start';
        if ($threshold && count($approvals) > 1) {
            // Above the threshold every approver; at or below it only the first.
            $nodes[] = ['key' => 'threshold', 'type' => 'decision', 'name' => 'Above R' . number_format($threshold, 0, '.', ' ') . '?', 'config' => []];
            $edges[] = ['from' => 'start', 'to' => 'threshold'];
        }
        foreach ($approvals as $i => [$word, [$type, $role, $name]]) {
            $nodes[] = ['key' => $word, 'type' => 'approval', 'name' => $name, 'config' => ['assignee' => array_filter(['type' => $type, 'value' => $role])]];
            if ($i === 0) {
                $edges[] = ['from' => $threshold && count($approvals) > 1 ? 'threshold' : 'start', 'to' => $word] + ($threshold && count($approvals) > 1 ? ['condition' => ['field' => 'amount', 'operator' => 'gt', 'value' => $threshold]] : []);
            } else {
                $edges[] = ['from' => $prev, 'to' => $word, 'outcome' => 'approved'];
            }
            $edges[] = ['from' => $word, 'to' => 'rejected', 'outcome' => 'rejected'];
            $prev = $word;
        }
        $nodes[] = ['key' => 'approved', 'type' => 'end', 'name' => 'Approved', 'config' => ['outcome' => 'completed']];
        $nodes[] = ['key' => 'rejected', 'type' => 'end', 'name' => 'Rejected', 'config' => ['outcome' => 'rejected']];
        $edges[] = ['from' => $prev, 'to' => 'approved', 'outcome' => 'approved'];
        $notes = ['Drafted from the description without AI. Check each step.'];
        if ($threshold && count($approvals) > 1) {
            // Below the threshold: the first approver only.
            $nodes[] = ['key' => 'small', 'type' => 'approval', 'name' => $approvals[0][1][2] . ' (up to the threshold)', 'config' => ['assignee' => array_filter(['type' => $approvals[0][1][0], 'value' => $approvals[0][1][1]])]];
            $edges[] = ['from' => 'threshold', 'to' => 'small', 'is_default' => true];
            $edges[] = ['from' => 'small', 'to' => 'approved', 'outcome' => 'approved'];
            $edges[] = ['from' => 'small', 'to' => 'rejected', 'outcome' => 'rejected'];
            $notes[] = 'The amount is read from a form field called "amount"; add it to the start form.';
        }

        return [['nodes' => $nodes, 'edges' => $edges], $notes];
    }

    // ── Explanation ─────────────────────────────────────────────────────────

    public function explain(WorkflowVersion $version): array
    {
        $plain = $this->describe($version->graph);
        $ai = $this->tryText("Explain this workflow to a business user in a few short paragraphs: who does what, the branches, and what happens on rejection or failure.\n\n" . $plain, [], 'explain');

        return ['text' => $ai ?? $plain, 'source' => $ai ? 'ai' : 'rules'];
    }

    /** A plain-language walk through the graph, written without AI. */
    public function describe(array $graph): string
    {
        $nodes = collect($graph['nodes'] ?? [])->keyBy('key');
        $out = collect($graph['edges'] ?? [])->groupBy('from');
        $lines = [];
        foreach ($nodes as $key => $n) {
            $cfg = $n['config'] ?? [];
            $who = isset($cfg['assignee']) ? (NodeTypes::ASSIGNEE_TYPES[$cfg['assignee']['type']] ?? 'someone') . (isset($cfg['assignee']['value']) && is_string($cfg['assignee']['value']) ? " ({$cfg['assignee']['value']})" : '') : null;
            $line = match ($n['type']) {
                'start' => "It starts when \"{$n['name']}\" happens.",
                'end' => "\"{$n['name']}\" ends the request as " . ($cfg['outcome'] ?? 'completed') . '.',
                'approval' => "\"{$n['name']}\": {$who} " . (($cfg['mode'] ?? 'any') === 'all' ? 'must all approve' : (($cfg['mode'] ?? 'any') === 'quorum' ? 'approve by a quorum of ' . ($cfg['quorum'] ?? '?') : 'approve')) . (isset($cfg['sla']['hours']) ? " within {$cfg['sla']['hours']} hours" : '') . '.',
                'user_task', 'review' => "\"{$n['name']}\" is done by {$who}" . (isset($cfg['sla']['hours']) ? " within {$cfg['sla']['hours']} hours" : '') . '.',
                'decision' => "\"{$n['name']}\" chooses the route: " . ($out[$key] ?? collect())->map(fn ($e) => (! empty($e['is_default']) ? 'otherwise' : 'if ' . Conditions::describe($e['condition'] ?? null)) . ' → "' . ($nodes[$e['to']]['name'] ?? $e['to']) . '"')->implode('; ') . '.',
                'parallel' => "\"{$n['name']}\" runs " . count($out[$key] ?? []) . ' branches at the same time.',
                'join' => "\"{$n['name']}\" waits for " . (($cfg['mode'] ?? 'all') === 'all' ? 'every branch' : (($cfg['mode'] ?? 'all') === 'any' ? 'the first branch' : ($cfg['quorum'] ?? '?') . ' branches')) . '.',
                'delay' => "\"{$n['name']}\" waits " . (isset($cfg['hours']) ? "{$cfg['hours']} hours" : 'until ' . ($cfg['until_field'] ?? 'a date')) . '.',
                'notification' => "\"{$n['name']}\" notifies " . (NodeTypes::ASSIGNEE_TYPES[$cfg['to']['type'] ?? ''] ?? 'someone') . '.',
                'action' => "\"{$n['name']}\" automatically does: " . (ActionCatalog::ACTIONS[$cfg['action'] ?? '']['label'] ?? 'an action') . ' (retried if it fails).',
                default => "\"{$n['name']}\" (" . (NodeTypes::TYPES[$n['type']]['label'] ?? $n['type']) . ').',
            };
            if (in_array($n['type'], ['approval', 'user_task', 'review', 'subworkflow', 'exception'], true)) {
                $routes = ($out[$key] ?? collect())->map(fn ($e) => ($e['outcome'] ?? 'then') . ' → "' . ($nodes[$e['to']]['name'] ?? $e['to']) . '"')->implode(', ');
                $line .= $routes ? " Then: {$routes}." : '';
            }
            $lines[] = $line;
        }
        $lines[] = 'If a step cannot find anyone to assign, or an automated step keeps failing, the request is raised to the process owner or the workflow managers.';

        return implode("\n", $lines);
    }

    // ── Task summary ────────────────────────────────────────────────────────

    public function summarise(WorkflowTask $task): array
    {
        $instance = WorkflowInstance::query()->with('definition')->findOrFail($task->instance_id);
        $present = app(Presenter::class);
        $history = WorkflowTask::query()->where('instance_id', $instance->id)->whereNotNull('decision')->orderBy('completed_at')->get()
            ->map(fn ($t) => "{$t->name}: " . str_replace('_', ' ', $t->decision) . ' by ' . $present->name($t->decided_by) . ($t->comment ? " — {$t->comment}" : ''))->all();
        $comments = WorkflowComment::query()->where('instance_id', $instance->id)->orderBy('id')->limit(30)->get()
            ->map(fn ($c) => ($c->user_id ? $present->name($c->user_id) : 'Workflow') . ': ' . mb_substr($c->body, 0, 400))->all();
        $settings = app(WorkflowSettings::class);
        $data = [];
        if ($settings->get('ai_may_read_data') && ! $instance->definition->is_confidential) {
            $sensitive = app(WorkflowRunner::class)->sensitiveFields($instance);
            $data = collect($instance->data)->except(array_merge($sensitive, ['requester']))->all();
        }
        $plain = "{$instance->reference} — {$instance->title}. Requested by " . $present->name($instance->requester_id) . '.'
            . ($history ? "\nSo far: " . implode('; ', $history) . '.' : "\nNo decisions yet.")
            . ($comments ? "\nLatest comment: " . end($comments) : '');
        $ai = $data || $comments ? $this->tryText("Summarise this request for the next person who must act on \"{$task->name}\". Three or four sentences; mention anything unusual.",
            ['request' => $instance->title, 'history' => $history, 'comments' => $comments, 'data' => $data], 'summarize') : null;
        app(WorkflowAudit::class)->as('ai', 'Workflow AI', fn () => app(WorkflowAudit::class)->record('ai.summary', $task, ['instance' => $instance, 'context' => ['source' => $ai ? 'ai' : 'rules', 'data_shared' => (bool) $data]]));

        return ['text' => $ai ?? $plain, 'source' => $ai ? 'ai' : 'rules'];
    }

    // ── Form suggestions ────────────────────────────────────────────────────

    public function suggestFields(string $purpose): array
    {
        $json = $this->tryJson("Suggest form fields for this request form. Return JSON {\"fields\":[{\"key\":\"snake_case\",\"label\":\"..\",\"type\":\"..\",\"required\":true,\"options\":[]}]}.\n\nForm: {$purpose}",
            ['types' => array_keys(FormService::TYPES)], 'form');
        $fields = [];
        foreach ((array) ($json['fields'] ?? []) as $f) {
            $f = array_intersect_key((array) $f, array_flip(['key', 'label', 'type', 'required', 'options', 'help']));
            $f['key'] = Str::slug((string) ($f['key'] ?? $f['label'] ?? ''), '_');
            if ($f['key'] && isset(FormService::TYPES[$f['type'] ?? '']) && ! app(FormService::class)->schemaProblems([$f])) {
                $fields[] = $f;
            }
        }
        if (! $fields) {
            $fields = [
                ['key' => 'summary', 'label' => 'What do you need?', 'type' => 'text', 'required' => true],
                ['key' => 'details', 'label' => 'Details', 'type' => 'textarea', 'required' => true],
                ['key' => 'needed_by', 'label' => 'Needed by', 'type' => 'date'],
                ['key' => 'supporting_documents', 'label' => 'Supporting documents', 'type' => 'attachment'],
            ];
            if (preg_match('/cost|amount|purchase|invoice|claim|budget|spend|price/i', $purpose)) {
                array_splice($fields, 2, 0, [['key' => 'amount', 'label' => 'Amount', 'type' => 'currency', 'required' => true]]);
            }
        }

        return ['fields' => $fields, 'source' => $json ? 'ai' : 'rules'];
    }

    // ── Natural-language reporting ──────────────────────────────────────────

    /**
     * Answers a reporting question with figures computed from permission-filtered data: AI (or a
     * keyword parser) only chooses which report and period; the numbers come from ReportService.
     */
    public function answer(\App\Core\User\Models\User $user, string $question): array
    {
        $spec = $this->tryJson("Map this question to a report. Return JSON {\"report\":\"summary|bottlenecks|workflows|workload\",\"days\":30,\"workflow_name\":null}.\n\nQuestion: {$question}", [], 'report');
        $q = mb_strtolower($question);
        $report = $spec['report'] ?? (str_contains($q, 'breach') || str_contains($q, 'delay') || str_contains($q, 'slow') || str_contains($q, 'bottleneck') ? 'bottlenecks'
            : (str_contains($q, 'workload') || str_contains($q, 'who') ? 'workload' : (str_contains($q, 'workflow') || str_contains($q, 'process') ? 'workflows' : 'summary')));
        $report = in_array($report, ['summary', 'bottlenecks', 'workflows', 'workload'], true) ? $report : 'summary';
        $days = (int) ($spec['days'] ?? (str_contains($q, 'last month') ? 31 : (str_contains($q, 'week') ? 7 : (str_contains($q, 'quarter') ? 92 : (str_contains($q, 'year') ? 365 : 30)))));
        $filters = ['from' => now()->subDays(max(1, min(3650, $days)))->toDateString(), 'to' => now()->toDateString()];
        $name = $spec['workflow_name'] ?? null;
        if (! $name) {
            foreach (WorkflowDefinition::query()->pluck('name', 'uuid') as $uuid => $n) {
                if (str_contains($q, mb_strtolower($n)) || ($n && str_contains($q, mb_strtolower(Str::before($n, ' '))) && mb_strlen(Str::before($n, ' ')) > 4)) {
                    $filters['workflow'] = $uuid;
                    $name = $n;
                    break;
                }
            }
        }
        $reports = app(ReportService::class);
        $rows = match ($report) {
            'bottlenecks' => $reports->bottlenecks($user, $filters, 10),
            'workflows' => $reports->byWorkflow($user, $filters),
            'workload' => $reports->workload($user, $filters),
            default => [$reports->summary($user, $filters)],
        };
        app(WorkflowAudit::class)->as('ai', 'Workflow AI', fn () => app(WorkflowAudit::class)->record('ai.report', 'WorkflowReport', ['context' => ['question' => mb_substr($question, 0, 300), 'report' => $report] + $filters]));

        return ['report' => $report, 'filters' => $filters, 'workflow' => $name, 'rows' => $rows, 'source' => $spec ? 'ai' : 'rules'];
    }

    /** Steps worth automating: consistently fast, rarely returned, high volume (§15 recommendations). */
    public function recommendations(\App\Core\User\Models\User $user): array
    {
        $rows = app(ReportService::class)->bottlenecks($user, ['from' => now()->subDays(180)->toDateString(), 'to' => now()->toDateString()], 100);
        $out = [];
        foreach ($rows as $r) {
            if ((int) $r['tasks'] >= 20 && (float) $r['median_hours'] < 1 && (int) $r['returned'] === 0) {
                $out[] = ['workflow' => $r['workflow'], 'step' => $r['step'], 'why' => "{$r['tasks']} tasks, median {$r['median_hours']} h, never returned: a candidate for a rule-based decision or an automated action."];
            } elseif ((int) $r['tasks'] >= 10 && (int) $r['breaches'] / max(1, (int) $r['tasks']) > 0.3) {
                $out[] = ['workflow' => $r['workflow'], 'step' => $r['step'], 'why' => round(100 * $r['breaches'] / $r['tasks']) . '% of these tasks breach their SLA: review the assignee, the target or add a backup approver.'];
            }
        }

        return $out;
    }
}
