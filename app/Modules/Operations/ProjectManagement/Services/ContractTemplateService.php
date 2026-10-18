<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Models\ContractTemplate;
use App\Modules\Operations\ProjectManagement\Support\ContractBody;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Project Management's own contract template library.
 *
 * ── Why PM has one at all ───────────────────────────────────────────────────
 *
 * Because the modules are sold separately. A tenant on Project Management alone must be able to
 * write a subcontract, and sending them to a Contract Management screen they have not bought is
 * not an answer. So the library is PM's, and Contract Management — when it is there — becomes a
 * place to import *from*.
 *
 * ── Import is a copy, deliberately ──────────────────────────────────────────
 *
 * `import()` writes a new PM row holding the wording as it read at that moment. It does not keep a
 * live reference. Two reasons, both learned the hard way elsewhere in this codebase: editing an
 * imported template must not reach back and rewrite the shared original that other modules are
 * using, and a tenant who later drops Contract Management must keep the contracts they were
 * working from. `source_ref` records provenance; it is not a foreign key.
 */
class ContractTemplateService
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function all(bool $activeOnly = false): array
    {
        return ContractTemplate::query()
            ->when($activeOnly, fn ($q) => $q->active())
            ->orderBy('name')
            ->get()
            ->map(fn (ContractTemplate $t) => $t->toPayload())
            ->all();
    }

    public function create(array $attributes): ContractTemplate
    {
        $template = ContractTemplate::create([
            'uuid'       => (string) Str::uuid(),
            'name'       => $attributes['name'],
            'summary'    => $attributes['summary'] ?? null,
            'category'   => $attributes['category'] ?? null,
            // Sanitised on the way in as well as on the way out. A template is written in a rich
            // text editor and rendered as markup, so it is never trusted at either end.
            'body'       => ContractBody::sanitize($attributes['body'] ?? null),
            'source'     => $attributes['source'] ?? 'own',
            'source_ref' => $attributes['source_ref'] ?? null,
            'is_active'  => $attributes['is_active'] ?? true,
            'created_by' => auth()->id(),
        ]);

        $this->audit->recordAction($template, 'ctemplate.created', ['name' => $template->name]);

        return $template;
    }

    public function update(ContractTemplate $template, array $attributes): ContractTemplate
    {
        $template->update([
            'name'      => $attributes['name'] ?? $template->name,
            'summary'   => $attributes['summary'] ?? $template->summary,
            'category'  => $attributes['category'] ?? $template->category,
            'body'      => array_key_exists('body', $attributes)
                ? ContractBody::sanitize($attributes['body'])
                : $template->body,
            'is_active' => $attributes['is_active'] ?? $template->is_active,
            // An edited import is the company's own wording now, whatever it started as. Saying so
            // stops the screen implying it still tracks Contract Management.
            'source'    => $template->source === 'imported' ? 'own' : $template->source,
        ]);

        $this->audit->recordAction($template, 'ctemplate.updated', ['name' => $template->name]);

        return $template->fresh();
    }

    public function delete(ContractTemplate $template): void
    {
        $this->audit->recordAction($template, 'ctemplate.deleted', ['name' => $template->name]);

        // Soft: contracts drafted from it keep a `template_id`, and a register that cannot say what
        // a contract was based on has lost the provenance the whole library exists to give.
        $template->delete();
    }

    // ── Contract Management, when it is installed ────────────────────────────

    public function canImport(): bool
    {
        return $this->modules->has('contracts.register') && Schema::hasTable('contracts_templates');
    }

    /**
     * What could be brought across, minus what already has been.
     *
     * @return list<array<string, mixed>>
     */
    public function importable(): array
    {
        if (! $this->canImport()) {
            return [];
        }

        $already = ContractTemplate::query()->whereNotNull('source_ref')
            ->pluck('source_ref')->all();

        return DB::table('contracts_templates')
            ->where('status', true)
            ->orderBy('name')
            ->limit(300)
            ->get(['uuid', 'name'])
            ->reject(fn ($t) => in_array($t->uuid, $already, true))
            ->map(fn ($t) => ['id' => $t->uuid, 'name' => $t->name])
            ->values()
            ->all();
    }

    /**
     * Copy one of Contract Management's templates into PM's library.
     *
     * Read through the query builder rather than that module's model on purpose: this must not
     * fail on a tenant where the module was never installed, and RLS already scopes the row —
     * including that module's shipped library, which uses a NULL company_id shared by everybody.
     */
    public function import(string $sourceUuid): ContractTemplate
    {
        abort_unless($this->canImport(), 422);

        $source = DB::table('contracts_templates')->where('uuid', $sourceUuid)->first();

        abort_if($source === null, 404);

        return $this->create([
            'name'       => $source->name,
            'summary'    => __('project_management.contract_templates.imported_summary'),
            'body'       => $source->content ?? null,
            'source'     => 'imported',
            'source_ref' => $source->uuid,
        ]);
    }
}
