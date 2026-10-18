<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Operations\Manufacturing\Models\AssistantAction as Action;
use App\Modules\Operations\Manufacturing\Models\AssistantQuery;
use App\Modules\Operations\Manufacturing\Models\AssistantRecommendation as Rec;
use App\Modules\Operations\Manufacturing\Models\LossEvent;
use App\Modules\Operations\Manufacturing\Models\LossFinding;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog as R;
use App\Modules\Procurement\Services\PurchaseRequestService;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Questions, recommendations and the drafts made from them (MFG-019).
 *
 * Every question is recorded with the figures it was answered from; every recommendation with the
 * measure it should move and that measure's value then. From a recommendation an authorised user
 * may draft a task or request an investigation. A purchase request or a change to an order's
 * priority is only proposed: it is carried out by somebody else holding the permission for it,
 * through the services a person would use, and the approval is recorded on the action. Nothing
 * here touches a posted transaction.
 */
class AssistantDesk
{
    /** Who may approve each kind that needs approval. */
    private const APPROVERS = ['PURCHASE_REQUEST' => R::APPROVE_PLAN, 'PRIORITISE_ORDER' => R::APPROVE_ORDERS];

    private const CATEGORY_FOR = ['material_variance' => 'EXCESS_MATERIAL', 'downtime' => 'EXCESS_DOWNTIME', 'supplier_quality' => 'SUPPLIER_QUALITY',
        'scrap_causes' => 'HIGH_SCRAP', 'late_orders' => 'RUSH_ORDERS', 'materials_at_risk' => 'OVERSTOCK', 'declining_margins' => 'OVER_STANDARD', 'cost_reduction' => 'OVER_STANDARD'];

    public function __construct(
        private readonly ManufacturingAssistant $assistant,
        private readonly AssistantNarrator $narrator,
        private readonly NumberSequences $numbers,
    ) {}

    /** Answer, word, and record a question. */
    public function ask(string $question, User $user): AssistantQuery
    {
        $answer = $this->assistant->answer($question, $user);
        $words = $this->narrator->narrate($answer);
        $companyId = $this->companyId($user);

        return DB::transaction(function () use ($question, $user, $answer, $words, $companyId) {
            $q = AssistantQuery::create(['company_id' => $companyId, 'user_id' => $user->id, 'question' => mb_substr($question, 0, 2000),
                'topic' => $answer['topic'], 'period_from' => $answer['period']['from'] ?? null, 'period_to' => $answer['period']['to'] ?? null,
                'refused' => $answer['refused'], 'answer' => $answer, 'narration' => $words['text'], 'narration_source' => $words['source']]);

            foreach ($answer['recommendations'] as $i => $r) {
                $rec = Rec::create(['company_id' => $companyId, 'query_id' => $q->id, 'topic' => $answer['topic'], 'text' => $r['text'],
                    'subject_type' => $r['subject_type'], 'subject_id' => $r['subject_id'], 'subject_label' => $r['subject_label'], 'url' => $r['url'],
                    'metric' => $r['metric'], 'baseline_value' => $r['baseline'], 'baseline_from' => $answer['period']['from'] ?? null,
                    'baseline_to' => $answer['period']['to'] ?? null, 'status' => 'PROPOSED']);
                $answer['recommendations'][$i]['id'] = $rec->uuid;
            }
            $q->update(['answer' => $answer]);

            return $q->refresh();
        });
    }

    /**
     * Draft something from a recommendation. A task and an investigation request are created at
     * once; anything that buys or changes production waits for approval.
     *
     * @param  array{owner_id?: ?int, due_date?: ?string, notes?: ?string}  $data
     */
    public function draft(Rec $rec, string $kind, array $data, User $user): Action
    {
        if (! $user->can(R::ACT_ON_ASSISTANT)) {
            throw new DomainException('Drafting work from the assistant needs the assistant action permission.');
        }
        $offered = collect($rec->askedIn->answer['recommendations'] ?? [])->firstWhere('id', $rec->uuid);
        if (! $offered || ! in_array($kind, $offered['actions'] ?? [], true)) {
            throw new DomainException('That is not one of the actions this recommendation offers.');
        }
        if ($kind === 'INVESTIGATION' && ! $user->can(R::INVESTIGATE_LOSS)) {
            throw new DomainException('Requesting an investigation needs the loss investigation permission.');
        }

        return DB::transaction(function () use ($rec, $kind, $data, $user, $offered) {
            $action = Action::create(['company_id' => $rec->company_id, 'recommendation_id' => $rec->id, 'kind' => $kind, 'status' => 'DRAFT',
                'summary' => trim($rec->text.(! empty($data['notes']) ? "\n\n".$data['notes'] : '')), 'payload' => $offered['payload'] ?? [],
                'requires_permission' => self::APPROVERS[$kind] ?? null, 'owner_id' => $data['owner_id'] ?? $user->id,
                'due_date' => $data['due_date'] ?? today()->addDays(7)->toDateString(), 'created_by' => $user->id]);
            $rec->update(['status' => 'ACTED']);

            if ($kind === 'INVESTIGATION') {
                $this->openInvestigation($action, $rec, $user);
            }

            return $action->refresh();
        });
    }

