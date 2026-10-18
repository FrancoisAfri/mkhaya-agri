<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Contracts\FinancialEvent;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Models\FinStockMovement;
use App\Modules\Finance\Accounting\Services\InventoryService;
use App\Modules\Finance\Accounting\Services\Ledger;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\Manufacturing\Models\AccountingIssue;
use App\Modules\Operations\Manufacturing\Models\AccountingRule as Rule;
use App\Modules\Operations\Manufacturing\Models\CostRecord;
use App\Modules\Operations\Manufacturing\Models\FactoryPolicy;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\ProductionOrderComponent;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Manufacturing's side of the books (MFG-021).
 *
 * Each manufacturing event has a treatment, defined per company and approved by finance: POST (the
 * stock ledger or this class writes a journal between named accounts), MEMO (kept in the
 * manufacturing cost subledger only) or NONE. The defaults describe what this installation does
 * today. A suspended rule stops the event; a POST rule not yet approved stops it too, so nothing is
 * posted automatically without a treatment somebody signed off.
 *
 * Every accounting-relevant event is written to the cost subledger with its source document.
 * Reconciliation compares that subledger and the stock movements with the general ledger and fills
 * an exception queue with whatever does not agree.
 */
class ManufacturingAccounting
{
    /** event => [treatment, debit, credit, what happens] -- the stock ledger's existing behaviour. */
    public const EVENTS = [
        'MATERIAL_ISSUE' => ['POST', '1310', 'INVENTORY', 'Material issued to an order: Dr Work in Progress, Cr the item\'s inventory account, at issue cost. Posted by the stock ledger.'],
        'MATERIAL_RETURN' => ['POST', 'INVENTORY', '1310', 'Unused material returned: Dr inventory, Cr Work in Progress, at the cost it was issued at. Posted by the stock ledger.'],
        'OUTPUT_RECEIPT' => ['POST', 'INVENTORY', '1310', 'Finished output received: Dr the product\'s inventory account, Cr Work in Progress, at standard material cost. Posted by the stock ledger.'],
        'SCRAP' => ['POST', '5610', 'INVENTORY', 'Stock scrapped after a quality decision: Dr Scrap & Spoilage, Cr inventory. Posted by the stock ledger.'],
        'ORDER_CLOSE' => ['POST', '5600', '1310', 'Order closed: whatever is left in Work in Progress goes to Production Variance (or back, when negative), so a closed order holds no WIP.'],
        'LABOUR_ABSORPTION' => ['MEMO', null, null, 'Eligible labour (logged minutes at each centre\'s labour rate) is kept in the cost subledger. Payroll already expenses wages; posting absorption needs accounts approved by finance.'],
        'OVERHEAD_ABSORPTION' => ['MEMO', null, null, 'Overhead at the factory\'s overhead percentage of labour and machine cost, kept in the cost subledger until finance approves accounts to post it to.'],
        'SUBCONTRACT_COST' => ['MEMO', null, null, 'Subcontracted work at the agreed price, kept on the order. The supplier\'s invoice is posted by accounts payable, never here.'],
        'INTER_FACTORY_TRANSFER' => ['NONE', null, null, 'Stock moved between factories\' warehouses changes place, not value: no journal.'],
        'STOCK_REVERSAL' => ['POST', 'ORIGINAL', 'ORIGINAL', 'An authorised reversal of a manufacturing stock movement posts the exact opposite journal of the original.'],
    ];

    private const MOVEMENT_EVENT = ['mfg_issue' => 'MATERIAL_ISSUE', 'mfg_return' => 'MATERIAL_RETURN', 'mfg_receipt' => 'OUTPUT_RECEIPT', 'scrap' => 'SCRAP'];

    public function __construct(
        private readonly Ledger $ledger,
        private readonly InventoryService $inventory,
        private readonly StockLedger $stock,
    ) {}

    // ── Rules ────────────────────────────────────────────────────────────────────────────────

    /** Every event gets its treatment together: none is ever left undefined. */
    public function install(int $companyId): void
    {
        if (Rule::query()->where('company_id', $companyId)->count() >= count(self::EVENTS)) {
            return;
        }
        foreach (array_keys(self::EVENTS) as $event) {
            $this->define($companyId, $event);
        }
    }

