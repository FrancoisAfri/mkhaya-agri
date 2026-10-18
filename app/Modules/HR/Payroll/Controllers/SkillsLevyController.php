<?php

namespace App\Modules\HR\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Services\SkillsLevyService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Skills Development Levy, and the fifth of it that can be claimed back.
 *
 * Payroll paid the levy every month and the LMS chased the 30 April deadline, and neither knew
 * what the other was doing — so nobody could see how much had been levied, how much was claimable,
 * or how much had actually come back.
 */
class SkillsLevyController extends Controller
{
    public function __construct(private readonly SkillsLevyService $levy)
    {
    }

    public function index(Request $request): Response
    {
        // The levy year is named by the year it ends in, and turns over on 1 April.
        $default = (int) date('n') >= 4 ? (int) date('Y') + 1 : (int) date('Y');
        $year = (int) $request->integer('year', $default);

        return Inertia::render('Modules/HR/Payroll/SkillsLevy/Index', [
            'report' => $this->levy->forYear($year),
            'year'   => $year,
            'years'  => range($default, $default - 4),
            'history' => DB::table('lms_wsp_submissions')
                ->orderByDesc('year')->limit(5)
                ->get(['year', 'seta', 'submitted_at', 'grant_expected', 'grant_received', 'status', 'reference']),
        ]);
    }

    /** Records that a plan went in, and what the SETA paid back against it. */
    public function record(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'year'              => ['required', 'integer', 'min:2000', 'max:2100'],
            'seta'              => ['nullable', 'string', 'max:30'],
            'sdl_number'        => ['nullable', 'string', 'max:30'],
            'reference'         => ['nullable', 'string', 'max:120'],
            'submitted'         => ['nullable', 'boolean'],
            'grant_received'    => ['nullable', 'numeric', 'min:0'],
            'grant_received_on' => ['nullable', 'date'],
            'status'            => ['nullable', 'in:draft,submitted,approved,rejected,paid'],
            'notes'             => ['nullable', 'string', 'max:2000'],
        ]);

        $companyId = app(CurrentCompany::class)->id();
        $report = $this->levy->forYear((int) $data['year'], $companyId);

        DB::table('lms_wsp_submissions')->updateOrInsert(
            ['company_id' => $companyId, 'year' => (int) $data['year']],
            [
                'uuid'              => (string) Str::uuid(),
                'seta'              => $data['seta'] ?? null,
                'sdl_number'        => $data['sdl_number'] ?? null,
                'reference'         => $data['reference'] ?? null,
                // Submitted is a date rather than a flag: the deadline is a date, and "was it in
                // by 30 April" cannot be answered by a boolean.
                'submitted_at'      => ! empty($data['submitted']) ? now() : null,
                'submitted_by'      => ! empty($data['submitted']) ? Auth::id() : null,
                // Expected comes from the levy actually paid, not from anybody's typing.
                'grant_expected'    => $report['mandatory_grant'],
                'grant_received'    => $data['grant_received'] ?? 0,
                'grant_received_on' => $data['grant_received_on'] ?? null,
                'status'            => $data['status'] ?? 'draft',
                'notes'             => $data['notes'] ?? null,
                'updated_at'        => now(),
                'created_at'        => now(),
            ],
        );

        return back()->with('success', 'Recorded.');
    }
}
