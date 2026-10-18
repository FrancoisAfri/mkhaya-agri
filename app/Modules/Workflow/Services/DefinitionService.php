<?php

namespace App\Modules\Workflow\Services;

use App\Core\User\Models\User;
use App\Modules\Workflow\Exceptions\WorkflowException;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowForm;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowNodeRun;
use App\Modules\Workflow\Models\WorkflowVersion;
use App\Modules\Workflow\Support\TemplateLibrary;
use App\Modules\Workflow\Support\WorkflowSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The life of a workflow definition (PRS §4, §16): draft → (review) → published → suspended /
 * retired, with every published version immutable. Editing a published workflow makes a new draft
 * version; publishing it supersedes the old one for new requests only. Running requests stay on the
 * version they started with unless an administrator migrates them explicitly.
 */
class DefinitionService
{
    public function __construct(
        private readonly GraphValidator $validator,
        private readonly WorkflowAudit $audit,
        private readonly WorkflowSettings $settings,
        private readonly FormService $forms,
    ) {
    }

    public static function emptyGraph(): array
    {
        return ['nodes' => [
            ['key' => 'start', 'type' => 'start', 'name' => 'Start', 'x' => 80, 'y' => 200, 'config' => []],
            ['key' => 'end', 'type' => 'end', 'name' => 'Done', 'x' => 520, 'y' => 200, 'config' => ['outcome' => 'completed']],
        ], 'edges' => [['key' => 'e1', 'from' => 'start', 'to' => 'end']]];
    }

    public function create(array $attrs, ?array $graph = null, string $origin = 'manual'): WorkflowDefinition
    {
        return DB::transaction(function () use ($attrs, $graph, $origin) {
            $key = Str::slug($attrs['key'] ?? $attrs['name'], '_');
            if (WorkflowDefinition::query()->where('key', $key)->exists()) {
                $key .= '_' . Str::lower(Str::random(4));
            }
            $definition = WorkflowDefinition::create(array_merge(array_intersect_key($attrs, array_flip([
                'name', 'description', 'category', 'tags', 'module', 'process_owner_id', 'departments', 'trigger_type', 'trigger_config',
                'start_roles', 'default_priority', 'calendar_id', 'is_confidential', 'restricted_user_ids', 'retention_days',
            ])), ['key' => $key, 'status' => 'draft', 'created_by' => auth()->id(), 'module' => $attrs['module'] ?? 'generic']));
            $definition->subject_type = $definition->module === 'generic' ? null : $definition->module;
            $definition->save();
            WorkflowVersion::create(['company_id' => $definition->company_id, 'definition_id' => $definition->id, 'version' => 1, 'status' => 'draft',
                'graph' => $graph ?? self::emptyGraph(), 'created_by' => auth()->id(), 'origin' => $origin]);
            $this->audit->record('definition.created', $definition, ['definition_id' => $definition->id, 'after' => ['name' => $definition->name, 'origin' => $origin]]);

            return $definition->refresh();
        });
    }

    public function updateProperties(WorkflowDefinition $definition, array $attrs, string $reason): WorkflowDefinition
    {
        $fields = ['name', 'description', 'category', 'tags', 'process_owner_id', 'departments', 'trigger_type', 'trigger_config', 'start_roles',
            'default_priority', 'calendar_id', 'is_confidential', 'restricted_user_ids', 'retention_days'];
        $before = $definition->only($fields);
        $definition->fill(array_intersect_key($attrs, array_flip($fields)))->save();
        $changed = array_keys(array_filter($fields, fn ($f) => ($before[$f] ?? null) != $definition->{$f}, ARRAY_FILTER_USE_BOTH));
        $this->audit->record('definition.updated', $definition, ['definition_id' => $definition->id, 'reason' => $reason,
            'before' => array_intersect_key($before, array_flip(array_map(fn ($i) => $fields[$i], array_keys($changed)))),
            'after' => $definition->only(array_map(fn ($i) => $fields[$i], array_keys($changed)))]);

        return $definition;
    }

    /** The editable version, creating a new draft from the published one if there is none. */
    public function workingVersion(WorkflowDefinition $definition): WorkflowVersion
    {
        $working = $definition->workingVersion();
        if ($working) {
            return $working;
        }
        $from = $definition->currentVersion ?? $definition->versions()->orderByDesc('version')->first();

        return WorkflowVersion::create(['company_id' => $definition->company_id, 'definition_id' => $definition->id,
            'version' => (int) $definition->versions()->max('version') + 1, 'status' => 'draft',
            'graph' => $from?->graph ?? self::emptyGraph(), 'created_by' => auth()->id(), 'origin' => 'manual']);
    }

