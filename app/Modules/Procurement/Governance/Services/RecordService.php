<?php

namespace App\Modules\Procurement\Governance\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Procurement 360° record (§6) — PROC-2026-000145 and everything hanging off it.
 *
 * §6 asks for "comprehensive procurement history covering supplier performance, contract history,
 * procurement timelines, procurement officer activities, evaluation history and compliance
 * records". The temptation is to build that as a reporting table that copies the interesting fields
 * out of six modules on a schedule. That table is wrong within a day and nobody notices for a
 * quarter.
 *
 * So a record holds identity, ownership and stage only, and reaches everything else through typed
 * links resolved at read time. The denormalised label on each link is a *fallback* for when the
 * source module is uninstalled or the row archived — never the primary source. When both exist,
 * the live row wins.
 *
 * The stage column is also what makes §4.4's processing-time analytics possible: each transition
 * writes an event carrying how long the previous stage lasted, so "request → approval" and
 * "tender close → evaluation" are a group-by rather than a per-record reconstruction.
 */
class RecordService
{
    /** The §11 chain, in order. Used to compute durations and to validate transitions. */
    public const STAGES = [
        'REQUEST',
        'PLANNING',
        'SOURCING',
        'EVALUATION',
        'RECOMMENDATION',
        'APPROVAL',
        'AWARD',
        'CONTRACT',
        'DELIVERY',
        'CLOSED',
    ];

    public function __construct(
        private readonly ReferenceGenerator $references,
        private readonly AuditLedger $audit,
    ) {}

