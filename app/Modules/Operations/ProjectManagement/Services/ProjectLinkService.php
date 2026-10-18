<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Contracts\Providers\AssetProvider;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\BudgetProvider;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\ContractProvider;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\VehicleProvider;
use App\Modules\Operations\ProjectManagement\Models\ExternalRef;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Support\Providers\ModuleTable;
use App\Modules\Operations\ProjectManagement\Support\Providers\ProvidedRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Attaching another module's records to a project (PM-1042, PM-1043).
 *
 * The problem this solves: `assets`, `contracts`, `purchase_orders`, `hd_tickets` and `budgets` all
 * belong to other modules, none of them has a project column, and PM has no business adding one to
 * a table it does not own. Without a link, an "integration" can only mean PM reading a list —
 * which is a page, not an integration.
 *
 * So the association lives on PM's side, in `pm_external_refs` with `local_type = 'project_link'`.
 * That table already exists for exactly this and carries what a link needs: the source module, the
 * entity, its id, and a `display_name` snapshot so the row still reads when the other module is
 * uninstalled or the record is deleted (PM-1043).
 *
 * **Explicit, never inferred.** No matching on supplier and date, no "same month, similar amount".
 * A guessed link would attribute another job's steel to this project's committed cost, and the
 * person reading the figure would have no way to tell which parts of it were guessed.
 */
