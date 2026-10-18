<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Services\SiteAttendanceService;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use App\Modules\Operations\Sheq\Support\ScopesToPermittedSites;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who is on site, who was, and who never signed out (CON-16).
 *
 * A screen of its own, and the only one in the module anybody opens in a hurry: during an
 * evacuation the roll-call is the whole product. So it loads the list of people inside first and
 * everything else after, it works with no site chosen, and the manual sign-in is on the same
 * page rather than behind a menu — a marshal with a clipboard and a flat scanner needs it in
 * one place.
 */
class AttendanceController extends Controller
{
    use ResolvesCompany;
    use ScopesToPermittedSites;

    public function __construct(private readonly SiteAttendanceService $attendance) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        $siteId = $this->permittedSite($request, $companyId);
        $day = $request->date('day') ? Carbon::parse($request->input('day')) : Carbon::today();

        /*
         * The hours after which a stay is treated as a forgotten exit. Site-wide rather than
         * per site for now: a tenant with a night shift sets it once, and a per-site setting
         * nobody fills in would read as every site demanding the default anyway.
         */
        $overstayHours = 14;

        return Inertia::render('Modules/Sheq/Registers/Attendance', [
            'noCompany' => $companyId === null,
            'onSite' => $companyId === null ? []
                : $this->attendance->onSite($companyId, $siteId),
            'movements' => $companyId === null ? []
                : $this->attendance->movements($companyId, $day, $siteId),
            'overstaying' => $companyId === null ? []
                : $this->attendance->overstaying($companyId, $overstayHours),
            'overstayHours' => $overstayHours,
            'sites' => $companyId === null ? [] : DB::table('sheq_sites')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->where('is_active', true)->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($s) => ['value' => (int) $s->id, 'title' => $s->name])->all(),
            /*
             * The crew a guard can sign in by hand. Only active workers of active contractors:
             * signing in somebody who is off the roster is how a register ends up disagreeing
             * with the gate it exists to back up.
             */
            'workers' => $companyId === null ? [] : $this->signableWorkers($companyId),
            'activities' => $companyId === null ? [] : DB::table('sheq_activities')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->where('is_active', true)->orderBy('name')
                ->get(['code', 'name'])
                ->map(fn ($a) => ['value' => $a->code, 'title' => $a->name])->all(),
            'siteId' => $siteId,
            'day' => $day->toDateString(),
            'isToday' => $day->isToday(),
            'canManage' => (bool) $request->user()?->can('sheq.contractor.manage'),
        ]);
    }

    /** Sign somebody in or out by hand — the guard's path when a reader is down. */
    public function record(Request $request, string $direction): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.contractor.manage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.contractor.manage');

        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'worker_uuid' => ['required', 'uuid',
                Rule::exists('sheq_contractor_workers', 'uuid')
                    ->where('company_id', $companyId)->whereNull('deleted_at'),
            ],
            'site_id' => ['nullable', 'integer',
                Rule::exists('sheq_sites', 'id')->where('company_id', $companyId),
            ],
            'activity' => ['nullable', 'string', 'max:60'],
            /*
             * A time in the past, for the common case: a guard writing up the last hour from a
             * paper list. Not the future — a register that can record an arrival that has not
             * happened is a register that can be used to say somebody was here when they were
             * not.
             */
            'occurred_at' => ['nullable', 'date', 'before_or_equal:now'],
        ]);

        $this->attendance->manual($companyId, strtoupper($direction), $data,
            $request->user()?->id);

        return back()->with('success', strtoupper($direction) === 'ENTRY'
            ? 'Signed in. They are on the roll-call now.'
            : 'Signed out.');
    }

    /**
     * Workers a guard may sign in, with whether they are already inside.
     *
     * The "already inside" flag is what stops the commonest mistake: signing somebody in who
     * never signed out yesterday, which produces a stay of nineteen hours and a roll-call that
     * looks wrong to everybody who reads it.
     *
     * @return list<array<string,mixed>>
     */
    private function signableWorkers(int $companyId): array
    {
        $inside = DB::table('sheq_site_attendance')
            ->where('company_id', $companyId)->where('subject_type', 'CONTRACTOR_WORKER')
            ->whereNull('exited_at')->pluck('subject_id')
            ->map(fn ($id) => (int) $id)->all();

        return DB::table('sheq_contractor_workers as w')
            ->join('sheq_contractors as c', 'c.id', '=', 'w.contractor_id')
            ->where('w.company_id', $companyId)->whereNull('w.deleted_at')
            ->where('w.is_active', true)
            ->whereNull('c.deleted_at')->where('c.is_active', true)
            ->orderBy('c.name')->orderBy('w.name')->limit(2000)
            ->get(['w.uuid', 'w.id', 'w.name', 'w.trade', 'c.name as contractor'])
            ->map(fn ($w) => [
                'value' => $w->uuid,
                'title' => $w->name,
                'subtitle' => trim($w->contractor.($w->trade ? ' · '.$w->trade : '')),
                'on_site' => in_array((int) $w->id, $inside, true),
            ])->all();
    }
}