    /**
     * Open a procurement record. Idempotent on the caller's source: passing the same origin twice
     * returns the existing record rather than creating a second spine for one procurement.
     *
     * @param  array<string,mixed>  $data
     */
    public function open(int $companyId, array $data): object
    {
        $origin = $data['origin'] ?? null;   // ['link_role'=>…, 'entity_type'=>…, 'entity_id'=>…]

        if ($origin && $existing = $this->findByLink($companyId, $origin['entity_type'], (int) $origin['entity_id'])) {
            return $existing;
        }

        return DB::transaction(function () use ($companyId, $data, $origin) {
            $now = Carbon::now();
            $reference = $this->references->next($companyId, 'record');

            $recordId = DB::table('procgov_records')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'company_id' => $companyId,
                'reference' => $reference,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'category' => $data['category'] ?? null,
                'method' => $data['method'] ?? null,
                'company_unit_id' => $data['company_unit_id'] ?? null,
                'project_id' => $data['project_id'] ?? null,
                'cost_centre' => $data['cost_centre'] ?? null,
                'officer_id' => $data['officer_id'] ?? null,
                'estimated_value' => $data['estimated_value'] ?? null,
                'currency' => $data['currency'] ?? 'ZAR',
                'stage' => $data['stage'] ?? 'REQUEST',
                'status' => 'ACTIVE',
                'financial_year_id' => $data['financial_year_id'] ?? null,
                'financial_year_label' => $data['financial_year_label'] ?? null,
                'opened_at' => $now,
                'target_completion_at' => $data['target_completion_at'] ?? null,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('procgov_stage_events')->insert([
                'company_id' => $companyId,
                'record_id' => $recordId,
                'from_stage' => null,
                'to_stage' => $data['stage'] ?? 'REQUEST',
                'occurred_at' => $now,
                'actor_id' => auth()->id(),
                'note' => 'Procurement record opened',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($origin) {
                $this->link($companyId, $recordId, $origin);
            }

            $this->audit->record($companyId, [
                'action' => 'created',
                'capability' => 'governance',
                'entity_type' => 'procgov_records',
                'entity_id' => $recordId,
                'entity_reference' => $reference,
                'record_id' => $recordId,
                'summary' => "Opened procurement record {$reference} — {$data['title']}",
                'after' => ['title' => $data['title'], 'estimated_value' => $data['estimated_value'] ?? null],
            ]);

            return DB::table('procgov_records')->find($recordId);
        });
    }

    /**
     * Attach a typed pointer. Re-linking the same entity in the same role refreshes the cached
     * label rather than creating a duplicate row.
     *
     * @param  array<string,mixed>  $link
     */
    public function link(int $companyId, int $recordId, array $link): void
    {
        $now = Carbon::now();

        DB::table('procgov_record_links')->updateOrInsert(
            [
                'record_id' => $recordId,
                'link_role' => $link['link_role'],
                'entity_type' => $link['entity_type'],
                'entity_id' => $link['entity_id'] ?? null,
            ],
            [
                'company_id' => $companyId,
                'source_module' => $link['source_module'] ?? 'procurement_governance',
                'entity_uuid' => $link['entity_uuid'] ?? null,
                'entity_reference' => $link['entity_reference'] ?? null,
                'label' => isset($link['label']) ? Str::limit((string) $link['label'], 185, '') : null,
                'amount' => $link['amount'] ?? null,
                'state' => $link['state'] ?? null,
                'meta' => isset($link['meta']) ? json_encode($link['meta']) : null,
                'linked_by' => auth()->id(),
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
    }

    /**
     * Move a record to a new stage, recording how long the previous one took.
     *
     * Backwards moves are permitted — a recommendation sent back for re-evaluation is a real thing
     * that happens — but they are recorded as such, and the duration written is the time actually
     * spent, not a negative number derived from stage order.
     */
    public function transition(int $companyId, int $recordId, string $toStage, ?string $note = null): void
    {
        DB::transaction(function () use ($companyId, $recordId, $toStage, $note) {
            $record = DB::table('procgov_records')->lockForUpdate()->find($recordId);

            if (! $record || $record->stage === $toStage) {
                return;
            }

            $now = Carbon::now();

            $lastEvent = DB::table('procgov_stage_events')
                ->where('record_id', $recordId)
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->first();

            $secondsInPrevious = $lastEvent
                // Cast, and round, deliberately: Carbon returns a float with fractional seconds,
                // and the column is a bigint — an unrounded value is rejected by Postgres, which
                // would make every stage transition fail.
                ? (int) max(0, round(Carbon::parse($lastEvent->occurred_at)->diffInSeconds($now)))
                : null;

            DB::table('procgov_stage_events')->insert([
                'company_id' => $companyId,
                'record_id' => $recordId,
                'from_stage' => $record->stage,
                'to_stage' => $toStage,
                'occurred_at' => $now,
                'seconds_in_from_stage' => $secondsInPrevious,
                'actor_id' => auth()->id(),
                'note' => $note,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('procgov_records')->where('id', $recordId)->update([
                'stage' => $toStage,
                'status' => $toStage === 'CLOSED' ? 'CLOSED' : $record->status,
                'closed_at' => $toStage === 'CLOSED' ? $now : $record->closed_at,
                'updated_by' => auth()->id(),
                'updated_at' => $now,
            ]);

            $this->audit->record($companyId, [
                'action' => 'stage_changed',
                'capability' => 'governance',
                'entity_type' => 'procgov_records',
                'entity_id' => $recordId,
                'entity_reference' => $record->reference,
                'record_id' => $recordId,
                'before' => ['stage' => $record->stage],
                'after' => ['stage' => $toStage],
                'comment' => $note,
                'summary' => "Procurement {$record->reference} moved from {$record->stage} to {$toStage}",
            ]);
        });
    }

    /** Set or change the responsible officer — the §4 workload figures follow from this. */
    public function assignOfficer(int $companyId, int $recordId, ?int $officerId, ?string $note = null): void
    {
        $record = DB::table('procgov_records')->find($recordId);

        if (! $record || (int) $record->officer_id === (int) $officerId) {
            return;
        }

        DB::table('procgov_records')->where('id', $recordId)->update([
            'officer_id' => $officerId,
            'updated_by' => auth()->id(),
            'updated_at' => Carbon::now(),
        ]);

        $this->audit->record($companyId, [
            'action' => 'officer_assigned',
            'capability' => 'governance',
            'entity_type' => 'procgov_records',
            'entity_id' => $recordId,
            'entity_reference' => $record->reference,
            'record_id' => $recordId,
            'before' => ['officer_id' => $record->officer_id],
            'after' => ['officer_id' => $officerId],
            'comment' => $note,
        ]);
    }

    /**
     * The full §6.1 view, grouped the way the brief lays it out: Procurement, Tender, Supplier,
     * Contract, Deviations, Audit.
     *
     * @return array<string,mixed>
     */
    public function threeSixty(int $companyId, int $recordId): array
    {
        $record = DB::table('procgov_records')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->find($recordId);

        if (! $record) {
            return [];
        }

        $links = DB::table('procgov_record_links')
            ->where('record_id', $recordId)
            ->orderBy('link_role')
            ->orderBy('id')
            ->get();

        return [
            'record' => $record,
            'procurement' => $this->groupLinks($links, ['request', 'plan_item', 'budget', 'approval']),
            'tender' => $this->tenderSection($companyId, $recordId, $links),
            'supplier' => $this->groupLinks($links, ['supplier', 'supplier_performance']),
            'contract' => $this->groupLinks($links, ['contract', 'amendment', 'purchase_order', 'invoice']),
            'deviations' => $this->deviationSection($companyId, $recordId),
            'documents' => $this->documentSection($companyId, $recordId),
            'timeline' => $this->timeline($companyId, $recordId),
            'compliance' => $this->complianceSection($companyId, $recordId),
            'audit' => $this->auditTrail($companyId, $recordId),
        ];
    }

    /** §4.4: the transitions with their durations, ready to render as a timeline. */
    public function timeline(int $companyId, int $recordId): Collection
    {
        return DB::table('procgov_stage_events as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.actor_id')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->where('e.company_id', $companyId)
            ->where('e.record_id', $recordId)
            ->orderBy('e.occurred_at')
            ->orderBy('e.id')
            ->get([
                'e.from_stage', 'e.to_stage', 'e.occurred_at', 'e.seconds_in_from_stage', 'e.note',
                DB::raw("trim(coalesce(p.first_name,'') || ' ' || coalesce(p.surname,'')) as actor_name"),
                'u.email as actor_email',
            ])
            ->map(function ($event) {
                $event->actor = trim((string) $event->actor_name) ?: $event->actor_email;
                unset($event->actor_name, $event->actor_email);
                $event->days_in_from_stage = $event->seconds_in_from_stage !== null
                    ? round($event->seconds_in_from_stage / 86400, 1)
                    : null;

                return $event;
            });
    }

    public function auditTrail(int $companyId, int $recordId, int $limit = 200): Collection
    {
        return DB::table('procgov_audit_events')
            ->where('company_id', $companyId)
            ->where('record_id', $recordId)
            ->orderByDesc('occurred_at')
            ->orderByDesc('sequence')
            ->limit($limit)
            ->get([
                'sequence', 'occurred_at', 'actor_label', 'actor_role', 'action', 'entity_type',
                'entity_reference', 'summary', 'decision', 'comment', 'ip',
            ]);
    }

    public function findByLink(int $companyId, string $entityType, int $entityId): ?object
    {
        $recordId = DB::table('procgov_record_links')
            ->where('company_id', $companyId)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->value('record_id');

        return $recordId ? DB::table('procgov_records')->find($recordId) : null;
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────

    private function groupLinks(Collection $links, array $roles): Collection
    {
        return $links->whereIn('link_role', $roles)->values();
    }

    private function tenderSection(int $companyId, int $recordId, Collection $links): array
    {
        $reviews = DB::table('procgov_tender_reviews')
            ->where('company_id', $companyId)
            ->where('record_id', $recordId)
            ->whereNull('deleted_at')
            ->get(['id', 'uuid', 'reference', 'tender_number', 'title', 'status', 'methodology',
                'estimated_value', 'evaluation_deadline', 'awarded_at']);

        $reviewIds = $reviews->pluck('id')->all();

        return [
            'reviews' => $reviews,
            'bidders' => $reviewIds === [] ? collect() : DB::table('procgov_bidders')
                ->whereIn('review_id', $reviewIds)
                ->whereNull('deleted_at')
                ->orderBy('overall_rank')
                ->get(['review_id', 'supplier_name', 'bid_amount', 'weighted_score', 'overall_rank',
                    'is_disqualified', 'disqualification_reason', 'status']),
            'links' => $this->groupLinks($links, ['tender', 'evaluation', 'recommendation']),
        ];
    }

    private function deviationSection(int $companyId, int $recordId): Collection
    {
        return DB::table('procgov_deviations')
            ->where('company_id', $companyId)
            ->where('record_id', $recordId)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->get(['id', 'uuid', 'reference', 'title', 'deviation_type_code', 'status', 'risk_level',
                'procurement_value', 'compliance_result', 'decided_at', 'decision']);
    }

    private function documentSection(int $companyId, int $recordId): Collection
    {
        return DB::table('procgov_document_links as l')
            ->join('procgov_documents as d', 'd.id', '=', 'l.document_id')
            ->leftJoin('procgov_document_categories as c', 'c.id', '=', 'd.category_id')
            ->where('l.company_id', $companyId)
            ->where('l.entity_type', 'procgov_records')
            ->where('l.entity_id', $recordId)
            ->whereNull('d.deleted_at')
            ->orderByDesc('d.id')
            ->get(['d.id', 'd.uuid', 'd.title', 'd.confidentiality', 'd.approval_status',
                'd.expires_at', 'd.version_count', 'c.name as category', 'l.link_role']);
    }

    private function complianceSection(int $companyId, int $recordId): Collection
    {
        return DB::table('procgov_compliance_checks')
            ->where('company_id', $companyId)
            ->where('record_id', $recordId)
            ->orderByDesc('checked_at')
            ->limit(50)
            ->get(['uuid', 'subject_type', 'subject_reference', 'result', 'rules_evaluated',
                'failures', 'warnings', 'checked_at', 'rule_set_code', 'rule_set_version']);
    }
}