    public function rule(int $companyId, string $event): Rule
    {
        $this->install($companyId);

        return Rule::query()->where('company_id', $companyId)->where('event_type', $event)->firstOrFail();
    }

    private function define(int $companyId, string $event): Rule
    {
        [$treatment, $debit, $credit, $text] = self::EVENTS[$event];

        return Rule::query()->firstOrCreate(['company_id' => $companyId, 'event_type' => $event], [
            'treatment' => $treatment, 'debit_account_code' => $debit, 'credit_account_code' => $credit, 'description' => $text,
            // What the stock ledger already does is installed as approved; anything new starts as a memo.
            'status' => 'APPROVED', 'approved_at' => now(), 'notes' => 'Installed as this system\'s existing treatment.',
        ]);
    }

    /** The event may go ahead only under a treatment that is in force. */
    public function assertAllowed(int $companyId, string $event): Rule
    {
        $rule = $this->rule($companyId, $event);
        if ($rule->status === 'SUSPENDED') {
            throw new DomainException("The accounting treatment for ".strtolower(str_replace('_', ' ', $event)).' is suspended; finance must reinstate it first.');
        }
        if ($rule->treatment === Rule::POST && $rule->status !== 'APPROVED') {
            throw new DomainException("The accounting treatment for ".strtolower(str_replace('_', ' ', $event)).' has not been approved, so it cannot post.');
        }

        return $rule;
    }

