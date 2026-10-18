<?php

namespace App\Modules\HR\Payroll\Controllers\Bureau;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\HR\Payroll\Models\PayrollAuditLog;
use App\Modules\HR\Payroll\Services\Bureau\BureauAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Everything done to one client's payroll, and by whom.
 *
 * The question this answers is asked from outside — by a client's auditor, or by a client disputing
 * a payment — so it is one screen per client rather than a firehose across the bureau. Reading it
 * should not require knowing which of our two engines ran the payroll.
 */
class BureauAuditController extends Controller
{
    public function index(Request $request, BureauCompany $company): Response
    {
        abort_unless($company->accessibleBy(Auth::user()), 403);

        $entries = PayrollAuditLog::query()
            ->where('bureau_company_id', $company->id)
            ->when($request->input('action'), fn ($q, $action) => $q->where('action', $action))
            ->when($request->input('search'), fn ($q, $term) => $q->where('description', 'ilike', '%' . $term . '%'))
            // Tie-broken on the id: a busy minute writes several rows in the same second, and paging
            // an order the database is free to vary shows the same row twice and skips another.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        // Who, by name. The trail stores a user id, and a page of numbers is a page nobody can read.
        $names = DB::table('users as u')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->whereIn('u.id', $entries->getCollection()->pluck('user_id')->filter()->unique())
            ->selectRaw("u.id, COALESCE(NULLIF(TRIM(CONCAT(p.first_name,' ',COALESCE(p.surname,''))), ''), u.email) AS name")
            ->pluck('name', 'id');

        $entries->getCollection()->transform(function (PayrollAuditLog $entry) use ($names) {
            $entry->user_name = $names[$entry->user_id] ?? ($entry->user_id ? "User #{$entry->user_id}" : 'System');

            return $entry;
        });

        return Inertia::render('Modules/HR/Payroll/Bureau/AuditTrail', [
            'company' => ['uuid' => $company->uuid, 'name' => $company->name],
            'entries' => $entries,
            'filters' => $request->only(['action', 'search']),
            'actions' => $this->actions(),
        ]);
    }

    /** The actions that can appear, for the filter — labelled the way a person would say them. */
    private function actions(): array
    {
        return [
            ['value' => '', 'title' => 'All activity'],
            ['value' => BureauAuditService::RUN_CREATED, 'title' => 'Run created'],
            ['value' => BureauAuditService::RUN_PROCESSED, 'title' => 'Run calculated'],
            ['value' => BureauAuditService::RUN_FINALISED, 'title' => 'Run finalised'],
            ['value' => BureauAuditService::PAYSLIPS_EMAILED, 'title' => 'Payslips emailed'],
            ['value' => BureauAuditService::EMP201_GENERATED, 'title' => 'EMP201 exported'],
            ['value' => BureauAuditService::EMP201_FILED, 'title' => 'EMP201 filed'],
            ['value' => BureauAuditService::CERTIFICATES_GENERATED, 'title' => 'Tax certificates'],
            ['value' => BureauAuditService::BANK_FILE_GENERATED, 'title' => 'Bank file generated'],
            ['value' => BureauAuditService::DISBURSEMENT_SENT, 'title' => 'Payment released'],
            ['value' => BureauAuditService::DISBURSEMENT_RESPONSE, 'title' => 'Payment response'],
            ['value' => BureauAuditService::EMPLOYEE_CREATED, 'title' => 'Employee added'],
            ['value' => BureauAuditService::BANKING_CHANGED, 'title' => 'Banking changed'],
            ['value' => BureauAuditService::TAX_DETAILS_CHANGED, 'title' => 'Tax details changed'],
            ['value' => BureauAuditService::ETI_CHANGED, 'title' => 'Incentive details changed'],
            ['value' => BureauAuditService::PAY_FREQUENCY_CHANGED, 'title' => 'Pay frequency changed'],
            ['value' => BureauAuditService::EMPLOYEE_TERMINATED, 'title' => 'Employee terminated'],
        ];
    }
}