    public function saveDraft(WorkflowDefinition $definition, array $graph, ?string $summary = null): WorkflowVersion
    {
        $this->assertShape($graph);

        return DB::transaction(function () use ($definition, $graph, $summary) {
            $version = $this->workingVersion($definition);
            if ($version->status === 'in_review') {
                throw new WorkflowException('This version is waiting for review. Withdraw it before editing.');
            }
            $version->forceFill(['graph' => self::normalise($graph), 'status' => 'draft', 'change_summary' => $summary ?? $version->change_summary,
                'validation' => $this->validator->validate($graph, $definition)])->save();
            $this->audit->record('version.saved', $version, ['definition_id' => $definition->id, 'version' => $version->version,
                'after' => ['nodes' => count($graph['nodes']), 'edges' => count($graph['edges']), 'problems' => count($version->validation)]]);

            return $version;
        });
    }

    /** Submit the working version: published at once, or sent for review when the company requires it. */
    public function submit(WorkflowDefinition $definition, User $user, string $summary): WorkflowVersion
    {
        // Validation is saved and a failure audited outside the transaction, so both survive the refusal.
        $version = $this->workingVersion($definition);
        if ($version->status === 'in_review') {
            throw new WorkflowException('This version is already waiting for review.');
        }
        $problems = $this->validator->validate($version->graph, $definition);
        $version->validation = $problems;
        $version->save();
        if ($problems) {
            $this->audit->failed('version.submit', $version, count($problems) . ' validation problem(s).', ['definition_id' => $definition->id, 'version' => $version->version]);
            throw new WorkflowException('The workflow has ' . count($problems) . ' problem(s) to fix before it can be published. First: ' . $problems[0]['message']);
        }

        return DB::transaction(function () use ($definition, $user, $summary, $version) {
            $version->forceFill(['change_summary' => $summary, 'submitted_by' => $user->id, 'submitted_at' => now()])->save();
            if ($this->settings->get('publication_requires_review', $definition->company_id)) {
                $version->status = 'in_review';
                $version->save();
                if ($definition->status === 'draft') {
                    $definition->update(['status' => 'in_review']);
                }
                $this->audit->record('version.submitted', $version, ['definition_id' => $definition->id, 'version' => $version->version, 'reason' => $summary]);

                return $version;
            }

            return $this->publish($definition, $version, $user, $summary);
        });
    }

    public function withdraw(WorkflowDefinition $definition, User $user): WorkflowVersion
    {
        $version = $definition->versions()->where('status', 'in_review')->firstOrFail();
        $version->forceFill(['status' => 'draft'])->save();
        if ($definition->status === 'in_review') {
            $definition->update(['status' => 'draft']);
        }
        $this->audit->record('version.withdrawn', $version, ['definition_id' => $definition->id, 'version' => $version->version]);

        return $version;
    }

    /** §16: peer review. The reviewer is never the person who drafted or submitted it. */
    public function review(WorkflowDefinition $definition, User $reviewer, bool $approve, string $comment): WorkflowVersion
    {
        try {
            return $this->reviewIn($definition, $reviewer, $approve, $comment);
        } catch (\App\Modules\Workflow\Exceptions\WorkflowDenied $e) {
            $e->audit();
            throw $e;
        }
    }

    private function reviewIn(WorkflowDefinition $definition, User $reviewer, bool $approve, string $comment): WorkflowVersion
    {
        return DB::transaction(function () use ($definition, $reviewer, $approve, $comment) {
            $version = $definition->versions()->where('status', 'in_review')->lockForUpdate()->first();
            if (! $version) {
                throw new WorkflowException('No version is waiting for review.');
            }
            if (in_array($reviewer->id, [$version->submitted_by, $version->created_by], true)) {
                throw new \App\Modules\Workflow\Exceptions\WorkflowDenied('Someone other than the person who drafted or submitted this version must review it.',
                    'version.review', $version, 'The reviewer drafted or submitted this version.', ['definition_id' => $definition->id, 'version' => $version->version]);
            }
            $version->forceFill(['reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_comment' => $comment])->save();
            if (! $approve) {
                $version->forceFill(['status' => 'rejected'])->save();
                if ($definition->status === 'in_review') {
                    $definition->update(['status' => 'draft']);
                }
                $this->audit->record('version.rejected', $version, ['definition_id' => $definition->id, 'version' => $version->version, 'reason' => $comment]);

                return $version;
            }

            return $this->publish($definition, $version, $reviewer, $comment);
        });
    }

