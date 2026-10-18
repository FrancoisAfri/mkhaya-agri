<?php

namespace App\Modules\HR\SelfService;

use App\Http\Controllers\Controller;
use App\Modules\Communication\ContentManagement\Models\Content;
use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Leave\Models\LeaveBalanceTransaction;
use App\Modules\HR\Leave\Models\LeaveRequest;
use App\Modules\HR\Leave\Models\LeaveType;
use App\Modules\HR\Payroll\Models\Payslip;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SelfServiceController extends Controller
{
    public function index(): Response
    {
        $user   = Auth::user();
        $person = HRPerson::where('user_id', $user->id)
            ->with(['hrPosition'])
            ->first();

        return Inertia::render('Modules/HR/SelfService/Index', [
            'person'          => $person ? $this->personSummary($person) : null,
            'leave_balances'  => $person ? $this->leaveBalances($person->id) : [],
            'pending_leaves'  => $person ? $this->pendingLeaves($person->id) : [],
            'recent_payslips' => $person ? $this->recentPayslips($person->id) : [],
            'announcements'   => $this->announcements(),
            // No helpdesk in this product; the page still renders the (empty) panel.
            'open_tickets'    => [],
            'assigned_assets' => $person ? $this->assignedAssets($user->id) : [],
        ]);
    }

    // ── Person summary ────────────────────────────────────────────────────────

    private function personSummary(HRPerson $p): array
    {
        return [
            'id'         => $p->id,
            'name'       => trim("{$p->first_name} {$p->surname}"),
            'job_title'  => $p->hrPosition?->name ?? 'Employee',
            'department' => $p->companyUnit?->name ?? null,
            'email'      => $p->work_email ?? Auth::user()->email,
            'avatar'     => $p->profile_photo ? '/storage/' . $p->profile_photo : null,
            'start_date' => $p->date_of_appointment?->toDateString(),
        ];
    }

    // ── Leave balances (running balance per type from latest transaction) ─────

    private function leaveBalances(int $personId): array
    {
        $types = LeaveType::where('status', 1)->orderBy('name')->get(['id', 'name']);

        return $types->map(function (LeaveType $lt) use ($personId) {
            $latest = LeaveBalanceTransaction::where('employee_id', $personId)
                ->where('leave_type_id', $lt->id)
                ->latest('id')
                ->value('balance_after');

            // Days taken this leave cycle
            $taken = LeaveRequest::where('employee_id', $personId)
                ->where('leave_type_id', $lt->id)
                ->whereIn('status', ['approved', 'pending'])
                ->whereYear('start_date', now()->year)
                ->sum('working_days');

            return [
                'type_id'   => $lt->id,
                'type_name' => $lt->name,
                'color'     => '#7367f0',
                'balance'   => $latest !== null ? (float) $latest : null,
                'taken'     => (float) $taken,
            ];
        })->filter(fn ($b) => $b['balance'] !== null || $b['taken'] > 0)
          ->values()
          ->toArray();
    }

    // ── Pending leave requests ────────────────────────────────────────────────

    private function pendingLeaves(int $personId): array
    {
        // statutory_type is what the colour is derived from; without it every type reads grey.
        return LeaveRequest::with('leaveType:id,name,statutory_type')
            ->where('employee_id', $personId)
            ->whereIn('status', ['pending', 'approved', 'rejected'])
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'id'         => $r->id,
                'type'       => $r->leaveType?->name,
                'color'      => $r->leaveType?->color ?? '#607D8B',
                'start_date' => $r->start_date?->toDateString(),
                'end_date'   => $r->end_date?->toDateString(),
                'days'       => (float) $r->working_days,
                'status'     => $r->status,
            ])
            ->toArray();
    }

    // ── Recent payslips ───────────────────────────────────────────────────────

    private function recentPayslips(int $personId): array
    {
        return Payslip::where('employee_id', $personId)
            ->with('payrollRun:id,name,period_start,period_end')
            ->latest()
            ->limit(3)
            ->get()
            ->map(fn ($ps) => [
                'id'           => $ps->id,
                'uuid'         => $ps->uuid,
                'period_start' => $ps->period_start?->toDateString(),
                'period_end'   => $ps->period_end?->toDateString(),
                'net_pay'      => (float) $ps->net_pay,
                'status'       => $ps->status?->value ?? 'issued',
                'run_name'     => $ps->payrollRun?->name,
            ])
            ->toArray();
    }

    // ── Recent announcements ──────────────────────────────────────────────────

    private function announcements(): array
    {
        return Content::where('type', 1)
            ->where('is_active', 1)
            ->latest()
            ->limit(4)
            ->get(['id', 'title', 'summary', 'created_at'])
            ->map(fn ($c) => [
                'id'         => $c->id,
                'title'      => $c->title,
                'summary'    => strip_tags($c->summary ?? ''),
                'created_at' => $c->created_at->diffForHumans(),
            ])
            ->toArray();
    }

    // ── Assigned assets (via asset_assignments table) ─────────────────────────

    private function assignedAssets(int $userId): array
    {
        return DB::table('asset_assignments as aa')
            ->join('assets as a', 'a.id', '=', 'aa.asset_id')
            ->where('aa.assigned_to', $userId)
            ->whereNull('aa.returned_at')
            ->select('a.id', 'a.name', 'a.asset_tag', 'aa.effective_date')
            ->limit(6)
            ->get()
            ->map(fn ($r) => [
                'id'          => $r->id,
                'name'        => $r->name,
                'asset_tag'   => $r->asset_tag,
                'assigned_at' => $r->effective_date,
            ])
            ->toArray();
    }
}
