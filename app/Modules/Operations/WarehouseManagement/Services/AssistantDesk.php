<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog as R;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Questions, drafts and quality for the warehouse assistant (WMS-140, WMS-143, WMS-144).
 *
 * Drafts only: a count is opened like any count, a transfer is drafted for approval, a purchase is
 * a draft request through Procurement, an investigation is a case for investigators. Nothing here
 * moves stock, commits money, approves a write-off or changes access. Each draft is recorded with
 * who asked for it and a key, so asking twice makes it once; a draft that has not been acted on can
 * be withdrawn.
 */
class AssistantDesk
{
    /** kind => permission the person drafting it needs */
    private const NEEDS = ['COUNT' => R::MANAGE_COUNTS, 'TRANSFER_DRAFT' => R::MOVE_STOCK, 'PURCHASE_REQUEST' => R::PLAN, 'INVESTIGATION' => R::INVESTIGATE, 'TASK' => R::VIEW];

    public function __construct(private readonly WarehouseAssistant $assistant, private readonly WarehouseNarrator $narrator) {}

    public function ask(string $question, User $user): object
    {
        $started = microtime(true);
        $answer = $this->assistant->answer($question, $user);
        $words = $this->narrator->narrate($answer);
        $uuid = (string) Str::uuid();
        DB::table('wms_assistant_queries')->insert(['uuid' => $uuid, 'company_id' => $user->company_id, 'user_id' => $user->id, 'question' => mb_substr($question, 0, 2000),
            'topic' => $answer['topic'], 'refused' => $answer['refused'], 'answer' => json_encode($answer), 'narration' => $words['text'], 'narration_source' => $words['source'],
            'latency_ms' => (int) round((microtime(true) - $started) * 1000), 'tokens' => $words['tokens'], 'cost' => $words['cost'], 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('wms_assistant_queries')->where('company_id', app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id())->where('uuid', $uuid)->first();
    }

    public function draft(string $queryUuid, int $recIndex, string $kind, User $user): object
    {
        $q = DB::table('wms_assistant_queries')->where('company_id', app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id())->where('uuid', $queryUuid)->where('user_id', $user->id)->first()
            ?? throw new RuntimeException('Draft from your own questions.');
        // The assistant drafts; it never approves, posts or writes anything off (QA-025). Every refusal is audited.
        $refuse = function (string $why) use ($kind, $user) {
            app(WarehouseAudit::class)->record((int) $user->company_id, 'assistant.refused', 'ASSISTANT', $kind, "Assistant action refused: {$why}", null, ['kind' => $kind], $user);
            throw new RuntimeException($why);
        };
        if (! isset(self::NEEDS[$kind])) {
            $refuse('The assistant only drafts counts, transfers, purchase requests, investigations and tasks; approving, posting or writing off is done by a person on the record itself.');
        }
        $rec = json_decode($q->answer, true)['recommendations'][$recIndex] ?? throw new RuntimeException('No such recommendation.');
        if (! in_array($kind, $rec['actions'] ?? [], true)) {
            $refuse('That is not one of the drafts this recommendation offers.');
        }
        if (! $user->can(self::NEEDS[$kind])) {
            $refuse('Drafting that needs a permission you do not have; the assistant cannot do more than you can.');
        }
        $key = "q{$q->id}:r{$recIndex}:{$kind}";
        if ($existing = DB::table('wms_assistant_actions')->where('idempotency_key', $key)->first()) {
            return $existing;
        }

        return DB::transaction(function () use ($q, $recIndex, $kind, $rec, $user, $key) {
            [$type, $ref] = match ($kind) {
                'COUNT' => $this->openCount($rec['payload'], $user),
                'TRANSFER_DRAFT' => $this->draftTransfer($rec['payload'], $user),
                'INVESTIGATION' => ['LOSS_FINDING', app(ProtectionCases::class)->open(['title' => mb_substr($rec['text'], 0, 200),
                    'description' => "From the warehouse assistant.\n\n{$rec['reason']}\n\n{$rec['impact']}", 'priority' => 'MEDIUM'], $user)->number],
                'PURCHASE_REQUEST' => ['PURCHASE_REQUEST', app(Replenishment::class)->raiseRequest($rec['payload']['lines'] ?? [], $user, 'From the warehouse assistant')->request_number],
                default => [null, null],
            };
            $uuid = (string) Str::uuid();
            DB::table('wms_assistant_actions')->insert(['uuid' => $uuid, 'company_id' => $q->company_id, 'query_id' => $q->id, 'rec_index' => $recIndex, 'kind' => $kind,
                'status' => $type ? 'EXECUTED' : 'DRAFT', 'summary' => $rec['text'], 'payload' => json_encode($rec['payload']), 'result_type' => $type, 'result_reference' => $ref,
                'idempotency_key' => $key, 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);

            return DB::table('wms_assistant_actions')->where('company_id', app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id())->where('uuid', $uuid)->first();
        });
    }

    public function withdraw(string $actionUuid, User $user, string $reason): void
    {
        $a = DB::table('wms_assistant_actions')->where('company_id', app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id())->where('uuid', $actionUuid)->first() ?? throw new RuntimeException('No such draft.');
        if ((int) $a->created_by !== $user->id) {
            throw new RuntimeException('Only whoever drafted it can withdraw it.');
        }
        if ($a->result_type === 'TRANSFER_ORDER') {
            $order = \App\Modules\Operations\WarehouseManagement\Models\TransferOrder::query()->where('number', $a->result_reference)->first();
            if ($order && $order->status === 'DRAFT') {
                app(TransferOrders::class)->cancel($order, $user, "Withdrawn from the assistant: {$reason}");
            } elseif ($order) {
                throw new RuntimeException("{$order->number} has moved on from draft; cancel it on the transfer itself.");
            }
        }
        DB::table('wms_assistant_actions')->where('id', $a->id)->update(['status' => 'CANCELLED', 'decided_by' => $user->id, 'decided_at' => now(), 'notes' => $reason, 'updated_at' => now()]);
    }

    public function feedback(string $queryUuid, ?int $recIndex, string $verdict, ?string $note, User $user): void
    {
        if (! in_array($verdict, ['CORRECT', 'INCORRECT', 'USEFUL', 'NOT_USEFUL'], true)) {
            throw new RuntimeException('Say whether it was correct, incorrect, useful or not useful.');
        }
        if ($verdict === 'INCORRECT' && blank($note)) {
            throw new RuntimeException('Say what was wrong, so it can be put right.');
        }
        $q = DB::table('wms_assistant_queries')->where('company_id', app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id())->where('uuid', $queryUuid)->first() ?? throw new RuntimeException('No such answer.');
        DB::table('wms_assistant_feedback')->insert(['company_id' => $q->company_id, 'query_id' => $q->id, 'rec_index' => $recIndex, 'verdict' => $verdict,
            'note' => $note, 'user_id' => $user->id, 'created_at' => now()]);
    }

    /** WMS-144: accuracy, usefulness, false alerts, missed events, latency and cost. */
    public function quality(int $companyId, string $from, string $to): array
    {
        $range = [$from.' 00:00:00', $to.' 23:59:59'];
        $q = DB::table('wms_assistant_queries')->where('company_id', $companyId)->whereBetween('created_at', $range);
        $total = (clone $q)->count();
        $fb = DB::table('wms_assistant_feedback')->where('company_id', $companyId)->whereBetween('created_at', $range)->groupBy('verdict')->selectRaw('verdict, COUNT(*) AS n')->pluck('n', 'verdict');
        $latency = (clone $q)->selectRaw('percentile_cont(0.5) WITHIN GROUP (ORDER BY latency_ms) AS p50, percentile_cont(0.9) WITHIN GROUP (ORDER BY latency_ms) AS p90')->first();
        $findings = DB::table('mfg_loss_findings')->where('company_id', $companyId)->where('category', 'like', 'WH\_%')->whereBetween('detected_at', $range);
        $raised = (clone $findings)->where('origin', 'RULE')->count();
        $dismissed = (clone $findings)->where('origin', 'RULE')->where('status', 'DISMISSED')->count();
        // A confirmed loss no detector raised beforehand is a missed event.
        $missed = DB::table('wms_stock_adjustments as a')->join('wms_reason_codes as r', 'r.id', '=', 'a.reason_code_id')->where('a.company_id', $companyId)
            ->where('a.status', 'POSTED')->where('a.qty_delta', '<', 0)->whereIn('r.category', ['LOSS'])->whereBetween('a.occurred_at', $range)
            ->whereNotExists(fn ($x) => $x->from('mfg_loss_findings as f')->where('f.category', 'like', 'WH\_%')->where('f.subject_type', 'STOCK_ITEM')
                ->whereColumn('f.subject_id', 'a.item_id')->whereColumn('f.detected_at', '<=', 'a.created_at'))->count();
        $acted = DB::table('wms_assistant_actions')->where('company_id', $companyId)->whereBetween('created_at', $range)->where('status', '!=', 'CANCELLED')->count();

        return [
            'questions' => $total, 'refused' => (clone $q)->where('refused', true)->count(), 'worded_by_model' => (clone $q)->where('narration_source', 'MODEL')->count(),
            'accuracy' => ($fb['CORRECT'] ?? 0) + ($fb['INCORRECT'] ?? 0) ? round(($fb['CORRECT'] ?? 0) / (($fb['CORRECT'] ?? 0) + ($fb['INCORRECT'] ?? 0)) * 100, 1) : null,
            'flagged_incorrect' => (int) ($fb['INCORRECT'] ?? 0),
            'usefulness' => ($fb['USEFUL'] ?? 0) + ($fb['NOT_USEFUL'] ?? 0) ? round(($fb['USEFUL'] ?? 0) / (($fb['USEFUL'] ?? 0) + ($fb['NOT_USEFUL'] ?? 0)) * 100, 1) : null,
            'drafts_made' => $acted, 'false_alert_rate' => $raised ? round($dismissed / $raised * 100, 1) : null, 'alerts_raised' => $raised, 'missed_events' => $missed,
            'latency_p50_ms' => $latency->p50 !== null ? (int) $latency->p50 : null, 'latency_p90_ms' => $latency->p90 !== null ? (int) $latency->p90 : null,
            'tokens' => (int) (clone $q)->sum('tokens'), 'cost' => round((float) (clone $q)->sum('cost'), 2),
        ];
    }

    private function openCount(array $payload, User $user): array
    {
        $item = FinStockItem::query()->findOrFail($payload['item_id']);
        $warehouse = FinWarehouse::query()->whereIn('id', DB::table('fin_stock_balances')->where('item_id', $item->id)->where('qty', '>', 0)->select('warehouse_id'))->first()
            ?? throw new RuntimeException("No warehouse holds {$item->code} to count.");
        $count = app(Counting::class)->open(['warehouse_id' => $warehouse->id, 'scope' => 'ITEMS', 'scope_ids' => [$item->id], 'blind' => true,
            'reason' => "Variance on {$item->code}, from the warehouse assistant", 'trigger' => 'MANUAL'], $user);

        return ['COUNT', $count->number];
    }

    private function draftTransfer(array $payload, User $user): array
    {
        $from = FinWarehouse::query()->where('code', $payload['from'] ?? '')->firstOrFail();
        $to = FinWarehouse::query()->where('code', $payload['to'] ?? '')->first();
        if (! $to) {
            throw new RuntimeException('Choose where the stock should go; then draft the transfer on the transfers screen.');
        }
        $lines = array_map(fn ($l) => ['item_id' => FinStockItem::query()->where('code', $l['item'])->firstOrFail()->id, 'qty' => (float) $l['qty']], $payload['lines'] ?? []);
        if ($lines === []) {
            throw new RuntimeException('Nothing is missing to transfer.');
        }
        $order = app(TransferOrders::class)->draft($from, $to, $lines, $user, ['reason' => 'Drafted from the warehouse assistant'.(! empty($payload['order']) ? " for {$payload['order']}" : '')]);

        return ['TRANSFER_ORDER', $order->number];
    }
}
