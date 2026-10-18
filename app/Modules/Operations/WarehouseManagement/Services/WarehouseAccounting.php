<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Services\AccountMap;
use App\Modules\Operations\WarehouseManagement\Models\AccountingIssue;
use App\Modules\Operations\WarehouseManagement\Models\AccountingRule;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The warehouse's side of the books (WMS-101, WMS-102).
 *
 * Every inventory event has one owner and one treatment. Where Procurement or Finance already posts
 * an event, the warehouse records that it does and posts nothing, so no event is journalled twice.
 * The events the warehouse posts itself use accounts from Finance's account map; the two that move
 * value without moving stock -- landed costs and revaluations -- post only once finance has
 * approved their treatment, and a suspended rule stops its event.
 *
 * Reconciliation compares the stock ledger with itself (balances, positions, movements) and with the
 * general ledger (inventory value against the inventory accounts), and keeps a queue of what does
 * not agree. Exceptions that clear are resolved by the next run; the rest wait for a person.
 */
class WarehouseAccounting
{
    /** event => [owner, treatment, debit, credit, approved by default, what happens] */
    public const EVENTS = [
        'GOODS_RECEIPT' => ['PROCUREMENT', 'POST', 'INVENTORY', 'grni', true, 'A goods-received note posts Dr inventory, Cr Goods Received Not Invoiced. The supplier\'s bill later clears GRNI against payables.'],
        'DISPATCH' => ['WAREHOUSE', 'POST', 'cogs', 'INVENTORY', true, 'Stock leaving on a shipment posts Dr cost of sales, Cr inventory, at the item\'s cost.'],
        'INVOICE_RELIEF' => ['FINANCE', 'NONE', null, null, true, 'A sales invoice relieves stock only where the warehouse is not run by WHMI; for WHMI warehouses dispatch does it, so the sale is not costed twice.'],
        'ADJUSTMENT' => ['WAREHOUSE', 'POST', 'adjustment', 'INVENTORY', true, 'An approved stock adjustment or count variance posts between inventory and Inventory Adjustments, in the direction of the change.'],
        'CUSTOMER_RETURN' => ['WAREHOUSE', 'POST', 'INVENTORY', 'cogs', true, 'Goods returned by a customer post Dr inventory, Cr cost of sales, at the cost they left at. The credit note is Finance\'s.'],
        'SUPPLIER_RETURN' => ['WAREHOUSE', 'POST', 'grni', 'INVENTORY', true, 'Goods sent back to a supplier post Dr Goods Received Not Invoiced, Cr inventory.'],
        'SCRAP' => ['MANUFACTURING', 'POST', '5610', 'INVENTORY', true, 'Stock scrapped by an inspection, return or recall posts Dr Scrap & Spoilage, Cr inventory.'],
        'TRANSFER' => ['WAREHOUSE', 'NONE', null, null, true, 'Moving stock between bins or warehouses changes where it is, not what it is worth.'],
        'STATUS_CHANGE' => ['WAREHOUSE', 'NONE', null, null, true, 'Quarantine, hold, release and rejection change whether stock may be used, not its value.'],
        'CLIENT_STOCK' => ['WAREHOUSE', 'NONE', null, null, true, 'Stock held for a third-party client is counted but never valued: it is not this company\'s asset.'],
        'LANDED_COST' => ['WAREHOUSE', 'POST', 'INVENTORY', 'landed_cost_clearing', false, 'Freight, duty and other costs of bringing stock in post Dr inventory (or cost of sales for what has already gone), Cr Landed Cost Clearing.'],
        'REVALUATION' => ['WAREHOUSE', 'POST', 'INVENTORY', 'revaluation', false, 'A revaluation posts the change in value between inventory and Inventory Revaluation, after a second person approves it.'],
    ];

    public function __construct(private readonly AccountMap $accounts) {}

    public function install(int $companyId): void
    {
        foreach (self::EVENTS as $event => [$owner, $treatment, $debit, $credit, $approved, $text]) {
            AccountingRule::query()->firstOrCreate(['company_id' => $companyId, 'event' => $event], [
                'owner' => $owner, 'treatment' => $treatment, 'debit' => $debit, 'credit' => $credit, 'description' => $text,
                // What the system already did is installed as approved; new treatments wait for finance.
                'approved_at' => $approved ? now() : null,
            ]);
        }
    }

    public function rule(int $companyId, string $event): AccountingRule
    {
        $this->install($companyId);

        return AccountingRule::query()->where('event', $event)->firstOrFail();
    }

