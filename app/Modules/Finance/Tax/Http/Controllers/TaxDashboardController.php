<?php

namespace App\Modules\Finance\Tax\Http\Controllers;

use App\Modules\Finance\Tax\Models\TaxAuditEvent;
use App\Modules\Finance\Tax\Models\TaxComplianceDeadline;
use App\Modules\Finance\Tax\Models\TaxPeriod;
use App\Modules\Finance\Tax\Models\TaxReconciliation;
use App\Modules\Finance\Tax\Models\TaxRegistration;
use App\Modules\Finance\Tax\Models\TaxTransaction;
use App\Modules\Finance\Tax\Services\VatRegisterService;
use App\Modules\Finance\Tax\Support\TaxPermissions;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §20.1 Tax dashboard. Every figure is computed from records the user may see (tenant and entity
 * scopes apply); nothing is estimated. Amounts from periods not yet approved are labelled draft
 * so a working figure is never read as a final liability (§21 usability).
 */
class TaxDashboardController extends TaxController
{
    public function __invoke(Request $request, VatRegisterService $register): Response
    {
        $this->allow(TaxPermissions::VIEW);
        $type = $request->query('tax_type');
        $business = $this->businessFilter();

        $periods = TaxPeriod::query()->with('taxType:id,code')->forBusiness($business)
            ->when($type, fn ($q, $c) => $q->whereHas('taxType', fn ($t) => $t->where('code', $c)))
            ->where('end_date', '>=', now()->subMonths(13)->startOfMonth())->orderByDesc('end_date')->get();

        $liabilities = $periods->filter(fn ($p) => $p->taxType?->code === 'VAT' && $p->end_date->lte(today()->endOfMonth()))
            ->take(8)->map(function (TaxPeriod $p) use ($register) {
                $s = $register->summary($p);

                return ['uuid' => $p->uuid, 'label' => $p->label, 'entity' => $this->entityName($p->entity_id), 'tax_type' => 'VAT',
                    'output' => $s['output_tax'], 'input' => $s['input_tax'], 'net' => $s['net'], 'status' => $p->status,
                    'final' => in_array($p->status, ['approved', 'submitted', 'accepted', 'closed', 'locked'], true),
                    'due' => $p->payment_due_date?->toDateString()];
            })->values();

        $deadlines = TaxComplianceDeadline::query()->with('taxType:id,code')->forBusiness($business)->whereIn('status', ['open', 'in_progress', 'overdue'])
            ->when($type, fn ($q, $c) => $q->whereHas('taxType', fn ($t) => $t->where('code', $c)))
            ->orderBy('due_date')->limit(12)->get()
            ->map(fn ($d) => ['uuid' => $d->uuid, 'title' => $d->title, 'due_date' => $d->due_date->toDateString(),
                'days_left' => (int) today()->diffInDays($d->due_date, false), 'status' => $d->status]);

        return Inertia::render('Modules/Finance/Tax/Dashboard', [
            'kpis' => [
                'registrations' => TaxRegistration::query()->forBusiness($business)->where('status', 'active')->count(),
                'unconfirmed' => TaxRegistration::query()->forBusiness($business)->where('applicability_confirmed', false)->count(),
                'overdue' => TaxComplianceDeadline::query()->forBusiness($business)->where('status', 'overdue')->count(),
                'due_14' => TaxComplianceDeadline::query()->forBusiness($business)->whereIn('status', ['open', 'in_progress'])->whereDate('due_date', '<=', today()->addDays(14))->count(),
                'exceptions' => TaxTransaction::query()->forBusiness($business)->current()->where('has_exception', true)
                    ->whereHas('period', fn ($q) => $q->whereIn('status', ['open', 'in_preparation', 'rejected']))->count(),
                'awaiting_sign_off' => TaxReconciliation::query()->forBusiness($business)->where('status', 'completed')->count(),
                'returns_in_review' => \App\Modules\Finance\Tax\Models\TaxReturn::query()->forBusiness($business)->whereIn('status', ['prepared', 'under_review'])->count(),
                'returns_to_submit' => \App\Modules\Finance\Tax\Models\TaxReturn::query()->forBusiness($business)->where('status', 'approved')->count(),
                'unconfirmed_submissions' => \App\Modules\Finance\Tax\Models\TaxReturn::query()->forBusiness($business)->whereIn('submission_status', ['pending', 'unknown'])->count(),
                'overdue_liabilities' => \App\Modules\Finance\Tax\Models\TaxLiability::query()->forBusiness($business)->whereIn('status', ['open', 'part_paid'])->whereDate('due_date', '<', today())->count(),
            ],
            'pipeline' => collect(TaxPeriod::STATUSES)->mapWithKeys(fn ($s) => [$s => $periods->where('status', $s)->count()]),
            'liabilities' => $liabilities,
            'deadlines' => $deadlines,
            // §20.1: what is owed, paid and outstanding, by tax — from approved returns and assessments only.
            'owed' => \App\Modules\Finance\Tax\Models\TaxLiability::query()->forBusiness($business)->with('taxType:id,code')
                ->whereNotIn('status', ['cancelled'])->get()->groupBy(fn ($l) => $l->taxType?->code)
                ->map(fn ($g, $code) => ['tax_type' => $code,
                    'owed' => array_reduce($g->all(), fn ($c, $l) => bcadd($c, bcadd((string) $l->original_amount, (string) $l->adjustments, 2), 2), '0.00'),
                    'paid' => array_reduce($g->all(), fn ($c, $l) => bcadd($c, (string) $l->paid_amount, 2), '0.00'),
                    'outstanding' => array_reduce($g->all(), fn ($c, $l) => bcadd($c, $l->outstanding(), 2), '0.00')])->values(),
            'recent' => TaxAuditEvent::query()->whereIn('action', ['rule.published', 'rule.retired', 'vat_register.classify', 'vat_register.accept',
                'period.reopened', 'reconciliation.signed_off', 'registration.updated', 'registration.confirmed'])
                ->orderByDesc('id')->limit(10)->get(['id', 'action', 'actor_name', 'reason', 'created_at'])
                ->map(fn ($e) => ['action' => $e->action, 'actor' => $e->actor_name, 'reason' => $e->reason, 'at' => $e->created_at?->toDateTimeString()]),
            'filters' => ['tax_type' => $type, 'business' => $business],
            'taxTypes' => TaxPeriod::query()->with('taxType:id,code')->get()->pluck('taxType.code')->unique()->values(),
        ] + $this->businessProps($business));
    }
}