    /** Somebody else, holding the permission, lets a proposed action go ahead -- and it is carried out. */
    public function approve(Action $action, User $user, ?string $notes = null): Action
    {
        if ($action->status !== 'DRAFT' || ! in_array($action->kind, Action::NEEDS_APPROVAL, true)) {
            throw new DomainException('Only a proposed purchase or production change waits for approval.');
        }
        if (! $user->can($action->requires_permission)) {
            throw new DomainException("Approving this needs {$action->requires_permission}.");
        }
        if ((int) $action->created_by === (int) $user->id) {
            throw new DomainException('The person who proposed it cannot approve it.');
        }

        return DB::transaction(function () use ($action, $user, $notes) {
            [$type, $id, $ref] = match ($action->kind) {
                'PURCHASE_REQUEST' => $this->raisePurchaseRequest($action, $user),
                'PRIORITISE_ORDER' => $this->prioritise($action, $user),
            };
            $action->update(['status' => 'EXECUTED', 'decided_by' => $user->id, 'decided_at' => now(), 'decision_notes' => $notes,
                'result_type' => $type, 'result_id' => $id, 'result_reference' => $ref]);

            return $action->refresh();
        });
    }

    public function reject(Action $action, User $user, string $reason): Action
    {
        if ($action->status !== 'DRAFT') {
            throw new DomainException('It has already been decided.');
        }
        if ($action->requires_permission && ! $user->can($action->requires_permission) && (int) $action->created_by !== (int) $user->id) {
            throw new DomainException("Rejecting this needs {$action->requires_permission}.");
        }
        if (blank($reason)) {
            throw new DomainException('Say why.');
        }
        $action->update(['status' => 'REJECTED', 'decided_by' => $user->id, 'decided_at' => now(), 'decision_notes' => $reason]);

        return $action->refresh();
    }

    public function complete(Action $action, User $user, ?string $notes = null): Action
    {
        if ($action->kind !== 'TASK' || $action->status !== 'DRAFT') {
            throw new DomainException('Only an open task is completed.');
        }
        if (! in_array($user->id, [(int) $action->owner_id, (int) $action->created_by], true)) {
            throw new DomainException('Only the task\'s owner or the person who raised it can complete it.');
        }
        $action->update(['status' => 'DONE', 'decided_by' => $user->id, 'decided_at' => now(), 'decision_notes' => $notes]);

        return $action->refresh();
    }

    /**
     * Measure recommendations whose follow-up period has passed: the same measure, over a period as
     * long as the one it was made from, starting when it was made.
     */
    public function measureDue(): int
    {
        $n = 0;
        foreach (Rec::query()->whereNull('measured_at')->whereNotNull('metric')->whereNotNull('baseline_from')->get() as $rec) {
            $length = max(1, $rec->baseline_from->diffInDays($rec->baseline_to) + 1);
            $from = Carbon::parse($rec->created_at)->startOfDay();
            $to = $from->copy()->addDays($length - 1);
            if ($rec->metric !== 'ORDER_LATENESS' && $to->gte(today())) {
                continue;
            }
            $value = $this->assistant->measure($rec->metric, $rec->subject_type, $rec->subject_id, $from, $to);
            if ($value === null) {
                if ($rec->metric !== 'ORDER_LATENESS' && $to->lt(today()->subDays(90))) {
                    $rec->update(['measured_at' => now(), 'outcome' => 'No comparable records in the follow-up period; it cannot be measured.']);
                }

                continue;
            }
            $before = (float) $rec->baseline_value;
            $better = $value < $before;
            $rec->update(['measured_value' => $value, 'measured_at' => now(), 'outcome' => sprintf('%s: %s before, %s after (%s to %s). %s',
                $rec->metric, $this->num($before), $this->num($value), $from->toDateString(), $to->toDateString(),
                abs($value - $before) < 0.0001 ? 'No change.' : ($better ? 'Improved.' : 'Worse.').($rec->status === 'ACTED' ? '' : ' Nothing was drafted from this recommendation.'))]);
            $n++;
        }

        return $n;
    }

