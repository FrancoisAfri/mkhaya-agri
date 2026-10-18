<?php

namespace App\Modules\Workflow\Services;

use App\Core\User\Models\User;
use App\Modules\Workflow\Models\WorkflowFormVersion;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowNodeRun;
use App\Modules\Workflow\Models\WorkflowTask;
use App\Modules\Workflow\Support\NodeTypes;
use App\Modules\Workflow\Support\ProviderRegistry;
use App\Modules\Workflow\Support\WorkflowPermissions;

/** Everything a person allowed to open a request sees about it (the request and task screens). */
class InstanceView
{
    public function __construct(
        private readonly Presenter $present,
        private readonly ProviderRegistry $providers,
        private readonly WorkflowAccess $access,
    ) {
    }

    public function build(WorkflowInstance $instance, User $user): array
    {
        $instance->loadMissing(['definition', 'version']);
        $version = $instance->version;
        $runs = WorkflowNodeRun::query()->where('instance_id', $instance->id)->orderBy('id')->get();
        $tasks = WorkflowTask::query()->where('instance_id', $instance->id)->orderBy('id')->get();

        // The start form's labels, so values read as the requester entered them.
        $start = collect($version->graph['nodes'] ?? [])->firstWhere('type', 'start');
        $fields = [];
        if (! empty($start['config']['form_id']) && ($fv = $version->pinned_forms[$start['config']['form_id']] ?? null)) {
            $fields = WorkflowFormVersion::query()->find($fv)?->fields ?? [];
        }
        $data = $instance->data ?? [];
        $values = [];
        foreach ($fields as $f) {
            if (($f['type'] ?? '') === 'heading' || ! array_key_exists($f['key'], $data)) {
                continue;
            }
            $values[] = ['key' => $f['key'], 'label' => $f['label'] ?? $f['key'], 'type' => $f['type'], 'value' => $this->display($f, $data[$f['key']]), 'columns' => $f['columns'] ?? null, 'raw' => $data[$f['key']]];
        }
        // Values set later (task forms, actions) that the start form does not describe.
        foreach (array_diff_key($data, array_flip(array_merge(array_column($fields, 'key'), ['record', 'requester']))) as $k => $v) {
            $values[] = ['key' => $k, 'label' => ucfirst(str_replace('_', ' ', $k)), 'type' => is_array($v) ? 'json' : 'text', 'value' => is_array($v) ? json_encode($v) : (string) $v, 'columns' => null, 'raw' => $v];
        }

        $record = null;
        if ($instance->subject_type && ($p = $this->providers->get($instance->subject_type))) {
            $model = $instance->subject_id ? $p->find($instance->subject_id) : null;
            $labels = collect($p->fields())->pluck('label', 'key');
            $record = [
                'module' => $p->label(), 'reference' => $instance->subject_ref,
                'fields' => collect($data['record'] ?? [])->map(fn ($v, $k) => ['label' => $labels[$k] ?? $k, 'value' => is_bool($v) ? ($v ? 'Yes' : 'No') : (string) ($v ?? '—')])->values(),
                'url' => $model && $p->canView($user, $model) ? $p->url($model) : null,
            ];
        }

        $statusByNode = [];
        foreach ($runs as $r) {
            $statusByNode[$r->node_key] = $r->status === 'completed' ? ($r->outcome === 'completed' || $r->outcome === null ? 'done' : $r->outcome) : $r->status;
        }

        return [
            'instance' => $this->present->instance($instance) + ['version' => $version->version, 'subject_url' => $record['url'] ?? null],
            'values' => $values,
            'record' => $record,
            'graph' => ['nodes' => array_map(fn ($n) => ['key' => $n['key'], 'type' => $n['type'], 'name' => $n['name'] ?? $n['key'], 'x' => $n['x'] ?? 0, 'y' => $n['y'] ?? 0,
                'state' => $statusByNode[$n['key']] ?? null], $version->graph['nodes'] ?? []),
                'edges' => array_map(fn ($e) => ['key' => $e['key'] ?? null, 'from' => $e['from'], 'to' => $e['to'], 'label' => $e['label'] ?? ($e['outcome'] ?? (! empty($e['is_default']) ? 'otherwise' : null)),
                    'taken' => $runs->contains(fn ($r) => $r->via_edge !== null && $r->via_edge === ($e['key'] ?? '-'))], $version->graph['edges'] ?? [])],
            'timeline' => $runs->map(fn ($r) => ['node' => $version->node($r->node_key)['name'] ?? $r->node_key, 'type' => $r->node_type,
                'type_label' => NodeTypes::TYPES[$r->node_type]['label'] ?? $r->node_type, 'status' => $r->status, 'outcome' => $r->outcome, 'activation' => $r->activation,
                'started_at' => $r->started_at?->toIso8601String(), 'finished_at' => $r->finished_at?->toIso8601String(),
                'detail' => $this->runDetail($r)])->values(),
            'tasks' => $tasks->map(fn ($t) => $this->present->task($t))->values(),
            'comments' => $instance->comments()->orderBy('id')->get()->map(fn ($c) => ['id' => $c->id, 'by' => $c->user_id ? $this->present->name($c->user_id) : 'Workflow',
                'body' => $c->body, 'at' => $c->created_at?->toIso8601String()])->values(),
            'attachments' => $instance->libraryDocuments()->get(['documents.id', 'documents.uuid', 'documents.title', 'documents.created_at'])
                ->map(fn ($d) => ['id' => $d->id, 'title' => $d->title, 'added' => $d->created_at?->toDateString(), 'url' => route('document-management.documents.preview', $d)])->values(),
            'can' => [
                'cancel' => $instance->isOpen() && ($instance->requester_id === $user->id || $user->can(WorkflowPermissions::MANAGE)),
                'comment' => $instance->isOpen(),
                'respond' => $instance->requester_id === $user->id && $tasks->contains(fn ($t) => $t->status === 'open' && $t->decision === 'info_requested'),
                'attach' => $instance->isOpen() && $this->access->isParticipant($user, $instance),
            ],
        ];
    }