    /** Change a rule. A posting rule needs both accounts; any change goes back to draft until approved. */
    public function updateRule(Rule $rule, array $data, User $actor): Rule
    {
        if (($data['treatment'] ?? $rule->treatment) === Rule::POST && (blank($data['debit_account_code'] ?? $rule->debit_account_code) || blank($data['credit_account_code'] ?? $rule->credit_account_code))) {
            throw new DomainException('A posting treatment names the account debited and the account credited.');
        }
        foreach (['debit_account_code', 'credit_account_code'] as $k) {
            $code = $data[$k] ?? null;
            // "The item's inventory account" and "the original journal" only mean something where the stock ledger posts.
            if (in_array($code, ['INVENTORY', 'ORIGINAL'], true) && ! in_array($rule->event_type, ['MATERIAL_ISSUE', 'MATERIAL_RETURN', 'OUTPUT_RECEIPT', 'SCRAP', 'STOCK_REVERSAL'], true)) {
                throw new DomainException('Name a real account for this event.');
            }
            if ($code && ! in_array($code, ['INVENTORY', 'ORIGINAL'], true) && ! DB::table('fin_accounts')->where('code', $code)->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $rule->company_id))->exists()) {
                throw new DomainException("There is no account {$code} in the chart.");
            }
        }
        $rule->update(array_intersect_key($data, array_flip(['treatment', 'debit_account_code', 'credit_account_code', 'notes'])) + [
            'status' => 'DRAFT', 'approved_by' => null, 'approved_at' => null, 'changed_by' => $actor->id]);
        $this->audit($rule, 'ACCOUNTING_RULE_CHANGED', $actor, $data);

        return $rule->refresh();
    }

    public function approveRule(Rule $rule, User $actor): Rule
    {
        if ($rule->changed_by && (int) $rule->changed_by === (int) $actor->id) {
            throw new DomainException('The person who changed an accounting treatment cannot also approve it.');
        }
        $rule->update(['status' => 'APPROVED', 'approved_by' => $actor->id, 'approved_at' => now()]);
        $this->audit($rule, 'ACCOUNTING_RULE_APPROVED', $actor, []);

        return $rule->refresh();
    }

    public function suspendRule(Rule $rule, User $actor, string $reason): Rule
    {
        $rule->update(['status' => 'SUSPENDED', 'notes' => $reason]);
        $this->audit($rule, 'ACCOUNTING_RULE_SUSPENDED', $actor, ['reason' => $reason]);

        return $rule->refresh();
    }

    // ── Subledger ────────────────────────────────────────────────────────────────────────────

    /** A manufacturing stock movement, into the cost subledger with its journal. Safe to call twice. */
    public function recordMovement(FinStockMovement $m, PO $order, ?User $actor): ?CostRecord
    {
        $event = self::MOVEMENT_EVENT[$m->type] ?? null;
        if (! $event) {
            return null;
        }

        return $this->record($order->company_id, $event, $order->id, abs((float) $m->value), abs((float) $m->qty),
            ['stock_movement_id' => $m->id, 'journal_id' => $m->journal_id, 'source_type' => 'STOCK_MOVEMENT', 'source_id' => $m->id,
                'reference' => $m->reference ?? $order->number, 'reversal_of_id' => null],
            "movement:{$m->id}", $actor);
    }

    public function record(int $companyId, string $event, ?int $orderId, float $amount, ?float $qty, array $source, string $key, ?User $actor, ?string $basis = null): CostRecord
    {
        $rule = $this->rule($companyId, $event);

        return CostRecord::query()->firstOrCreate(['company_id' => $companyId, 'idempotency_key' => $key], $source + [
            'production_order_id' => $orderId, 'event_type' => $event, 'treatment' => $rule->treatment, 'amount' => round($amount, 2),
            'qty' => $qty, 'basis' => $basis, 'actor_id' => $actor?->id, 'booked_on' => now()->toDateString(),
        ]);
    }

    /**
     * Eligible labour and overhead for an order: logged labour minutes at each centre's labour rate
     * (subcontracted operations excluded), and the factory's overhead percentage of labour and
     * machine cost.
     *
     * @return array{labour: float, machine: float, overhead: float, labour_basis: string, overhead_basis: string}
     */
    public function allocations(PO $order): array
    {
        $labour = 0.0;
        $machine = 0.0;
        $minutes = 0.0;
        foreach ($order->operations()->with('workCentre')->get() as $op) {
            if ($op->is_subcontracted) {
                continue;
            }
            $minutes += (float) $op->actual_labour_minutes;
            $labour += (float) $op->actual_labour_minutes / 60 * (float) ($op->workCentre?->labour_rate_per_hour ?? 0);
            $machine += (float) $op->actual_machine_minutes / 60 * (float) ($op->workCentre?->cost_per_hour ?? 0);
        }
        $percent = (float) (FactoryPolicy::for($order->factory_id, $order->company_id)->overhead_percent ?? 0);

        return ['labour' => round($labour, 2), 'machine' => round($machine, 2), 'overhead' => round(($labour + $machine) * $percent / 100, 2),
            'labour_basis' => sprintf('%s labour minutes at the centres\' labour rates', $this->num($minutes)),
            'overhead_basis' => sprintf('%s%% of labour and machine cost', $this->num($percent))];
    }

    /** At close: absorb labour and overhead under their treatment, and book the WIP settlement. */
    public function close(PO $order, ?int $closeJournalId, float $residual, ?User $actor): void
    {
        $this->record($order->company_id, 'ORDER_CLOSE', $order->id, abs($residual), null,
            ['journal_id' => $closeJournalId, 'source_type' => 'PRODUCTION_ORDER', 'source_id' => $order->id, 'reference' => $order->number],
            "close:{$order->id}", $actor, $residual >= 0 ? 'Residual WIP to Production Variance' : 'Negative WIP back from Production Variance');

        $a = $this->allocations($order);
        foreach (['LABOUR_ABSORPTION' => [$a['labour'], $a['labour_basis']], 'OVERHEAD_ABSORPTION' => [$a['overhead'], $a['overhead_basis']]] as $event => [$amount, $basis]) {
            $rule = $this->assertAllowed($order->company_id, $event);
            if ($rule->treatment === Rule::NONE || $amount < 0.005) {
                continue;
            }
            $journalId = null;
            if ($rule->treatment === Rule::POST) {
                $journalId = $this->ledger->post(new FinancialEvent(
                    sourceModule: 'manufacturing', eventType: 'production.'.strtolower($event),
                    debits: [['account' => $this->inventory->accountIdFor($rule->debit_account_code), 'amount' => $amount, 'narration' => "{$event} {$order->number}"]],
                    credits: [['account' => $this->inventory->accountIdFor($rule->credit_account_code), 'amount' => $amount, 'narration' => "{$event} {$order->number}"]],
                    eventDate: now(), companyId: $order->company_id, sourceRef: "production_order:{$order->id}",
                    description: str_replace('_', ' ', ucfirst(strtolower($event)))." — {$order->number}", idempotencyKey: "mfg-absorb:{$event}:{$order->id}",
                ))->id;
            }
            $this->record($order->company_id, $event, $order->id, $amount, null,
                ['journal_id' => $journalId, 'source_type' => 'PRODUCTION_ORDER', 'source_id' => $order->id, 'reference' => $order->number],
                "absorb:{$event}:{$order->id}", $actor, $basis);
        }
    }

    /**
     * Before anything posts: is the period open? A closed period is refused here, with an exception
     * raised for finance, rather than failing half-way through.
     */
    public function assertPeriodOpen(int $companyId, ?Carbon $date = null, ?PO $order = null): void
    {
        $date ??= now();
        $period = FinFiscalPeriod::forDate($date, $companyId);
        // No period at all is the ledger's question: it refuses a journal there, and nothing else needs a period.
        if (! $period || $period->acceptsPostings()) {
            return;
        }
        $label = "{$period->fiscal_year}/{$period->period_no} is {$period->status}";
        $this->raise($companyId, 'PERIOD_CLOSED', 'HIGH', $order, "Posting refused: {$label}. Use the period-correction process.", null,
            ['date' => $date->toDateString()], 'PERIOD_CLOSED:'.($order?->id ?? 'x').':'.$date->toDateString());

        throw new DomainException("The accounting period is closed ({$label}). Reopen it through finance's period-correction process, or post in an open period.");
    }

    // ── Reversals ────────────────────────────────────────────────────────────────────────────

    /**
     * An authorised reversal of a manufacturing stock movement: the original stays, an opposite
     * movement and journal are posted, and the order's quantities follow.
     */
    public function reverse(FinStockMovement $m, string $reason, User $actor): FinStockMovement
    {
        if (blank($reason)) {
            throw new DomainException('A reversal says why.');
        }
        if ($m->source_type !== PO::SOURCE_TYPE || ! isset(self::MOVEMENT_EVENT[$m->type]) || $m->type === 'scrap') {
            throw new DomainException('Only a production issue, return or receipt is reversed here.');
        }
        if ($m->reversal_of_id !== null || FinStockMovement::withoutGlobalScopes()->where('reversal_of_id', $m->id)->exists()) {
            throw new DomainException('That movement has already been reversed, or is itself a reversal.');
        }
        $order = PO::query()->findOrFail($m->source_id);
        if (in_array($order->status, [PO::CLOSED, PO::CANCELLED], true)) {
            throw new DomainException("{$order->number} is ".strtolower($order->status).': correct it with an adjustment in an open period instead.');
        }
        $this->assertAllowed($order->company_id, 'STOCK_REVERSAL');
        $this->assertPeriodOpen($order->company_id, null, $order);

        return DB::transaction(function () use ($m, $reason, $actor, $order) {
            $rev = $this->stock->reverse($m, $reason, "mfg-reverse:{$m->id}");
            $qty = abs((float) $m->qty);
            $value = abs((float) $m->value);
            $component = $m->type !== 'mfg_receipt'
                ? ProductionOrderComponent::query()->where('order_id', $order->id)->where('stock_item_id', $m->item_id)->first() : null;
            match ($m->type) {
                'mfg_issue' => $component?->update(['issued_qty' => round((float) $component->issued_qty - $qty, 6), 'issued_value' => round((float) $component->issued_value - $value, 2)]),
                'mfg_return' => $component?->update(['returned_qty' => round((float) $component->returned_qty - $qty, 6)]),
                'mfg_receipt' => PO::query()->whereKey($order->id)->update(['qty_completed' => DB::raw('qty_completed - '.$qty)]),
            };
            $original = CostRecord::query()->where('stock_movement_id', $m->id)->first();
            $this->record($order->company_id, 'STOCK_REVERSAL', $order->id, $value, $qty,
                ['stock_movement_id' => $rev->id, 'journal_id' => $rev->journal_id, 'source_type' => 'STOCK_MOVEMENT', 'source_id' => $m->id,
                    'reference' => $order->number, 'reversal_of_id' => $original?->id],
                "reverse:{$m->id}", $actor, "Reversal of {$m->type}: {$reason}");
            app(ProductionOrders::class)->event($order->fresh(), 'MOVEMENT_REVERSED', $order->status, $order->status, $actor, $reason,
                ['movement' => $m->uuid, 'type' => $m->type, 'qty' => $qty, 'value' => $value], true);

            return $rev;
        });
    }

    // ── Reconciliation ───────────────────────────────────────────────────────────────────────

    /**
     * Manufacturing against the general ledger. Work in progress in the subledger (every open
     * order's issues less returns less receipts) against the WIP account; production movements
     * that carry value but no journal; closed orders still holding WIP; movements missing from the
     * cost subledger. Each disagreement is an exception; one that has cleared is resolved.
     */
    public function reconcile(int $companyId, ?Carbon $asOf = null): array
    {
        $asOf ??= now();
        $lifecycle = app(ProductionOrderLifecycle::class);
        $wipAccount = $this->inventory->accountIdFor(config('finance.wip_account', '1310'));
        $gl = (float) DB::table('fin_journal_lines as l')->join('fin_journals as j', 'j.id', '=', 'l.journal_id')
            ->where('l.company_id', $companyId)->where('l.account_id', $wipAccount)
            // A reversed journal still happened; its reversal is a journal of its own.
            ->whereIn('j.status', ['posted', 'reversed'])
            ->whereDate('j.journal_date', '<=', $asOf->toDateString())->sum(DB::raw('l.debit - l.credit'));

        $orders = PO::query()->whereIn('id', DB::table('fin_stock_movements')->where('company_id', $companyId)->where('source_type', PO::SOURCE_TYPE)->select('source_id'))
            ->orWhereIn('status', [PO::RELEASED, PO::IN_PRODUCTION, PO::QUALITY_REVIEW, PO::COMPLETED])->get();
        // An order's WIP: its stock movements, plus whatever its own journals (settlement at close,
        // absorption posted to WIP) put on the WIP account.
        $settled = DB::table('mfg_cost_records as c')->join('fin_journal_lines as l', 'l.journal_id', '=', 'c.journal_id')
            ->where('c.company_id', $companyId)->whereNull('c.stock_movement_id')->where('l.account_id', $wipAccount)
            ->groupBy('c.production_order_id')->selectRaw('c.production_order_id, SUM(l.debit - l.credit) AS amount')->pluck('amount', 'production_order_id');
        $rows = $orders->map(fn (PO $o) => ['order' => $o->number, 'id' => $o->uuid, 'status' => $o->status,
            'wip' => round($lifecycle->wipBalance($o) + (float) ($settled[$o->id] ?? 0), 2), 'order_id' => $o->id])
            ->filter(fn ($r) => abs($r['wip']) >= 0.01)->values();
        $sub = round((float) $rows->sum('wip'), 2);
        $seen = [];

        if (abs($gl - $sub) >= 0.01) {
            $seen[] = $this->raise($companyId, 'WIP_DIFFERENCE', 'HIGH', null, sprintf('Work in progress is %s in the general ledger and %s in manufacturing: a difference of %s.',
                $this->money($gl), $this->money($sub), $this->money($gl - $sub)), $gl - $sub, ['gl' => $gl, 'subledger' => $sub], 'WIP_DIFFERENCE');
        }
        foreach ($rows->filter(fn ($r) => $r['status'] === PO::CLOSED) as $r) {
            $seen[] = $this->raise($companyId, 'CLOSED_ORDER_WIP', 'HIGH', PO::query()->find($r['order_id']), "{$r['order']} is closed but still holds {$this->money($r['wip'])} of WIP.",
                $r['wip'], [], "CLOSED_ORDER_WIP:{$r['order_id']}");
        }
        $unposted = FinStockMovement::withoutGlobalScopes()->where('company_id', $companyId)->where('source_type', PO::SOURCE_TYPE)
            ->whereIn('type', array_keys(self::MOVEMENT_EVENT))->whereNull('journal_id')->whereRaw('ABS(value) >= 0.01')->get();
        foreach ($unposted as $m) {
            $seen[] = $this->raise($companyId, 'MOVEMENT_NOT_POSTED', 'HIGH', PO::query()->find($m->source_id),
                "A {$m->type} of {$this->money(abs((float) $m->value))} ({$m->reference}) carries value but no journal.", abs((float) $m->value),
                ['movement' => $m->uuid], "MOVEMENT_NOT_POSTED:{$m->id}");
        }
        $since = CostRecord::query()->where('company_id', $companyId)->min('created_at');
        if ($since) {
            $missing = FinStockMovement::withoutGlobalScopes()->where('company_id', $companyId)->where('source_type', PO::SOURCE_TYPE)
                ->whereIn('type', array_keys(self::MOVEMENT_EVENT))->where('created_at', '>=', $since)
                ->whereNotExists(fn ($q) => $q->from('mfg_cost_records as c')->whereColumn('c.stock_movement_id', 'fin_stock_movements.id'))->get();
            foreach ($missing as $m) {
                $seen[] = $this->raise($companyId, 'SUBLEDGER_GAP', 'MEDIUM', PO::query()->find($m->source_id),
                    "A {$m->type} ({$m->reference}) is not in the manufacturing cost subledger.", abs((float) $m->value), ['movement' => $m->uuid], "SUBLEDGER_GAP:{$m->id}");
            }
        }

        // Exceptions that no longer hold have cleared.
        $cleared = AccountingIssue::query()->where('company_id', $companyId)->where('status', 'OPEN')->where('kind', '!=', 'PERIOD_CLOSED')
            ->whereNotIn('id', array_filter($seen))->get();
        foreach ($cleared as $c) {
            $c->update(['status' => 'RESOLVED', 'resolved_at' => now(), 'resolution' => 'Cleared on reconciliation.']);
        }

        return [
            'as_of' => $asOf->toDateString(), 'currency' => config('finance.base_currency', 'ZAR'),
            'wip' => ['general_ledger' => round($gl, 2), 'subledger' => $sub, 'difference' => round($gl - $sub, 2)],
            'orders' => $rows->map(fn ($r) => array_diff_key($r, ['order_id' => 1]))->all(),
            'unposted_movements' => $unposted->count(), 'cleared' => $cleared->count(),
            'open_exceptions' => AccountingIssue::query()->where('company_id', $companyId)->where('status', 'OPEN')->count(),
        ];
    }

    public function resolve(AccountingIssue $issue, string $status, string $resolution, User $actor): AccountingIssue
    {
        if ($issue->status !== 'OPEN') {
            throw new DomainException('It has already been dealt with.');
        }
        if (! in_array($status, ['RESOLVED', 'ACCEPTED'], true) || blank($resolution)) {
            throw new DomainException('Say how it was resolved, or why the difference is accepted.');
        }
        $issue->update(['status' => $status, 'resolved_by' => $actor->id, 'resolved_at' => now(), 'resolution' => $resolution]);

        return $issue->refresh();
    }

    // ── Internals ────────────────────────────────────────────────────────────────────────────

    private function raise(int $companyId, string $kind, string $severity, ?PO $order, string $message, ?float $amount, array $detail, string $key): ?int
    {
        $open = AccountingIssue::query()->where('company_id', $companyId)->where('dedupe_key', $key)->where('status', 'OPEN')->first();
        if ($open) {
            $open->update(['last_seen_at' => now(), 'message' => $message, 'amount' => $amount !== null ? round($amount, 2) : null, 'detail' => $detail]);

            return $open->id;
        }

        return AccountingIssue::create(['company_id' => $companyId, 'kind' => $kind, 'severity' => $severity, 'production_order_id' => $order?->id,
            'reference' => $order?->number, 'message' => $message, 'amount' => $amount !== null ? round($amount, 2) : null, 'detail' => $detail,
            'status' => 'OPEN', 'dedupe_key' => $key, 'last_seen_at' => now()])->id;
    }

    private function audit(Rule $rule, string $action, User $actor, array $data): void
    {
        activity()->causedBy($actor)->performedOn($rule)->withProperties(['event_type' => $rule->event_type, 'changes' => $data, 'company_id' => $rule->company_id])
            ->log("manufacturing.{$action}");
    }

    private function money(float $n): string
    {
        return config('finance.base_currency', 'ZAR').' '.number_format($n, 2);
    }

    private function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    }
}
