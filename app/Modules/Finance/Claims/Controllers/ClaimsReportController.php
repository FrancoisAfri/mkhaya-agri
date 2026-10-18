<?php

namespace App\Modules\Finance\Claims\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Claims\Models\Claim;
use App\Modules\Finance\Claims\Models\ClaimCategory;
use App\Modules\Finance\Claims\Models\ClaimScheduledReport;
use App\Modules\Finance\Claims\Traits\ClaimsAuthTrait;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClaimsReportController extends Controller
{
    use ClaimsAuthTrait;

    public function index(Request $request): Response
    {
        abort_unless($this->canViewClaimsDashboard(), 403);

        $from       = $request->input('date_from', now()->startOfYear()->toDateString());
        $to         = $request->input('date_to',   now()->toDateString());
        $categoryId = $request->integer('category_id') ?: null;
        $status     = $request->input('status');

        // ── All claims ────────────────────────────────────────────────────────
        $allClaims = Claim::with(['employee.person', 'category'])
            ->whereBetween('claim_date', [$from, $to])
            ->when($categoryId, fn($q) => $q->where('category_id', $categoryId))
            ->when($status, fn($q) => $q->where('status', $status))
            ->latest()
            ->limit(500)
            ->get()
            ->map(fn($c) => $this->formatRow($c));

        // ── By employee ───────────────────────────────────────────────────────
        $byEmployee = DB::table('claims')
            ->join('hr_people as p', 'p.user_id', '=', 'claims.employee_id')
            ->leftJoin('company_unit_user_pivots as cup', 'cup.user_id', '=', 'claims.employee_id')
            ->leftJoin('company_units as cu', fn($j) =>
                $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department'))
            ->whereBetween('claims.claim_date', [$from, $to])
            ->when($categoryId, fn($q) => $q->where('claims.category_id', $categoryId))
            ->when($status, fn($q) => $q->where('claims.status', $status))
            ->groupBy('claims.employee_id', 'p.first_name', 'p.surname', 'cu.name')
            ->orderByRaw('SUM(claims.total_amount) DESC')
            ->limit(200)
            ->get([
                DB::raw("TRIM(p.first_name || ' ' || p.surname) as employee_name"),
                DB::raw('cu.name as department'),
                DB::raw('COUNT(*) as claim_count'),
                DB::raw('SUM(claims.total_amount) as total_amount'),
                DB::raw('AVG(claims.total_amount) as avg_amount'),
                DB::raw("SUM(CASE WHEN claims.status IN ('approved','paid') THEN 1 ELSE 0 END) as approved_count"),
                DB::raw("SUM(CASE WHEN claims.status = 'rejected' THEN 1 ELSE 0 END) as rejected_count"),
            ]);

        // ── By category ───────────────────────────────────────────────────────
        $byCategory = DB::table('claims')
            ->leftJoin('claim_categories as cc', 'cc.id', '=', 'claims.category_id')
            ->whereBetween('claims.claim_date', [$from, $to])
            ->when($status, fn($q) => $q->where('claims.status', $status))
            ->groupBy('claims.category_id', 'cc.name')
            ->orderByRaw('SUM(claims.total_amount) DESC')
            ->get([
                DB::raw("COALESCE(cc.name, 'Uncategorised') as category"),
                DB::raw('COUNT(*) as claim_count'),
                DB::raw('SUM(claims.total_amount) as total_amount'),
                DB::raw('AVG(claims.total_amount) as avg_amount'),
                DB::raw("SUM(CASE WHEN claims.status IN ('approved','paid') THEN 1 ELSE 0 END) as approved_count"),
            ]);

        // ── By department ─────────────────────────────────────────────────────
        $byDepartment = DB::table('claims')
            ->join('company_unit_user_pivots as cup', 'cup.user_id', '=', 'claims.employee_id')
            ->join('company_units as cu', fn($j) =>
                $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department'))
            ->whereBetween('claims.claim_date', [$from, $to])
            ->when($categoryId, fn($q) => $q->where('claims.category_id', $categoryId))
            ->when($status, fn($q) => $q->where('claims.status', $status))
            ->groupBy('cu.id', 'cu.name')
            ->orderByRaw('SUM(claims.total_amount) DESC')
            ->get([
                'cu.name as department',
                DB::raw('COUNT(DISTINCT claims.employee_id) as employee_count'),
                DB::raw('COUNT(*) as claim_count'),
                DB::raw('SUM(claims.total_amount) as total_amount'),
                DB::raw('AVG(claims.total_amount) as avg_amount'),
            ]);

        // ── Travel claims ─────────────────────────────────────────────────────
        $travelClaims = DB::table('claims as c')
            ->join('claim_travel_trips as ctt', 'ctt.claim_id', '=', 'c.id')
            ->join('hr_people as p', 'p.user_id', '=', 'c.employee_id')
            ->whereBetween('c.claim_date', [$from, $to])
            ->when($status, fn($q) => $q->where('c.status', $status))
            ->orderByDesc('c.claim_date')
            ->limit(500)
            ->get([
                'c.reference_number',
                DB::raw("TRIM(p.first_name || ' ' || p.surname) as employee_name"),
                'c.status',
                DB::raw('c.total_amount as claim_amount'),
                'ctt.trip_date',
                'ctt.origin',
                'ctt.destination',
                DB::raw('ctt.distance_km::float as distance_km'),
                DB::raw('ctt.km_rate::float as km_rate'),
                DB::raw('ctt.base_amount::float as base_amount'),
                DB::raw('ctt.peak_surcharge::float as peak_surcharge'),
                DB::raw('ctt.total_amount::float as trip_amount'),
                'ctt.traffic_condition',
                'ctt.is_peak_hour',
            ]);

        // ── High risk ─────────────────────────────────────────────────────────
        $highRisk = Claim::with(['employee.person', 'category', 'riskScore'])
            ->whereIn('risk_level', ['high', 'critical'])
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->whereBetween('claim_date', [$from, $to])
            ->orderByDesc('risk_score')
            ->limit(200)
            ->get()
            ->map(fn($c) => array_merge($this->formatRow($c), [
                'risk_score'   => $c->risk_score ? (float) $c->risk_score : 0,
                'risk_level'   => $c->risk_level,
                'ai_flags'     => $c->ai_flags ?? [],
                'is_duplicate' => $c->riskScore?->is_duplicate ?? false,
                'is_inflated'  => $c->riskScore?->is_inflated ?? false,
                'is_splitting' => $c->riskScore?->is_splitting ?? false,
            ]));

        // ── Policy violations ─────────────────────────────────────────────────
        $violations = DB::table('claim_policy_violations as cpv')
            ->join('claims as c', 'c.id', '=', 'cpv.claim_id')
            ->join('hr_people as p', 'p.user_id', '=', 'c.employee_id')
            ->leftJoin('claim_categories as cc', 'cc.id', '=', 'c.category_id')
            ->whereBetween('c.claim_date', [$from, $to])
            ->whereNotIn('c.status', ['cancelled', 'rejected'])
            ->orderByDesc('cpv.created_at')
            ->limit(500)
            ->get([
                'c.reference_number',
                DB::raw("TRIM(p.first_name || ' ' || p.surname) as employee_name"),
                DB::raw("COALESCE(cc.name, 'Uncategorised') as category"),
                DB::raw('c.total_amount::float as total_amount'),
                'c.status',
                'cpv.policy_rule',
                'cpv.violation_type',
                'cpv.description as violation_description',
                'cpv.severity',
                'cpv.exception_approved',
            ]);

        // ── Filter options ────────────────────────────────────────────────────
        $categories  = ClaimCategory::orderBy('name')->get(['id', 'name']);
        $departments = DB::table('company_units')
            ->where('type', 'department')->where('active', true)->orderBy('name')->get(['id', 'name']);

        // ── Schedules ─────────────────────────────────────────────────────────
        $schedules = $this->isClaimsAdmin()
            ? ClaimScheduledReport::with('creator')->orderBy('name')->get()
            : ClaimScheduledReport::with('creator')->where('created_by', Auth::id())->orderBy('name')->get();

        return Inertia::render('Modules/Finance/Claims/Reports/Index', [
            'allClaims'    => $allClaims,
            'byEmployee'   => $byEmployee,
            'byCategory'   => $byCategory,
            'byDepartment' => $byDepartment,
            'travelClaims' => $travelClaims,
            'highRisk'     => $highRisk,
            'violations'   => $violations,
            'categories'   => $categories,
            'departments'  => $departments,
            'schedules'    => $schedules->map(fn($s) => [
                'id'          => $s->id,
                'name'        => $s->name,
                'report_type' => $s->report_type,
                'frequency'   => $s->frequency,
                'format'      => $s->format,
                'recipients'  => $s->recipients,
                'is_active'   => $s->is_active,
                'last_sent_at'=> $s->last_sent_at?->format('d M Y H:i'),
                'next_run_at' => $s->next_run_at?->format('d M Y H:i'),
                'created_by'  => $s->creator?->email ?? "User #{$s->created_by}",
            ]),
            'filters' => array_merge(
                ['date_from' => $from, 'date_to' => $to],
                $request->only(['category_id', 'status'])
            ),
        ]);
    }

    public function scheduleIndex(): Response
    {
        abort_unless($this->canViewClaimsDashboard(), 403);

        $schedules = $this->isClaimsAdmin()
            ? ClaimScheduledReport::with('creator')->orderBy('name')->get()
            : ClaimScheduledReport::with('creator')->where('created_by', Auth::id())->orderBy('name')->get();

        return Inertia::render('Modules/Finance/Claims/Reports/Schedule', [
            'schedules' => $schedules->map(fn($s) => [
                'id'          => $s->id,
                'name'        => $s->name,
                'report_type' => $s->report_type,
                'frequency'   => $s->frequency,
                'format'      => $s->format,
                'recipients'  => $s->recipients,
                'is_active'   => $s->is_active,
                'last_sent_at'=> $s->last_sent_at?->format('d M Y H:i'),
                'next_run_at' => $s->next_run_at?->format('d M Y H:i'),
                'created_by'  => $s->creator?->email ?? "User #{$s->created_by}",
            ]),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        abort_unless($this->canViewClaimsDashboard(), 403);

        $type = $request->input('type', 'all_claims');
        $from = $request->input('date_from', now()->startOfYear()->toDateString());
        $to   = $request->input('date_to',   now()->toDateString());

        $rows = $this->buildExportData($type, $from, $to, $request->only(['category_id', 'status']));
        $filename = "claims-{$type}-" . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            if (!empty($rows)) {
                fputcsv($out, array_keys((array) $rows[0]));
                foreach ($rows as $row) {
                    fputcsv($out, array_values((array) $row));
                }
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    // ── Scheduled reports CRUD ────────────────────────────────────────────────

    public function scheduleStore(Request $request): RedirectResponse
    {
        abort_unless($this->canViewClaimsDashboard(), 403);

        $data = $request->validate([
            'name'        => 'required|string|max:100',
            'report_type' => 'required|string|max:50',
            'frequency'   => 'required|in:daily,weekly,monthly',
            'format'      => 'required|in:csv,pdf',
            'recipients'  => 'required|string|max:1000',
            'filters'     => 'nullable|array',
        ]);

        ClaimScheduledReport::create(array_merge($data, [
            'is_active'  => true,
            'created_by' => Auth::id(),
            'next_run_at'=> $this->nextRun($data['frequency']),
        ]));

        activity('claims')
            ->causedBy(Auth::user())
            ->withProperties(['name' => $data['name'], 'frequency' => $data['frequency']])
            ->event('scheduled_report_created')
            ->log("Scheduled report \"{$data['name']}\" created ({$data['frequency']}).");

        return back()->with('success', 'Scheduled report created.');
    }

    public function scheduleUpdate(Request $request, ClaimScheduledReport $schedule): RedirectResponse
    {
        abort_unless($schedule->created_by === Auth::id() || $this->isClaimsAdmin(), 403);

        $data = $request->validate([
            'name'       => 'sometimes|string|max:100',
            'frequency'  => 'sometimes|in:daily,weekly,monthly',
            'format'     => 'sometimes|in:csv,pdf',
            'recipients' => 'sometimes|string|max:1000',
            'is_active'  => 'sometimes|boolean',
        ]);

        if (isset($data['frequency'])) {
            $data['next_run_at'] = $this->nextRun($data['frequency']);
        }

        $schedule->update($data);
        return back()->with('success', 'Schedule updated.');
    }

    public function scheduleDestroy(ClaimScheduledReport $schedule): RedirectResponse
    {
        abort_unless($schedule->created_by === Auth::id() || $this->isClaimsAdmin(), 403);
        $schedule->delete();
        return back()->with('success', 'Schedule deleted.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function formatRow(Claim $c): array
    {
        return [
            'id'               => $c->id,
            'reference_number' => $c->reference_number,
            'employee'         => $c->employeeName(),
            'title'            => $c->title,
            'category'         => $c->category?->name ?? '—',
            'total_amount'     => (float) $c->total_amount,
            'currency'         => $c->currency,
            'status'           => $c->status,
            'risk_level'       => $c->risk_level,
            'claim_date'       => $c->claim_date->format('d M Y'),
            'submitted_at'     => $c->submitted_at?->format('d M Y'),
            'approved_at'      => $c->approved_at?->format('d M Y'),
            'paid_at'          => $c->paid_at?->format('d M Y'),
        ];
    }

    private function nextRun(string $frequency): string
    {
        return match ($frequency) {
            'daily'   => now()->addDay()->startOfDay()->toDateTimeString(),
            'weekly'  => now()->addWeek()->startOfDay()->toDateTimeString(),
            'monthly' => now()->addMonth()->startOfMonth()->toDateTimeString(),
            default   => now()->addDay()->toDateTimeString(),
        };
    }

    private function buildExportData(string $type, string $from, string $to, array $filters): array
    {
        $base = Claim::whereBetween('claim_date', [$from, $to]);
        if (!empty($filters['category_id'])) $base->where('category_id', $filters['category_id']);
        if (!empty($filters['status']))      $base->where('status',      $filters['status']);

        return match ($type) {
            'all_claims' => $base->with(['employee.person', 'category'])->latest()->limit(5000)->get()
                ->map(fn($c) => [
                    'Reference'    => $c->reference_number,
                    'Employee'     => $c->employeeName(),
                    'Title'        => $c->title,
                    'Category'     => $c->category?->name ?? '',
                    'Amount'       => $c->total_amount,
                    'Currency'     => $c->currency,
                    'Status'       => $c->status,
                    'Claim Date'   => $c->claim_date->format('Y-m-d'),
                    'Submitted'    => $c->submitted_at?->format('Y-m-d') ?? '',
                    'Approved'     => $c->approved_at?->format('Y-m-d') ?? '',
                    'Paid'         => $c->paid_at?->format('Y-m-d') ?? '',
                    'Risk Level'   => $c->risk_level ?? '',
                ])->toArray(),
            'high_risk' => Claim::with(['employee.person', 'category'])->whereIn('risk_level', ['high', 'critical'])
                ->whereBetween('claim_date', [$from, $to])->get()
                ->map(fn($c) => [
                    'Reference'   => $c->reference_number,
                    'Employee'    => $c->employeeName(),
                    'Amount'      => $c->total_amount,
                    'Status'      => $c->status,
                    'Risk Level'  => $c->risk_level ?? '',
                    'Risk Score'  => $c->risk_score ?? 0,
                    'AI Flags'    => implode('; ', $c->ai_flags ?? []),
                ])->toArray(),
            default => [],
        };
    }
}