    private function publish(WorkflowDefinition $definition, WorkflowVersion $version, User $by, string $note): WorkflowVersion
    {
        // Pin the forms the version uses, so later edits to a form do not change it (§6).
        $pinned = [];
        foreach ($version->graph['nodes'] as $n) {
            if (! empty($n['config']['form_id'])) {
                $form = WorkflowForm::query()->find($n['config']['form_id']);
                if (! $form?->current_version_id) {
                    throw new WorkflowException("A form used by \"{$n['name']}\" has no saved version.");
                }
                $pinned[$form->id] = $form->current_version_id;
            }
        }
        $previous = $definition->current_version_id;
        WorkflowVersion::query()->where('definition_id', $definition->id)->where('status', 'published')->update(['status' => 'superseded']);
        $version->forceFill(['status' => 'published', 'pinned_forms' => $pinned, 'published_by' => $by->id, 'published_at' => now(), 'effective_from' => $version->effective_from ?? today()])->save();
        $definition->forceFill(['current_version_id' => $version->id, 'status' => in_array($definition->status, ['suspended'], true) ? 'suspended' : 'published'])->save();
        $this->audit->record('version.published', $version, ['definition_id' => $definition->id, 'version' => $version->version, 'reason' => $note,
            'before' => ['version_id' => $previous], 'after' => ['version_id' => $version->id, 'forms' => $pinned,
                'running_on_previous' => $previous ? WorkflowInstance::query()->where('version_id', $previous)->whereIn('status', WorkflowInstance::OPEN)->count() : 0]]);

        return $version;
    }

    public function setStatus(WorkflowDefinition $definition, string $status, string $reason): void
    {
        $allowed = ['suspend' => ['published'], 'resume' => ['suspended'], 'retire' => ['published', 'suspended', 'draft', 'in_review']];
        $to = ['suspend' => 'suspended', 'resume' => 'published', 'retire' => 'retired'][$status] ?? null;
        if (! $to || ! in_array($definition->status, $allowed[$status], true)) {
            throw new WorkflowException("A {$definition->status} workflow cannot be {$status}d.");
        }
        if ($status === 'resume' && ! $definition->current_version_id) {
            throw new WorkflowException('It has no published version to resume.');
        }
        $before = $definition->status;
        $definition->update(['status' => $to]);
        if ($to === 'retired') {
            $definition->versions()->whereIn('status', ['published'])->update(['status' => 'retired']);
        }
        $this->audit->record('definition.' . $status, $definition, ['definition_id' => $definition->id, 'reason' => $reason, 'before' => ['status' => $before], 'after' => ['status' => $to]]);
    }

    /** §16: roll back for future requests by publishing a copy of an earlier version. */
    public function rollback(WorkflowDefinition $definition, WorkflowVersion $to, User $user, string $reason): WorkflowVersion
    {
        if ($to->definition_id !== $definition->id || ! in_array($to->status, ['superseded', 'published', 'retired'], true)) {
            throw new WorkflowException('Only an earlier published version can be rolled back to.');
        }
        if ($definition->workingVersion()) {
            throw new WorkflowException('Discard or publish the current draft first.');
        }

        return DB::transaction(function () use ($definition, $to, $user, $reason) {
            $copy = WorkflowVersion::create(['company_id' => $definition->company_id, 'definition_id' => $definition->id,
                'version' => (int) $definition->versions()->max('version') + 1, 'status' => 'draft', 'graph' => $to->graph,
                'created_by' => $user->id, 'origin' => 'rollback', 'change_summary' => "Rollback to version {$to->version}: {$reason}"]);
            $this->audit->record('version.rollback', $copy, ['definition_id' => $definition->id, 'version' => $copy->version, 'reason' => $reason, 'context' => ['from_version' => $to->version]]);

            return $this->submit($definition, $user, "Rollback to version {$to->version}: {$reason}");
        });
    }

    public function discardDraft(WorkflowDefinition $definition): void
    {
        $working = $definition->workingVersion();
        if (! $working || $working->status === 'in_review') {
            throw new WorkflowException('There is no draft to discard.');
        }
        if (! $definition->current_version_id && $definition->versions()->count() === 1) {
            throw new WorkflowException('This is the only version; retire the workflow instead.');
        }
        $this->audit->record('version.discarded', $working, ['definition_id' => $definition->id, 'version' => $working->version]);
        $working->delete();
    }