    /** Refuse an event whose treatment is suspended, or (for POST) not yet approved. */
    public function assertAllowed(int $companyId, string $event): AccountingRule
    {
        $rule = $this->rule($companyId, $event);
        if ($rule->suspended_at) {
            throw new RuntimeException("Posting of {$this->label($event)} is suspended: {$rule->suspended_reason}");
        }
        if ($rule->treatment === 'POST' && ! $rule->approved_at) {
            throw new RuntimeException("Finance has not yet approved how {$this->label($event)} is posted. Approve it under Warehouse accounting first.");
        }

        return $rule;
    }

    /** The account code for a rule side: the item's own inventory account, a mapped key, or a literal code. */
    public function code(string $side, ?string $inventoryCode = null): ?string
    {
        if ($side === 'INVENTORY') {
            return $inventoryCode;
        }
        if (ctype_digit($side)) {
            return $side;
        }

        return $this->accounts->codeFor('warehouse', $side, match ($side) {
            'adjustment' => config('finance.stock_adjustment', config('finance.cogs_account', '5000')),
            'cogs' => config('finance.cogs_account', '5000'), 'grni' => config('finance.grni_account', '2150'),
            default => null,
        });
    }

    public function approve(AccountingRule $rule, User $by): AccountingRule
    {
        $rule->update(['approved_by' => $by->id, 'approved_at' => now(), 'suspended_at' => null, 'suspended_reason' => null]);

        return $rule;
    }

    public function suspend(AccountingRule $rule, string $reason): AccountingRule
    {
        if (blank($reason)) {
            throw new RuntimeException('Say why posting is being suspended.');
        }
        if (in_array($rule->owner, ['PROCUREMENT', 'FINANCE', 'MANUFACTURING'], true)) {
            throw new RuntimeException("{$this->label($rule->event)} is posted by ".strtolower($rule->owner).'; suspend it there.');
        }
        $rule->update(['suspended_at' => now(), 'suspended_reason' => $reason]);

        return $rule;
    }

    // ── Reconciliation ───────────────────────────────────────────────────────────────────────

    /**
     * @return array{valuation: list<array>, unposted: int, quantity_mismatches: int, open: int, cleared: int}
     */
    public function reconcile(int $companyId): array
    {
        $seen = [];

        // 1. Inventory value by account: the stock subledger against the general ledger.
        $sub = $this->subledgerValue($companyId);
        $gl = DB::table('fin_journal_lines as l')->join('fin_journals as j', 'j.id', '=', 'l.journal_id')
            ->where('l.company_id', $companyId)->whereIn('l.account_id', array_keys($sub) ?: [0])->whereIn('j.status', ['posted', 'reversed'])
            ->groupBy('l.account_id')->selectRaw('l.account_id, SUM(l.debit - l.credit) AS balance')->pluck('balance', 'account_id');
        $valuation = [];
        foreach ($sub as $accountId => $value) {
            $code = DB::table('fin_accounts')->where('id', $accountId)->value('code');
            $ledger = round((float) ($gl[$accountId] ?? 0), 2);
            $valuation[] = ['account' => $code, 'stock' => round($value, 2), 'general_ledger' => $ledger, 'difference' => round($ledger - $value, 2)];
            if (abs($ledger - $value) >= 0.01) {
                $seen[] = $this->raise($companyId, 'VALUATION_DIFFERENCE', 'HIGH', "VALUATION:{$accountId}",
                    sprintf('Inventory account %s holds %s in the general ledger; the stock on hand is valued at %s. Difference %s.', $code, $this->money($ledger), $this->money($value), $this->money($ledger - $value)),
                    $ledger - $value, ['account' => $code]);
            }
        }

        // 2. Movements that carry value but reached no journal.
        $unposted = DB::table('fin_stock_movements as m')->join('fin_stock_items as i', 'i.id', '=', 'm.item_id')
            ->where('m.company_id', $companyId)->whereNull('m.journal_id')->whereRaw('ABS(m.value) >= 0.01')
            ->whereNotIn('m.type', ['transfer', 'status', 'relot'])->where(fn ($q) => $q->whereNull('i.is_valued')->orWhere('i.is_valued', true))
            ->get(['m.id', 'm.uuid', 'm.type', 'm.value', 'm.reference', 'i.code']);
        foreach ($unposted as $m) {
            $seen[] = $this->raise($companyId, 'MOVEMENT_NOT_POSTED', 'HIGH', "UNPOSTED:{$m->id}",
                "A {$m->type} of {$m->code} worth {$this->money(abs((float) $m->value))}".($m->reference ? " ({$m->reference})" : '').' has no journal.',
                abs((float) $m->value), ['movement' => $m->uuid]);
        }

        // 3. The quantity ledger against itself: balances are derived, and must equal what made them.
        $mismatches = 0;
        $rows = DB::select(<<<'SQL'
            SELECT b.item_id, b.warehouse_id, b.qty AS balance,
                   COALESCE((SELECT SUM(m.qty) FROM fin_stock_movements m WHERE m.item_id = b.item_id AND m.warehouse_id = b.warehouse_id), 0) AS moved,
                   COALESCE((SELECT SUM(p.qty) FROM fin_stock_positions p WHERE p.item_id = b.item_id AND p.warehouse_id = b.warehouse_id), 0) AS positioned
            FROM fin_stock_balances b WHERE b.company_id = ?
            SQL, [$companyId]);
        foreach ($rows as $r) {
            foreach (['moved' => 'the movements that made it add up to', 'positioned' => 'its bins and states add up to'] as $col => $words) {
                if (abs((float) $r->balance - (float) $r->{$col}) >= 0.0001) {
                    $mismatches++;
                    $code = DB::table('fin_stock_items')->where('id', $r->item_id)->value('code');
                    $wh = DB::table('fin_warehouses')->where('id', $r->warehouse_id)->value('code');
                    $seen[] = $this->raise($companyId, $col === 'moved' ? 'BALANCE_NOT_LEDGER' : 'POSITIONS_NOT_BALANCE', 'HIGH',
                        "{$col}:{$r->item_id}:{$r->warehouse_id}",
                        "{$code} in {$wh}: the balance says ".$this->qty($r->balance)."; {$words} ".$this->qty($r->{$col}).'.', 0,
                        ['item_id' => $r->item_id, 'warehouse_id' => $r->warehouse_id, 'balance' => (float) $r->balance, $col => (float) $r->{$col}]);
                }
            }
        }

        $cleared = AccountingIssue::query()->where('company_id', $companyId)->where('status', 'OPEN')->whereNotIn('id', array_filter($seen) ?: [0])->get();
        foreach ($cleared as $c) {
            $c->update(['status' => 'RESOLVED', 'resolved_at' => now(), 'resolution' => 'Cleared on reconciliation.']);
        }

        return ['valuation' => $valuation, 'unposted' => $unposted->count(), 'quantity_mismatches' => $mismatches,
            'open' => AccountingIssue::query()->where('company_id', $companyId)->where('status', 'OPEN')->count(), 'cleared' => $cleared->count()];
    }