class ProjectLinkService
{
    /**
     * What can be linked, and which capability has to be live for it to be offered.
     *
     * A kind whose capability is absent is not shown at all — offering a picker that returns
     * nothing is how a working system looks broken.
     *
     * A null capability means PM owns the records itself, so there is nothing to be entitled to and
     * the kind is always offered.
     *
     * @var array<string, array{capability: ?string, entity: string, module: string}>
     */
    public const KINDS = [
        'asset'          => ['capability' => 'assets.register', 'entity' => 'asset', 'module' => 'assets'],
        'vehicle'        => ['capability' => 'fleet.vehicles', 'entity' => 'vehicle', 'module' => 'fleet'],
        'contract'       => ['capability' => 'contracts.register', 'entity' => 'contract', 'module' => 'contracts'],
        'purchase_order' => ['capability' => 'procurement.orders', 'entity' => 'purchase_order', 'module' => 'procurement'],
        'requisition'    => ['capability' => 'procurement.requisitions', 'entity' => 'requisition', 'module' => 'procurement'],
        // What a project raises. A requisition is what Procurement converts an approved request
        // into, so the thing PM creates — and therefore links — is the request.
        'purchase_request' => ['capability' => 'procurement.requisitions', 'entity' => 'purchase_request', 'module' => 'procurement'],
        'supplier'       => ['capability' => 'procurement.suppliers', 'entity' => 'supplier', 'module' => 'procurement'],
        'ticket'         => ['capability' => 'helpdesk.tickets', 'entity' => 'ticket', 'module' => 'helpdesk'],
        'budget'         => ['capability' => 'finance.budgets', 'entity' => 'budget', 'module' => 'finance'],
        'invoice'        => ['capability' => 'finance.invoices', 'entity' => 'invoice', 'module' => 'finance'],
        // Rooms, licences and plant PM keeps itself, because no other module owns them: a meeting
        // room is not an asset the finance team depreciates.
        'resource'       => ['capability' => null, 'entity' => 'resource', 'module' => 'local'],
    ];

    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly ProviderRegistry $providers,
        private readonly AuditLogger $audit,
        private readonly InternalEmitter $emitter,
    ) {
    }

    /** Kinds this tenant can actually link right now. @return list<string> */
    public function availableKinds(): array
    {
        return array_keys(array_filter(
            self::KINDS,
            fn (array $kind) => $kind['capability'] === null || $this->modules->has($kind['capability']),
        ));
    }

    /**
     * Search a module for something to link.
     *
     * @return list<array{id:string,label:string,meta:array<string,mixed>}>
     */
    public function search(string $kind, string $query, int $limit = 20): array
    {
        $this->assertKind($kind);

        $records = match ($kind) {
            'asset'    => $this->providers->for(AssetProvider::class)->search($query, $limit),
            'vehicle'  => $this->providers->for(VehicleProvider::class)->search($query, $limit),
            'contract' => $this->providers->for(ContractProvider::class)->search($query, $limit),
            'budget'   => $this->providers->for(BudgetProvider::class)->search($query, $limit),
            default    => $this->searchTable($kind, $query, $limit),
        };

        return array_map(
            fn ($r) => is_array($r)
                ? ['id' => $r['id'], 'label' => $r['label'], 'meta' => $r]
                : ['id' => $r->id, 'label' => $r->label, 'meta' => $r->meta],
            $records,
        );
    }

    /** Attach a record to the project. Idempotent: linking the same thing twice is one link. */
    public function link(Project $project, string $kind, string $recordId, ?string $label = null): ExternalRef
    {
        $this->assertKind($kind);

        $spec = self::KINDS[$kind];

        $ref = ExternalRef::updateOrCreate(
            [
                'local_type'    => 'project_link',
                'source_module' => $spec['module'],
                'source_entity' => $spec['entity'],
                'source_id'     => $recordId,
                'local_id'      => $project->id,
            ],
            [
                // The label is snapshotted so the link still reads after the module is uninstalled
                // or the record removed — an orphaned link that shows an id is a link nobody can
                // interpret (PM-1043).
                'display_name'   => $label ?: $this->labelFor($kind, $recordId),
                'snapshot'       => ['project_id' => $project->uuid, 'kind' => $kind],
                'sync_status'    => 'ok',
                'last_synced_at' => now(),
            ],
        );

        $this->audit->recordAction($ref, 'link.created', [
            'project' => $project->uuid,
            'kind'    => $kind,
            'record'  => $recordId,
        ]);

        return $ref;
    }

    /**
     * Record a purchase this project just raised.
     *
     * Separate from `link()` because the record was created a moment ago by us: there is nothing to
     * search for, the label is already known, and it earns an evidence event that a link somebody
     * attached by hand does not — `requisition.raised` is a thing that *happened on this project*,
     * not merely a reference somebody filed.
     */
    public function linkRaisedPurchase(Project $project, ProvidedRecord $record): ExternalRef
    {
        return DB::transaction(function () use ($project, $record) {
            $ref = $this->link(
                $project,
                'purchase_request',
                (string) ($record->meta['record_id'] ?? $record->id),
                $record->label,
            );

            $this->emitter->emit($project, 'requisition.raised', [
                'subject_type' => 'purchase',
                'subject_id'   => $ref->uuid,
                'source_ref'   => 'purchase_request:' . $record->id,
                'amount'       => (string) ($record->meta['amount'] ?? '0'),
                'currency'     => $record->meta['currency'] ?? $project->currency,
                'payload'      => [
                    'reference'    => (string) ($record->meta['number'] ?? $record->id),
                    // There is no `users.name` on this platform; the name lives on hr_people.
                    'requested_by' => $this->actorName(),
                ],
            ]);

            return $ref;
        });
    }

    public function unlink(Project $project, string $refUuid): void
    {
        $ref = ExternalRef::where('uuid', $refUuid)
            ->where('local_type', 'project_link')
            ->where('local_id', $project->id)
            ->first();

        if (! $ref) {
            return;
        }

        $this->audit->recordAction($ref, 'link.removed', ['project' => $project->uuid]);

        // The link goes; the other module's record is untouched. Deleting an asset because
        // somebody unlinked it from a project would be the module overreaching badly.
        $ref->delete();
    }

    /**
     * Everything linked to a project, grouped by kind, each with whether it still resolves.
     *
     * @return array<string, list<array<string,mixed>>>
     */
    public function forProject(Project $project): array
    {
        $out = [];

        $refs = ExternalRef::where('local_type', 'project_link')
            ->where('local_id', $project->id)
            ->orderBy('source_entity')
            ->get();

        foreach ($refs as $ref) {
            $kind = $ref->snapshot['kind'] ?? $ref->source_entity;
            $live = $this->resolve($kind, (string) $ref->source_id);

            $out[$kind][] = [
                'id'      => $ref->uuid,
                // The live label when the record is still there, the snapshot when it is not.
                'label'   => $live['label'] ?? $ref->display_name,
                'meta'    => $live['meta'] ?? [],
                // Said plainly rather than hidden: a link to a record somebody deleted is a fact
                // about this project, and silently dropping it would lose it.
                'orphaned' => $live === null,
            ];
        }

        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function actorName(): string
    {
        $user = Auth::user();
        $person = $user?->person;

        return trim(($person->first_name ?? '') . ' ' . ($person->surname ?? ''))
            ?: (string) ($user?->email ?? Auth::id());
    }

    private function assertKind(string $kind): void
    {
        if (! isset(self::KINDS[$kind])) {
            throw new InvalidArgumentException("'{$kind}' is not something a project can be linked to.");
        }

        $capability = self::KINDS[$kind]['capability'];

        if ($capability !== null && ! $this->modules->has($capability)) {
            throw new InvalidArgumentException('That module is not available on this workspace.');
        }
    }

    /** @return array{label:string, meta:array<string,mixed>}|null */
    private function resolve(string $kind, string $recordId): ?array
    {
        if (! isset(self::KINDS[$kind])) {
            return null;
        }

        $capability = self::KINDS[$kind]['capability'];

        if ($capability !== null && ! $this->modules->has($capability)) {
            return null;
        }

        $record = match ($kind) {
            'asset'    => $this->providers->for(AssetProvider::class)->get($recordId),
            'vehicle'  => $this->providers->for(VehicleProvider::class)->get($recordId),
            'contract' => $this->providers->for(ContractProvider::class)->get($recordId),
            default    => null,
        };

        if ($record instanceof ProvidedRecord) {
            return ['label' => $record->label, 'meta' => $record->meta];
        }

        $row = $this->row($kind, $recordId);

        return $row ? ['label' => $this->labelFromRow($kind, $row), 'meta' => (array) $row] : null;
    }

    private function labelFor(string $kind, string $recordId): string
    {
        return $this->resolve($kind, $recordId)['label'] ?? $recordId;
    }

    /**
     * Tables read directly, because they have no provider seam of their own.
     *
     * A supplier or an invoice is a reference somebody attaches to a project; neither is something
     * PM ever substitutes a local implementation for, so a full seam would be ceremony.
     *
     * @return array{0:string, 1:list<string>, 2:list<string>}|null  [table, searchable, identity]
     */
    private function tableSpec(string $kind): ?array
    {
        return match ($kind) {
            'supplier'       => ['suppliers', ['company_name', 'supplier_number'], ['id', 'uuid', 'company_name']],
            'purchase_order' => ['purchase_orders', ['po_number', 'title'], ['id', 'uuid', 'po_number']],
            'requisition'    => ['procurement_requisitions', ['requisition_number', 'title'], ['id', 'uuid', 'requisition_number']],
            'purchase_request' => ['purchase_requests', ['request_number', 'justification'], ['id', 'uuid', 'request_number', 'status', 'estimated_total']],
            'ticket'         => ['hd_tickets', ['ticket_number', 'subject'], ['id', 'ticket_number', 'subject']],
            'invoice'        => ['fin_invoices', ['number', 'description'], ['id', 'uuid', 'number']],
            'resource'       => ['pm_resources', ['name', 'identifier'], ['id', 'uuid', 'name', 'kind', 'identifier']],
            default          => null,
        };
    }

    private function searchTable(string $kind, string $query, int $limit): array
    {
        [$table, $searchable] = $this->tableSpec($kind) ?? [null, []];

        $builder = $table ? ModuleTable::query($table, $this->tableSpec($kind)[2]) : null;

        if (! $builder) {
            return [];
        }

        $rows = $builder
            ->when($query !== '', function ($q) use ($searchable, $query) {
                $q->where(function ($w) use ($searchable, $query) {
                    foreach ($searchable as $column) {
                        $w->orWhere($column, 'ilike', '%' . $query . '%');
                    }
                });
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return collect($rows)->map(fn ($row) => [
            'id'    => (string) ($row->uuid ?? $row->id),
            'label' => $this->labelFromRow($kind, $row),
        ])->all();
    }

    private function row(string $kind, string $recordId): ?object
    {
        [$table, , $identity] = $this->tableSpec($kind) ?? [null, null, []];

        $builder = $table ? ModuleTable::query($table, $identity) : null;

        if (! $builder) {
            return null;
        }

        // hd_tickets has no uuid, so the id shape decides which column to match on rather than a
        // blind `orWhere` that would compare a uuid to a bigint and abort the transaction.
        return ctype_digit($recordId)
            ? $builder->where('id', (int) $recordId)->first()
            : $builder->where('uuid', $recordId)->first();
    }

    private function labelFromRow(string $kind, object $row): string
    {
        return match ($kind) {
            'supplier'       => (string) ($row->company_name ?: 'Supplier'),
            'purchase_order' => trim(($row->po_number ?: 'Order') . ' · ' . ($row->title ?? '')),
            'requisition'    => trim(($row->requisition_number ?: 'Requisition') . ' · ' . ($row->title ?? '')),
            'purchase_request' => (string) ($row->request_number ?: 'Purchase request'),
            'ticket'         => trim(($row->ticket_number ?: 'Ticket') . ' · ' . ($row->subject ?? '')),
            'invoice'        => trim(($row->number ?: 'Invoice') . ' · ' . ($row->description ?? '')),
            'resource'       => trim(($row->name ?: 'Resource') . ($row->identifier ? ' · ' . $row->identifier : '')),
            default          => 'Linked record',
        };
    }
}