    /** §16: what changed between two versions, step by step. */
    public function compare(WorkflowVersion $a, WorkflowVersion $b): array
    {
        $index = fn ($v, $k) => collect($v->graph[$k] ?? [])->keyBy('key');
        $na = $index($a, 'nodes');
        $nb = $index($b, 'nodes');
        $ea = $index($a, 'edges');
        $eb = $index($b, 'edges');
        $strip = fn ($n) => array_diff_key($n, ['x' => 1, 'y' => 1]);

        return [
            'nodes_added' => $nb->diffKeys($na)->map(fn ($n) => $n['name'])->values(),
            'nodes_removed' => $na->diffKeys($nb)->map(fn ($n) => $n['name'])->values(),
            'nodes_changed' => $nb->intersectByKeys($na)->filter(fn ($n, $k) => $strip($n) != $strip($na[$k]))->map(fn ($n, $k) => [
                'name' => $n['name'], 'changes' => collect(array_keys(array_merge($n['config'] ?? [], $na[$k]['config'] ?? [])))
                    ->filter(fn ($c) => ($n['config'][$c] ?? null) != ($na[$k]['config'][$c] ?? null))->values()
                    ->merge(($n['name'] !== $na[$k]['name']) ? ['name'] : [])->merge(($n['type'] !== $na[$k]['type']) ? ['type'] : []),
            ])->values(),
            'edges_added' => $eb->diffKeys($ea)->count(),
            'edges_removed' => $ea->diffKeys($eb)->count(),
            'edges_changed' => $eb->intersectByKeys($ea)->filter(fn ($e, $k) => $e != $ea[$k])->count(),
        ];
    }

    /**
     * §16: move chosen running requests to the published version. Only possible when every step a
     * request is waiting at exists, with the same type, in the target; completed approvals, data and
     * history are kept as they are.
     */
    public function migrate(WorkflowDefinition $definition, array $instanceUuids, User $by, string $reason): array
    {
        $target = $definition->currentVersion;
        if (! $target) {
            throw new WorkflowException('There is no published version to migrate to.');
        }
        $moved = [];
        $refused = [];
        DB::transaction(function () use ($definition, $instanceUuids, $by, $reason, $target, &$moved, &$refused) {
            foreach ($instanceUuids as $uuid) {
                $instance = WorkflowInstance::query()->where('uuid', $uuid)->where('definition_id', $definition->id)->lockForUpdate()->first();
                if (! $instance || ! $instance->isOpen() || $instance->version_id === $target->id) {
                    $refused[$uuid] = 'not a running request on an older version';

                    continue;
                }
                $waiting = WorkflowNodeRun::query()->where('instance_id', $instance->id)->whereIn('status', ['waiting', 'active'])->get();
                $missing = $waiting->filter(fn ($r) => ($target->node($r->node_key)['type'] ?? null) !== $r->node_type)->pluck('node_key')->all();
                if ($missing) {
                    $refused[$instance->reference] = 'waiting at step(s) the new version does not have: ' . implode(', ', $missing);

                    continue;
                }
                $from = $instance->version_id;
                $instance->forceFill(['version_id' => $target->id])->save();
                $this->audit->record('instance.migrated', $instance, ['instance' => $instance, 'reason' => $reason,
                    'before' => ['version_id' => $from], 'after' => ['version_id' => $target->id, 'version' => $target->version]]);
                $moved[] = $instance->reference;
            }
        });

        return compact('moved', 'refused');
    }

    /** §16: a portable definition (graph and the forms it uses). Credentials are never part of it. */
    public function export(WorkflowDefinition $definition): array
    {
        $version = $definition->workingVersion() ?? $definition->currentVersion;
        $formIds = collect($version->graph['nodes'] ?? [])->pluck('config.form_id')->filter()->unique();
        $forms = WorkflowForm::query()->whereIn('id', $formIds)->with('currentVersion')->get()
            ->map(fn ($f) => ['ref' => $f->id, 'name' => $f->name, 'description' => $f->description, 'fields' => $f->currentVersion?->fields ?? []]);
        $this->audit->record('definition.exported', $definition, ['definition_id' => $definition->id]);

        return [
            'format' => 'mkhaya-workflow/1',
            'definition' => $definition->only(['key', 'name', 'description', 'category', 'tags', 'module', 'trigger_type', 'trigger_config', 'default_priority', 'is_confidential']),
            'graph' => $this->portableGraph($version->graph),
            'forms' => $forms->values()->all(),
        ];
    }