    private function display(array $f, mixed $v): string
    {
        return match ($f['type']) {
            'yesno', 'acknowledgement' => $v ? 'Yes' : 'No',
            'user' => (string) $this->present->name((int) $v),
            'users' => implode(', ', array_map(fn ($id) => (string) $this->present->name((int) $id), (array) $v)),
            'daterange' => is_array($v) ? implode(' to ', $v) : (string) $v,
            'multiselect', 'checkboxes' => implode(', ', (array) $v),
            'attachment' => count((array) $v) . ' file(s)',
            'line_items' => count((array) $v) . ' line(s)',
            'currency' => 'R ' . number_format((float) $v, 2, '.', ' '),
            default => is_array($v) ? json_encode($v) : (string) $v,
        };
    }

    private function runDetail(WorkflowNodeRun $r): ?string
    {
        $d = $r->detail ?? [];

        return match (true) {
            isset($d['exception']) => $d['exception'],
            isset($d['evaluated']) => collect($d['evaluated'])->map(fn ($e) => $e['condition'] . ' → ' . ($e['result'] ? 'yes' : 'no'))->implode('; ') . (! empty($d['default']) ? '; default route' : ''),
            isset($d['arrived']) => count($d['arrived']) . ' of ' . ($d['needed'] ?? '?') . ' branch(es) arrived',
            isset($d['until']) => 'Until ' . \Carbon\Carbon::parse($d['until'])->setTimezone('Africa/Johannesburg')->format('j M H:i'),
            isset($d['child']) => 'Sub-workflow ' . $d['child'],
            isset($d['excluded']) && $d['excluded'] => 'Excluded: ' . collect($d['excluded'])->map(fn ($x) => $this->present->name($x['user']) . ' (' . $x['why'] . ')')->implode(', '),
            default => null,
        };
    }
}
