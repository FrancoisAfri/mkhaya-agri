<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Ai\SafetyAnalytics;
use App\Modules\Operations\Sheq\Services\ToolboxTalkService;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Toolbox talks, and the analytics findings.
 *
 * Together because they are the two ends of the same loop: the analytics say what keeps going
 * wrong, and the toolbox talk is how that reaches the people doing the work. A safety manager who
 * reads "the same hand injury has happened four times" should be one click from briefing the crew
 * about it.
 */
class ToolboxTalkController extends Controller
{
    use ResolvesCompany;

    public function __construct(
        private readonly ToolboxTalkService $talks,
        private readonly SafetyAnalytics $analytics,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Modules/Sheq/Talks/Index', [
            'noCompany' => $companyId === null,
            'talks' => $companyId === null ? [] : $this->talks->recent($companyId),
            'coverage' => $companyId === null ? null : $this->talks->coverage($companyId),
            'notReached' => $companyId === null ? [] : $this->notReached($companyId),
            'insights' => $companyId === null ? [] : $this->analytics->open($companyId),
            'people' => $companyId === null ? [] : DB::table('hr_people')
                ->where('company_id', $companyId)->orderBy('first_name')->limit(2000)
                ->get(['id', 'first_name', 'surname'])
                ->map(fn ($p) => ['id' => (int) $p->id,
                    'name' => trim($p->first_name.' '.$p->surname)])->all(),
            'sites' => $companyId === null ? [] : DB::table('sheq_sites')
                ->where('company_id', $companyId)->where('is_active', true)
                ->orderBy('name')->get(['id', 'name'])->map(fn ($s) => (array) $s)->all(),
        ]);
    }

    /**
     * The people this month's coverage figure leaves out.
     *
     * The screen reported "68% reached" and stopped, which is a number nobody can act on: a
     * supervisor cannot brief a percentage. This is the other 32% by name, with the last talk each
     * of them did attend — `ToolboxTalkService::lastAttendedBy()` was written for that and nothing
     * called it, so "when did this person last sit a talk" was a question the module held the
     * answer to and could not be asked.
     *
     * Capped at 50 names with the total alongside. `lastAttendedBy` is a query per person, and a
     * two-thousand-person workforce would otherwise put two thousand of them on a page load. Fifty
     * is a day's work for a supervisor; the figure tells them how much is behind it.
     *
     * @return array{total:int, people:list<array<string,mixed>>}
     */
    private function notReached(int $companyId): array
    {
        $since = Carbon::today()->startOfMonth();

        $reached = [];

        foreach (DB::table('sheq_toolbox_talks')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->where('held_on', '>=', $since->toDateString())
            ->get(['attendees']) as $talk) {
            foreach (json_decode((string) $talk->attendees, true) ?: [] as $attendee) {
                if (! empty($attendee['hr_person_id'])) {
                    $reached[(int) $attendee['hr_person_id']] = true;
                }
            }
        }

        $outstanding = DB::table('hr_people')
            ->where('company_id', $companyId)
            // Only an explicit 0 is "not an employee"; the column is nullable and most creation
            // paths leave it unset.
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '<>', 0))
            ->when($reached !== [], fn ($q) => $q->whereNotIn('id', array_keys($reached)))
            ->orderBy('first_name')->orderBy('surname')
            ->get(['id', 'first_name', 'surname']);

        return [
            'total' => $outstanding->count(),
            'people' => $outstanding->take(50)->map(fn ($person) => [
                'id' => (int) $person->id,
                'name' => trim(($person->first_name ?? '').' '.($person->surname ?? '')),
                // Null means they have never attended one at all, which is a different
                // conversation from somebody who missed this month.
                'last' => $this->talks->lastAttendedBy($companyId, (int) $person->id),
            ])->values()->all(),
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        $validated = $request->validate([
            'topic' => ['required', 'string', 'max:200'],
            'content' => ['nullable', 'string', 'max:20000'],
            'site_id' => ['nullable', 'integer'],
            'held_on' => ['required', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:480'],
            'attendees' => ['required', 'array', 'min:1'],
            'attendees.*.hr_person_id' => ['nullable', 'integer'],
            'attendees.*.name' => ['required', 'string', 'max:160'],
            'concerns_raised' => ['nullable', 'string', 'max:2000'],
        ]);

        $result = $this->talks->record($companyId, $validated, $request->user()?->id);

        return back()->with('success', $result['action_raised']
            ? "Recorded for {$result['attendees']} people. The concern raised has been turned into "
              .'an action so it is not lost when the talk ends.'
            : "Recorded for {$result['attendees']} people.");
    }

    /** Dismiss an insight so a finding somebody has considered stops resurfacing. */
    public function dismissInsight(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        $validated = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        $this->analytics->dismiss($companyId, $uuid, (int) $request->user()->id,
            $validated['note'] ?? null);

        return back()->with('success', 'Dismissed. It will not be raised again.');
    }
}
