<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Models\LossEvent;
use App\Modules\Operations\Manufacturing\Models\LossFinding as Finding;
use App\Modules\Operations\Manufacturing\Services\LossRules;
use App\Modules\Operations\Manufacturing\Services\NumberSequences;
use App\Modules\Operations\WarehouseManagement\Concerns\ScopesRawQueries;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Investigations and what they achieve (WMS-111, WMS-112, WMS-114).
 *
 * The case workflow itself -- assign, investigate, verify or dismiss, corrective action, result,
 * approval by somebody else, close, escalation -- is Manufacturing's LossInvestigation, unchanged.
 * Here: opening a case by hand, and the money and the effectiveness measured honestly. Each kind
 * of amount is its own figure from its own source; none is added to another, so one event can
 * never be counted as two savings.
 */
class ProtectionCases
{
    use ScopesRawQueries;

    public const PRIORITIES = ['LOW', 'MEDIUM', 'HIGH', 'URGENT'];

    public function __construct(private readonly LossRules $rules, private readonly NumberSequences $numbers) {}

    /** @param array{title:string, description:string, priority?:string, warehouse_id?:?int, estimated_impact?:?float, owner_id?:?int, due_date?:?string} $data */
    public function open(array $data, User $by): Finding
    {
        if (blank($data['title'] ?? null) || blank($data['description'] ?? null)) {
            throw new RuntimeException('A case needs a title and what prompted it.');
        }
        $priority = $data['priority'] ?? 'MEDIUM';
        if (! in_array($priority, self::PRIORITIES, true)) {
            throw new RuntimeException("Unknown priority {$priority}.");
        }
        $companyId = (int) ($this->tenantId() ?? $by->company_id);
        $rule = $this->rules->for($companyId, 'WH_MANUAL');

        return DB::transaction(function () use ($data, $by, $companyId, $rule, $priority) {
            $f = Finding::create([
                'company_id' => $companyId, 'number' => $this->numbers->next($companyId, 'LOSS_FINDING'), 'category' => 'WH_MANUAL',
                'status' => Finding::DETECTED, 'subject_type' => 'CASE', 'subject_label' => mb_substr($data['title'], 0, 250),
                'period_from' => today()->toDateString(), 'period_to' => today()->toDateString(), 'measure' => 'Reported by a person', 'actual_value' => 0,
                'baseline_basis' => 'Opened by hand', 'evidence' => ['reported' => $data['description']], 'estimated_impact' => round((float) ($data['estimated_impact'] ?? 0), 2),
                'confidence' => 'LOW', 'impact_basis' => 'Estimate given by the person who opened the case.', 'recommended_action' => 'Investigate and record the finding.',
                'owner_id' => $data['owner_id'] ?? null, 'due_date' => $data['due_date'] ?? today()->addDays($rule->due_days)->toDateString(),
                'dedupe_key' => 'WH_MANUAL:'.uniqid('', true), 'detected_at' => now(), 'priority' => $priority, 'origin' => 'MANUAL',
                'warehouse_id' => $data['warehouse_id'] ?? null, 'rule_snapshot' => null,
            ]);
            LossEvent::create(['company_id' => $companyId, 'finding_id' => $f->id, 'action' => 'OPENED', 'to_status' => Finding::DETECTED,
                'actor_id' => $by->id, 'notes' => $data['description'], 'acted_at' => now()]);

            return $f;
        });
    }

    public function prioritise(Finding $f, string $priority, User $by): Finding
    {
        if (! in_array($priority, self::PRIORITIES, true)) {
            throw new RuntimeException("Unknown priority {$priority}.");
        }
        $from = $f->priority;
        $f->update(['priority' => $priority]);
        LossEvent::create(['company_id' => $f->company_id, 'finding_id' => $f->id, 'action' => 'PRIORITY', 'from_status' => $f->status, 'to_status' => $f->status,
            'actor_id' => $by->id, 'detail' => ['priority' => [$from, $priority]], 'acted_at' => now()]);

        return $f;
    }

    /**
     * The money, each figure from its own source (WMS-111), and the effectiveness (WMS-114).
     *
     * @return array{impact: array, effectiveness: array, by_category: list<array>}
     */
    public function measures(string $from, string $to): array
    {
        $range = [$from.' 00:00:00', $to.' 23:59:59'];
        $findings = Finding::query()->whereIn('category', Finding::WAREHOUSE_CATEGORIES)->whereBetween('detected_at', $range)->get();
        $losses = app(LossControl::class)->register(null, $from, $to);

        $closed = $findings->filter(fn ($f) => $f->closed_at !== null);
        $days = $closed->map(fn ($f) => $f->detected_at->diffInHours($f->closed_at) / 24)->sort()->values();
        $median = $days->isEmpty() ? null : round((float) $days[(int) floor(($days->count() - 1) / 2)], 1);
        // Recurrence: the same thing found again after an earlier case about it was closed or dismissed.
        $recurring = $findings->filter(fn ($f) => Finding::query()->where('dedupe_key', $f->dedupe_key)->where('id', '<', $f->id)
            ->whereIn('status', [Finding::CLOSED, Finding::DISMISSED])->exists())->count();

        return [
            'impact' => [
                // Ledger facts: posted write-offs and disposals.
                'confirmed_loss' => $losses['confirmed']['total'],
                // Of the cases, what investigators verified as lost -- a subset of the above where the loss was written off.
                'verified_by_investigation' => round((float) $findings->whereNotIn('status', [Finding::DISMISSED])->sum('verified_loss'), 2),
                'potential_loss' => round((float) $findings->whereIn('status', [Finding::DETECTED, Finding::INVESTIGATING])->sum('estimated_impact'), 2),
                'unresolved_variance' => $losses['suspected']['total'],
                'legitimate_corrections' => $findings->where('status', Finding::DISMISSED)->count(),
                'recovered' => $losses['recoveries']['recovered'],
                'avoided_cost' => round((float) $findings->filter(fn ($f) => $f->realised_saving && $f->saving_evidence && $f->approved_at)->sum('realised_saving'), 2),
                'avoided_cost_unapproved' => round((float) $findings->filter(fn ($f) => $f->realised_saving && ! $f->approved_at)->sum('realised_saving'), 2),
            ],
            'effectiveness' => [
                'raised' => $findings->count(), 'open' => $findings->whereIn('status', Finding::OPEN)->count(), 'closed' => $closed->count(),
                'dismissed' => $findings->where('status', Finding::DISMISSED)->count(),
                'overdue' => $findings->filter(fn ($f) => in_array($f->status, Finding::OPEN, true) && $f->due_date?->isPast())->count(),
                'median_days_to_close' => $median, 'recurring' => $recurring,
                'recurrence_rate' => $findings->count() ? round($recurring / $findings->count() * 100, 1) : null,
                'with_corrective_action' => $findings->filter(fn ($f) => filled($f->corrective_action))->count(),
                'action_results' => $findings->filter(fn ($f) => filled($f->result_after_action))->count(),
            ],
            'by_category' => $findings->groupBy('category')->map(fn ($g, $c) => ['category' => $c, 'raised' => $g->count(),
                'open' => $g->whereIn('status', Finding::OPEN)->count(), 'dismissed' => $g->where('status', Finding::DISMISSED)->count(),
                'estimated' => round((float) $g->whereIn('status', Finding::OPEN)->sum('estimated_impact'), 2),
                'verified' => round((float) $g->sum('verified_loss'), 2)])->values()->all(),
        ];
    }
}
