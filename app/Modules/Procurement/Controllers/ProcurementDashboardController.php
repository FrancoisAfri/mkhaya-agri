<?php

namespace App\Modules\Procurement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Budget\Models\Budget;
use App\Modules\Budget\Services\BudgetService;
use App\Modules\Procurement\Models\ProcurementApproval;
use App\Modules\Procurement\Models\ProcurementEscalation;
use App\Modules\Procurement\Models\ProcurementInsight;
use App\Modules\Procurement\Models\ProcurementRequisition;
use App\Modules\Procurement\Models\ProcurementRiskScore;
use App\Modules\Procurement\Models\PurchaseRequest;
use App\Modules\SupplierManagement\Models\PurchaseOrder;
use App\Modules\SupplierManagement\Models\SupplierInvoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Role-adaptive procurement dashboard. Resolves the viewer's role (overridable
 * via ?view=) and surfaces the widgets relevant to Employee / Manager /
 * Procurement / Finance / Executive, all over the shared P2P data.
 */
class ProcurementDashboardController extends Controller
{
    public function __construct(private readonly BudgetService $budgets) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PurchaseRequest::class);
        $user = Auth::user();
        $uid  = $user->id;

        $view = $request->input('view') ?: $this->resolveRole($user);

        return Inertia::render('Modules/Procurement/Dashboard', [
            'view'         => $view,
            'availableViews' => ['employee', 'manager', 'procurement', 'finance', 'executive'],
            'myRequests'   => $this->myRequests($uid),
            'pendingOnMe'  => $this->pendingOnMe($uid),
            'procurementQueue' => $this->procurementQueue(),
            'financeQueue' => $this->financeQueue(),
            'executive'    => $this->executive(),
            'escalations'  => $this->openEscalations(),
            'narrative'    => Cache::get('procurement.spend_narrative'),
        ]);
    }

    private function resolveRole($user): string
    {
        if (method_exists($user, 'hasAnyRole')) {
            if ($user->hasAnyRole(['executive', 'administrator', 'super admin'])) {
                return 'executive';
            }
            if ($user->hasAnyRole(['finance', 'accountant', 'finance manager'])) {
                return 'finance';
            }
            if ($user->hasAnyRole(['procurement', 'procurement officer', 'buyer'])) {
                return 'procurement';
            }
            if ($user->hasAnyRole(['manager', 'line manager', 'hod'])) {
                return 'manager';
            }
        }

        return 'employee';
    }

    private function myRequests(int $uid): array
    {
        return PurchaseRequest::where('requester_id', $uid)->orWhere('created_by', $uid)
            ->latest()->limit(8)->get(['id', 'request_number', 'status', 'estimated_total', 'currency'])
            ->map(fn ($r) => [
                'id' => $r->id, 'uuid' => $r->uuid, 'request_number' => $r->request_number, 'status' => $r->status,
                'estimated_total' => $r->estimated_total, 'currency' => $r->currency,
            ])->all();
    }

    private function pendingOnMe(int $uid): array
    {
        // Pending steps have no approver_id yet (it's only stamped on approval), so the
        // old approver_id filter always returned nothing. Match the CURRENT step whose
        // role the viewer holds (or that's explicitly assigned to them).
        $roles = \App\Core\User\Models\User::find($uid)?->roles->pluck('name')->all() ?? [];

        $rows = DB::table('procurement_approval_steps as s')
            ->join('procurement_approvals as a', 'a.id', '=', 's.procurement_approval_id')
            ->where('a.status', 'pending')
            ->whereColumn('s.step', 'a.current_step')
            ->where('s.status', 'pending')
            ->where(function ($q) use ($uid, $roles) {
                $q->where('s.approver_id', $uid);
                if ($roles) {
                    $q->orWhereIn('s.role', $roles);
                }
            })
            ->select('a.id as approval_id', 'a.source_type', 'a.source_id', 's.name as step', 's.created_at')
            ->orderBy('s.created_at')->limit(12)->get();

        return $rows->map(function ($r) {
            $ref = $r->source_type === PurchaseRequest::class || str_contains((string) $r->source_type, 'PurchaseRequest')
                ? DB::table('purchase_requests')->where('id', $r->source_id)->value('request_number')
                : null;

            return ['approval_id' => $r->approval_id, 'step' => $r->step, 'reference' => $ref ?: ('#'.$r->source_id), 'since' => $r->created_at];
        })->all();
    }

    private function procurementQueue(): array
    {
        return [
            'requisitions_open' => ProcurementRequisition::whereIn('status', ['open', 'sourcing', 'quoting'])->count(),
            'requisitions_awarded' => ProcurementRequisition::where('status', 'awarded')->count(),
            'po_in_flight' => PurchaseOrder::whereIn('status', ['draft', 'approved', 'sent'])->where('received_status', '!=', 'received')->count(),
            'deliveries_late' => PurchaseOrder::whereIn('status', ['approved', 'sent'])
                ->where('received_status', '!=', 'received')->whereNotNull('expected_delivery')
                ->whereDate('expected_delivery', '<', now())->count(),
            'recent_pos' => PurchaseOrder::with('supplier:id,company_name')->latest()->limit(6)
                ->get(['id', 'po_number', 'amount', 'currency', 'status', 'received_status', 'supplier_id'])
                ->map(fn ($p) => ['id' => $p->id, 'uuid' => $p->uuid, 'po_number' => $p->po_number, 'amount' => $p->amount,
                    'currency' => $p->currency, 'status' => $p->status, 'received_status' => $p->received_status,
                    'supplier' => $p->supplier?->company_name])->all(),
        ];
    }

    private function financeQueue(): array
    {
        return [
            'unmatched'  => SupplierInvoice::where('match_status', 'unmatched')->count(),
            'exceptions' => SupplierInvoice::where('match_status', 'exception')->count(),
            'to_reconcile' => SupplierInvoice::where('match_status', 'matched')->whereNull('reconciled_at')->count(),
            'to_pay'     => SupplierInvoice::where('payment_status', 'approved')->count(),
            'recent'     => SupplierInvoice::with('supplier:id,company_name')->latest()->limit(6)
                ->get(['id', 'invoice_number', 'amount', 'currency', 'status', 'match_status', 'payment_status', 'supplier_id'])
                ->map(fn ($i) => ['id' => $i->id, 'invoice_number' => $i->invoice_number, 'amount' => $i->amount,
                    'currency' => $i->currency, 'status' => $i->status, 'match_status' => $i->match_status,
                    'payment_status' => $i->payment_status, 'supplier' => $i->supplier?->company_name])->all(),
        ];
    }

    private function executive(): array
    {
        $totals = ['allocated' => 0.0, 'committed' => 0.0, 'actual' => 0.0, 'available' => 0.0];
        foreach (Budget::where('status', 'active')->get() as $b) {
            $a = $this->budgets->amounts($b);
            foreach ($totals as $k => $_) {
                $totals[$k] += $a[$k];
            }
        }
        array_walk($totals, fn (&$v) => $v = round($v, 2));

        $open = ProcurementInsight::open()->get();

        return [
            'budget_totals' => $totals,
            'overall_risk'  => ProcurementRiskScore::where('scope_type', 'overall')->first(),
            'open_findings' => $open->count(),
            'critical'      => $open->where('severity', 'critical')->count(),
            'savings'       => round((float) $open->where('category', 'saving')->sum('amount_impact'), 2),
            'forecast_overrun' => round((float) $open->where('category', 'forecast')->sum('amount_impact'), 2),
            'top_suppliers' => DB::table('purchase_orders')
                ->join('suppliers', 'suppliers.id', '=', 'purchase_orders.supplier_id')
                ->whereNull('purchase_orders.deleted_at')->where('purchase_orders.status', '!=', 'cancelled')
                ->select('suppliers.company_name', DB::raw('sum(purchase_orders.amount) as spend'))
                ->groupBy('suppliers.company_name')->orderByDesc('spend')->limit(5)->get()->all(),
        ];
    }

    private function openEscalations(): array
    {
        return ProcurementEscalation::where('status', 'open')->latest()->limit(10)
            ->get(['id', 'reason', 'created_at'])
            ->map(fn ($e) => ['id' => $e->id, 'reason' => $e->reason, 'at' => $e->created_at?->toDateTimeString()])->all();
    }
}