    // ── Carrying out ─────────────────────────────────────────────────────────────────────────

    private function openInvestigation(Action $action, Rec $rec, User $user): void
    {
        $answer = $rec->askedIn->answer;
        $category = $action->payload['category'] ?? self::CATEGORY_FOR[$rec->topic] ?? 'OVER_STANDARD';
        $finding = LossFinding::create([
            'company_id' => $rec->company_id, 'number' => $this->numbers->next($rec->company_id, 'LOSS_FINDING'), 'category' => $category,
            'status' => LossFinding::DETECTED, 'subject_type' => $rec->subject_type ?? 'PRODUCT', 'subject_id' => $rec->subject_id,
            'subject_label' => $rec->subject_label ?? $rec->topic, 'period_from' => $rec->baseline_from, 'period_to' => $rec->baseline_to,
            'measure' => $answer['title'], 'actual_value' => (float) ($rec->baseline_value ?? 0), 'baseline_value' => null,
            'baseline_basis' => 'Raised from the manufacturing assistant: '.$answer['summary'],
            'evidence' => array_map(fn ($r) => ['type' => 'Assistant figure', 'reference' => implode(', ', (array) ($r['records'] ?? [])),
                'date' => $answer['period']['to'] ?? null, 'qty' => null, 'value' => null], array_slice($answer['rows'], 0, 20)),
            'estimated_impact' => 0, 'confidence' => 'LOW', 'impact_basis' => 'Not estimated: requested from the assistant, to be investigated.',
            'recommended_action' => $rec->text, 'owner_id' => $action->owner_id, 'due_date' => $action->due_date,
            'dedupe_key' => "ASSISTANT:{$rec->uuid}", 'detected_at' => now(),
        ]);
        LossEvent::create(['company_id' => $rec->company_id, 'finding_id' => $finding->id, 'action' => 'DETECTED', 'to_status' => LossFinding::DETECTED,
            'actor_id' => $user->id, 'notes' => 'Investigation requested from the manufacturing assistant.', 'acted_at' => now()]);
        $action->update(['status' => 'EXECUTED', 'result_type' => 'LOSS_FINDING', 'result_id' => $finding->id, 'result_reference' => $finding->number]);
    }

    private function raisePurchaseRequest(Action $action, User $user): array
    {
        $items = $action->payload['items'] ?? [];
        if ($items === []) {
            throw new DomainException('There is nothing to buy on this proposal.');
        }
        $request = app(PurchaseRequestService::class)->create([
            'justification' => 'Proposed by the manufacturing assistant: '.$action->summary, 'needed_by' => $action->due_date?->toDateString(),
            'urgency' => 'high', 'currency' => config('finance.base_currency', 'ZAR'), 'status' => 'draft', 'estimated_total' => 0,
        ], $user->id);
        foreach ($items as $i) {
            $stock = FinStockItem::query()->where('code', $i['code'])->first();
            $line = $request->items()->create(['item_type' => 'goods', 'name' => trim(($stock?->code ?? $i['code']).' — '.($stock?->name ?? '')),
                'description' => 'Production material', 'supplier_id' => $action->payload['supplier_id'] ?? null, 'quantity' => (float) $i['qty'],
                'unit_price' => (float) ($stock?->avg_cost ?: $stock?->standard_cost ?? 0), 'line_total' => round((float) $i['qty'] * (float) ($stock?->avg_cost ?: $stock?->standard_cost ?? 0), 2),
                'source' => 'MANUFACTURING', 'external_ref' => $action->uuid]);
            if ($stock) {
                $line->forceFill(['stock_item_id' => $stock->id])->save();
            }
        }

        return ['PURCHASE_REQUEST', $request->id, $request->request_number];
    }

    private function prioritise(Action $action, User $user): array
    {
        $order = PO::query()->findOrFail($action->payload['order_id'] ?? 0);
        if (in_array($order->status, [PO::CLOSED, PO::CANCELLED, PO::COMPLETED, PO::REJECTED], true)) {
            throw new DomainException("{$order->number} is ".strtolower($order->status).'; its priority no longer matters.');
        }
        $before = (int) $order->priority;
        $order->update(['priority' => 1]);
        app(ProductionOrders::class)->event($order, 'PRIORITISED', $order->status, $order->status, $user, 'Approved from the manufacturing assistant',
            ['priority_before' => $before, 'action' => $action->uuid]);

        return ['PRODUCTION_ORDER', $order->id, $order->number];
    }

    private function companyId(User $user): int
    {
        return (int) (app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id() ?? $user->company_id);
    }

    private function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    }
}
