<?php

namespace App\Modules\Operations\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinStockMovement;
use App\Modules\Operations\Manufacturing\Models\AccountingIssue;
use App\Modules\Operations\Manufacturing\Models\AccountingRule;
use App\Modules\Operations\Manufacturing\Models\CostRecord;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Services\ManufacturingAccounting;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Manufacturing accounting controls (MFG-021): treatments, the cost subledger, reconciliation, reversals. */
class AccountingController extends Controller
{
    public function __construct(private readonly ManufacturingAccounting $accounting) {}

    public function rules(Request $request): Response
    {
        $this->authorize('viewAny', AccountingRule::class);
        $companyId = $this->tenantId($request);
        foreach (array_keys(ManufacturingAccounting::EVENTS) as $event) {
            $this->accounting->rule($companyId, $event);
        }
        $names = DB::table('users')->leftJoin('hr_people', 'hr_people.user_id', '=', 'users.id')
            ->whereIn('users.id', AccountingRule::query()->pluck('approved_by')->filter())
            ->selectRaw("users.id, COALESCE(NULLIF(TRIM(CONCAT(hr_people.first_name, ' ', hr_people.surname)), ''), users.email) AS display_name")->pluck('display_name', 'id');

        return Inertia::render('Modules/Manufacturing/Accounting/Rules', [
            'rules' => AccountingRule::query()->orderBy('id')->get()->map(fn (AccountingRule $r) => [
                'id' => $r->id, 'event_type' => $r->event_type, 'treatment' => $r->treatment, 'debit' => $r->debit_account_code,
                'credit' => $r->credit_account_code, 'description' => $r->description, 'status' => $r->status, 'notes' => $r->notes,
                'approved_by' => $r->approved_by ? ($names[$r->approved_by] ?? null) : null, 'approved_at' => $r->approved_at?->toDateString(),
            ])->values(),
            'accounts' => DB::table('fin_accounts')->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))
                ->where('is_active', true)->orderBy('code')->get(['code', 'name'])->map(fn ($a) => ['code' => $a->code, 'name' => "{$a->code} — {$a->name}"])->values(),
            'can' => ['manage' => $request->user()->can('manage', AccountingRule::class)],
        ]);
    }

    public function ruleAction(Request $request, int $id, string $step): RedirectResponse
    {
        $this->authorize('manage', AccountingRule::class);
        $rule = AccountingRule::query()->findOrFail($id);

        return $this->attempt(fn () => match ($step) {
            'update' => $this->accounting->updateRule($rule, $request->validate([
                'treatment' => ['required', Rule::in(['POST', 'MEMO', 'NONE'])],
                'debit_account_code' => ['nullable', 'string', 'max:20'], 'credit_account_code' => ['nullable', 'string', 'max:20'],
                'notes' => ['nullable', 'string', 'max:1000'],
            ]), $request->user()),
            'approve' => $this->accounting->approveRule($rule, $request->user()),
            'suspend' => $this->accounting->suspendRule($rule, $request->user(), (string) $request->validate(['reason' => ['required', 'string', 'max:500']])['reason']),
        }, 'Accounting treatment saved.');
    }

    public function reconciliation(Request $request): Response
    {
        $this->authorize('reconcile', AccountingRule::class);
        $report = $this->accounting->reconcile($this->tenantId($request));
        $state = $request->validate(['state' => ['nullable', Rule::in(['OPEN', 'RESOLVED', 'ACCEPTED', 'all'])]])['state'] ?? 'OPEN';

        return Inertia::render('Modules/Manufacturing/Accounting/Reconciliation', [
            'report' => $report,
            'exceptions' => AccountingIssue::query()->when($state !== 'all', fn ($q) => $q->where('status', $state))
                ->with('order:id,uuid,number')->orderByRaw("CASE severity WHEN 'HIGH' THEN 0 WHEN 'MEDIUM' THEN 1 ELSE 2 END")->latest('last_seen_at')->limit(500)->get()
                ->map(fn (AccountingIssue $e) => ['id' => $e->uuid, 'kind' => $e->kind, 'severity' => $e->severity, 'message' => $e->message, 'amount' => $e->amount,
                    'order' => $e->order ? ['id' => $e->order->uuid, 'number' => $e->order->number] : null, 'status' => $e->status,
                    'resolution' => $e->resolution, 'last_seen_at' => $e->last_seen_at?->toIso8601String()])->values(),
            'filters' => ['state' => $state],
            'can' => ['resolve' => $request->user()->can('adjust', AccountingRule::class)],
        ]);
    }

    public function resolve(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('adjust', AccountingRule::class);
        $issue = AccountingIssue::query()->where('uuid', $uuid)->firstOrFail();
        $d = $request->validate(['status' => ['required', Rule::in(['RESOLVED', 'ACCEPTED'])], 'resolution' => ['required', 'string', 'max:2000']]);

        return $this->attempt(fn () => $this->accounting->resolve($issue, $d['status'], $d['resolution'], $request->user()), 'Exception closed.');
    }

    /** The cost subledger for an order, each line with its stock movement and journal. */
    public function transactions(Request $request): Response
    {
        $this->authorize('transactions', AccountingRule::class);
        $number = $request->validate(['order' => ['nullable', 'string', 'max:40']])['order'] ?? null;
        $order = $number ? PO::query()->where(\Illuminate\Support\Str::isUuid($number) ? 'uuid' : 'number', $number)->first() : null;
        $records = CostRecord::query()->when($order, fn ($q) => $q->where('production_order_id', $order->id))
            ->with(['order:id,uuid,number', 'movement'])->latest('id')->limit(500)->get();
        $journals = DB::table('fin_journals')->whereIn('id', $records->pluck('journal_id')->merge($records->pluck('movement.journal_id'))->filter()->unique())
            ->pluck('journal_no', 'id');
        $reversed = FinStockMovement::withoutGlobalScopes()->whereIn('reversal_of_id', $records->pluck('stock_movement_id')->filter())->pluck('reversal_of_id')->all();

        return Inertia::render('Modules/Manufacturing/Accounting/Transactions', [
            'order' => $order ? ['id' => $order->uuid, 'number' => $order->number, 'status' => $order->status] : null,
            'records' => $records->map(fn (CostRecord $c) => [
                'id' => $c->id, 'event' => $c->event_type, 'treatment' => $c->treatment, 'amount' => $c->amount, 'qty' => $c->qty, 'basis' => $c->basis,
                'order' => $c->order ? ['id' => $c->order->uuid, 'number' => $c->order->number] : null, 'reference' => $c->reference,
                'movement' => $c->movement ? ['id' => $c->movement->uuid, 'type' => $c->movement->type, 'date' => (string) $c->movement->movement_date] : null,
                'journal' => $journals[$c->journal_id ?? $c->movement?->journal_id] ?? null, 'booked_on' => $c->booked_on?->toDateString(),
                'is_reversal' => $c->reversal_of_id !== null || $c->event_type === 'STOCK_REVERSAL',
                'reversible' => $c->movement && in_array($c->movement->type, ['mfg_issue', 'mfg_return', 'mfg_receipt'], true)
                    && ! in_array($c->stock_movement_id, $reversed, true) && $c->movement->reversal_of_id === null && $c->event_type !== 'STOCK_REVERSAL',
            ])->values(),
            'can' => ['reverse' => $request->user()->can('adjust', AccountingRule::class)],
        ]);
    }

    public function reverse(Request $request, string $movement): RedirectResponse
    {
        $this->authorize('adjust', AccountingRule::class);
        $m = FinStockMovement::query()->where('uuid', $movement)->firstOrFail();
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        return $this->attempt(fn () => $this->accounting->reverse($m, $reason, $request->user()), 'Reversed. The original stays on record.');
    }

    private function attempt(callable $do, string $ok): RedirectResponse
    {
        try {
            $do();
        } catch (DomainException|PostingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $ok);
    }

    private function tenantId(Request $request): int
    {
        return (int) (app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id() ?? $request->user()->company_id);
    }
}
