<?php

namespace App\Modules\Workflow\Support;

use App\Modules\Workflow\Models\WorkflowSettingsRow;
use App\Modules\Workflow\Services\WorkflowAudit;
use Illuminate\Support\Facades\Auth;

/**
 * Each company's workflow policy (PRS §22). Defaults apply until a company changes them; every change
 * is audited with its reason. One company's policy never applies to another (§7).
 */
class WorkflowSettings
{
    /** key => [default, label, validation] */
    /** The starting list of workflow categories; each company edits its own under administration. */
    public const DEFAULT_CATEGORIES = ['Finance', 'Procurement', 'HR', 'IT', 'Legal', 'Operations', 'SHEQ', 'Warehouse', 'Governance', 'Sales', 'General'];

    public const KEYS = [
        'categories' => [self::DEFAULT_CATEGORIES, 'Workflow categories', 'array|min:1|max:60'],
        'publication_requires_review' => [true, 'A second person approves a version before it is published', 'boolean'],
        'self_approval' => ['deny', 'Self-approval', 'in:deny,node_decides'],
        'unassigned_policy' => ['process_owner', 'When no assignee can be found', 'in:process_owner,exception'],
        'deactivation_policy' => ['line_manager', "A deactivated person's open tasks go to", 'in:line_manager,process_owner,exception'],
        'default_sla_hours' => [null, 'Default task SLA (hours, empty for none)', 'nullable|integer|min:1|max:8760'],
        'permitted_actions' => [[], 'Automated actions designers may use (material actions must be listed)', 'array'],
        'modules' => [[], 'Modules that route their approvals through the engine', 'array'],
        'ai_enabled' => [true, 'AI assistance', 'boolean'],
        'ai_may_read_data' => [false, 'AI may read request data (summaries, reporting)', 'boolean'],
        'max_nodes' => [150, 'Most nodes in one workflow', 'integer|min:5|max:500'],
        'retention_days' => [null, 'Keep completed requests for (days, empty for indefinitely)', 'nullable|integer|min:30|max:36500'],
        'email_notifications' => [true, 'Email notifications as well as in-app', 'boolean'],
    ];

    /** @var array<int, array<string, mixed>> */
    private array $cache = [];

    public function all(?int $companyId = null): array
    {
        $companyId ??= $this->companyId();
        if ($companyId === null) {
            return array_map(fn ($d) => $d[0], self::KEYS);
        }

        return $this->cache[$companyId] ??= array_merge(
            array_map(fn ($d) => $d[0], self::KEYS),
            WorkflowSettingsRow::query()->withoutGlobalScopes()->where('company_id', $companyId)->value('values') ?? [],
        );
    }

    public function get(string $key, ?int $companyId = null): mixed
    {
        return $this->all($companyId)[$key] ?? (self::KEYS[$key][0] ?? null);
    }

    /** @return list<string> */
    public function categories(?int $companyId = null): array
    {
        return array_values(array_unique(array_filter(array_map('strval', (array) $this->get('categories', $companyId)))));
    }

    /** Add categories a template or import brings, so every workflow's category stays on the list. */
    public function ensureCategories(array $names, string $reason): void
    {
        $missing = array_values(array_diff(array_filter($names), $this->categories()));
        if ($missing) {
            $this->update(['categories' => array_merge($this->categories(), $missing)], $reason);
        }
    }

    public function moduleEnabled(string $provider, ?int $companyId = null): bool
    {
        return (bool) ($this->get('modules', $companyId)[$provider] ?? false);
    }

    /** Save changed values with the reason; returns what changed. */
    public function update(array $values, string $reason): array
    {
        $companyId = $this->companyId();
        $before = $this->all($companyId);
        $after = array_merge($before, array_intersect_key($values, self::KEYS));
        $changed = array_keys(array_filter(self::KEYS, fn ($d, $k) => ($before[$k] ?? null) !== ($after[$k] ?? null), ARRAY_FILTER_USE_BOTH));
        if (! $changed) {
            return [];
        }
        $row = WorkflowSettingsRow::query()->withoutGlobalScopes()->firstOrNew(['company_id' => $companyId]);
        $row->values = array_intersect_key($after, self::KEYS);
        $row->updated_by = Auth::id();
        $row->save();
        unset($this->cache[$companyId]);
        app(WorkflowAudit::class)->record('settings.updated', 'WorkflowSettings', ['company_id' => $companyId, 'reason' => $reason,
            'before' => array_intersect_key($before, array_flip($changed)), 'after' => array_intersect_key($after, array_flip($changed))]);

        return $changed;
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    private function companyId(): ?int
    {
        return app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id() ?? Auth::user()?->company_id;
    }
}