    public function resolve(AccountingIssue $issue, string $status, string $resolution, User $by): AccountingIssue
    {
        if (! in_array($status, ['RESOLVED', 'ACCEPTED'], true)) {
            throw new RuntimeException('An exception is resolved (put right) or accepted (explained and left).');
        }
        if (blank($resolution)) {
            throw new RuntimeException('Record what was done or why it is accepted.');
        }
        $issue->update(['status' => $status, 'resolution' => $resolution, 'resolved_by' => $by->id, 'resolved_at' => now()]);

        return $issue;
    }

    /**
     * What the stock on hand is worth, by inventory account, by each item's costing method:
     * FIFO from its remaining layers, standard at standard cost, weighted average at its average.
     *
     * @return array<int, float> inventory account id => value
     */
    public function subledgerValue(int $companyId): array
    {
        $rows = DB::table('fin_stock_items as i')->where('i.company_id', $companyId)->where(fn ($q) => $q->whereNull('i.is_valued')->orWhere('i.is_valued', true))
            ->whereNotNull('i.inventory_account_id')
            ->selectRaw("i.inventory_account_id, SUM(CASE
                WHEN i.cost_method = 'fifo' THEN COALESCE((SELECT SUM(f.qty_remaining * f.unit_cost) FROM fin_stock_layers f WHERE f.item_id = i.id), 0)
                WHEN i.cost_method = 'standard' THEN i.qty_on_hand * i.standard_cost
                ELSE i.qty_on_hand * i.avg_cost END) AS value")
            ->groupBy('i.inventory_account_id')->pluck('value', 'inventory_account_id');

        return $rows->map(fn ($v) => (float) $v)->all();
    }

    private function raise(int $companyId, string $kind, string $severity, string $reference, string $message, float $amount, array $detail): int
    {
        $issue = AccountingIssue::query()->where('company_id', $companyId)->where('reference', $reference)->where('status', 'OPEN')->first();
        if ($issue) {
            $issue->update(['message' => $message, 'amount' => round($amount, 2), 'detail' => $detail, 'last_seen_at' => now()]);

            return $issue->id;
        }

        return AccountingIssue::create(['company_id' => $companyId, 'kind' => $kind, 'severity' => $severity, 'reference' => $reference,
            'message' => $message, 'amount' => round($amount, 2), 'detail' => $detail, 'status' => 'OPEN', 'last_seen_at' => now()])->id;
    }

    private function label(string $event): string
    {
        return strtolower(str_replace('_', ' ', $event));
    }

    private function money(float $v): string
    {
        return number_format($v, 2);
    }

    private function qty($v): string
    {
        return rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.');
    }
}