    public function import(array $payload, string $origin = 'import'): WorkflowDefinition
    {
        if (($payload['format'] ?? null) !== 'mkhaya-workflow/1' || ! is_array($payload['graph'] ?? null) || ! is_array($payload['definition'] ?? null)) {
            throw new WorkflowException('This is not a Mkhaya workflow export.');
        }
        $this->assertShape($payload['graph']);

        return DB::transaction(function () use ($payload, $origin) {
            $map = [];
            foreach ($payload['forms'] ?? [] as $f) {
                $form = $this->forms->create((string) $f['name'], $f['description'] ?? null, (array) ($f['fields'] ?? []));
                $map[$f['ref']] = $form->id;
            }
            $graph = $payload['graph'];
            foreach ($graph['nodes'] as &$n) {
                // People and forms are company data: references are re-pointed or cleared, never trusted.
                if (! empty($n['config']['form_id'])) {
                    $n['config']['form_id'] = $map[$n['config']['form_id']] ?? null;
                }
                if (($n['config']['assignee']['type'] ?? null) === 'user') {
                    $n['config']['assignee']['value'] = null;
                }
            }
            unset($n);
            $attrs = $payload['definition'];
            if (! empty($attrs['category'])) {
                $this->settings->ensureCategories([(string) $attrs['category']], 'Category used by an imported workflow');
            }

            return $this->create(['name' => $attrs['name'] ?? 'Imported workflow'] + $attrs, $graph, $origin);
        });
    }

    public function installTemplate(string $key): WorkflowDefinition
    {
        $template = TemplateLibrary::get($key) ?? throw new WorkflowException('Unknown template.');

        return DB::transaction(function () use ($template) {
            $formIds = [];
            foreach ($template['forms'] ?? [] as $ref => $f) {
                $formIds[$ref] = $this->forms->create($f['name'], $f['description'] ?? null, $f['fields'])->id;
            }
            $graph = $template['graph'];
            foreach ($graph['nodes'] as &$n) {
                if (isset($n['config']['form'])) {
                    $n['config']['form_id'] = $formIds[$n['config']['form']] ?? null;
                    unset($n['config']['form']);
                }
            }
            unset($n);

            $this->settings->ensureCategories([$template['category']], "Category used by the \"{$template['name']}\" template");

            return $this->create(['name' => $template['name'], 'description' => $template['description'], 'category' => $template['category'],
                'module' => $template['module'] ?? 'generic', 'key' => $template['key'], 'process_owner_id' => auth()->id(),
                'trigger_type' => $template['trigger_type'] ?? 'manual', 'trigger_config' => $template['trigger_config'] ?? [],
                'is_confidential' => (bool) ($template['confidential'] ?? false)], $graph, 'template');
        });
    }

    private function portableGraph(array $graph): array
    {
        return ['nodes' => array_values($graph['nodes'] ?? []), 'edges' => array_values($graph['edges'] ?? [])];
    }

    /** Settings are maps: an empty one is stored as {} so clients never receive a list. */
    public static function normalise(array $graph): array
    {
        foreach ($graph['nodes'] as &$n) {
            $n['config'] = empty($n['config']) ? new \stdClass : $n['config'];
        }
        unset($n);

        return $graph;
    }

    /** Structural sanity before anything is stored (sizes, shapes); the validator explains the rest. */
    private function assertShape(array $graph): void
    {
        if (! isset($graph['nodes'], $graph['edges']) || ! is_array($graph['nodes']) || ! is_array($graph['edges'])) {
            throw new WorkflowException('The workflow must have steps and connections.');
        }
        if (count($graph['nodes']) > 500 || count($graph['edges']) > 1000 || strlen(json_encode($graph)) > 512000) {
            throw new WorkflowException('The workflow is too large.');
        }
        foreach ($graph['nodes'] as $n) {
            if (! is_array($n) || ! isset($n['key'], $n['type'])) {
                throw new WorkflowException('Every step needs a key and a type.');
            }
        }
        foreach ($graph['edges'] as $e) {
            if (! is_array($e) || ! isset($e['from'], $e['to'])) {
                throw new WorkflowException('Every connection needs a start and an end.');
            }
        }
    }
}
