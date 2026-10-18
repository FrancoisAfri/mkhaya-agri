<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Services\CompetencyService;
use App\Modules\Operations\Sheq\Services\DocumentService;
use App\Modules\Operations\Sheq\Services\InductionService;
use App\Modules\Operations\Sheq\Services\MedicalSurveillanceService;
use App\Modules\Operations\Sheq\Support\DocumentAudience;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the signed-in person themselves has to deal with (SHEQ-COMP-5).
 *
 * `sheq:reminders:send` tells somebody their first-aid certificate lapses in thirty days, and that
 * email is the only place the fact appears. Which means: it is gone once deleted, it cannot be
 * checked between sends, and somebody who was on leave for the 30-day mail and the 14-day mail has
 * no way to find out where they stand short of asking the safety officer to look them up on the
 * competency register — a screen that needs `sheq.competency.manage` and that they cannot open.
 *
 * So this is the standing version of the same answer, assembled per person and gated on nothing but
 * being signed in. It is your own record; the entitlements exist to stop you reading other people's.
 *
 * Two decisions worth stating, because both are ways a screen like this misleads:
 *
 * **An empty screen must never read as "you are fine".** Somebody with no personnel record, or
 * nobody has assigned them any requirement, gets an explicit statement that nothing is on file —
 * not a tidy page of empty sections, which is indistinguishable from compliance and is exactly the
 * wrong answer to give the person standing at a permit gate tomorrow.
 *
 * **Nothing here is a second implementation.** Status comes from `deriveStatus`, gaps from
 * `gapsFor`, fitness from `fitnessFor`, the document audience from `DocumentAudience` — the same
 * code the registers and the gate read. A personal view that computed its own answers would
 * eventually tell somebody they were compliant while the turnstile refused them.
 */
class MyComplianceController extends Controller
{
    use ResolvesCompany;

    public function __construct(
        private readonly CompetencyService $competencies,
        private readonly MedicalSurveillanceService $medical,
        private readonly InductionService $inductions,
        private readonly DocumentService $documents,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);
        $userId = $request->user()?->id;

        $person = $companyId === null || $userId === null ? null : DB::table('hr_people')
            ->where('company_id', $companyId)->where('user_id', $userId)
            ->first(['id', 'first_name', 'surname', 'employee_number']);

        if ($companyId === null || $person === null) {
            /*
             * No personnel record, so there is nothing to look up.
             *
             * Reported as its own state rather than as empty sections. Creating a user account does
             * not create an `hr_people` row — manage-users does not — so this is a real situation
             * for a real employee, and "nothing on file" is the honest answer. Telling them their
             * compliance is clear would be a guess, and the one they would act on.
             */
            return Inertia::render('Modules/Sheq/MyCompliance/Index', [
                'noCompany' => $companyId === null,
                'noRecord' => $companyId !== null,
                'person' => null,
                'competencies' => [],
                'gaps' => [],
                'medicals' => [],
                'induction' => null,
                'unacknowledged' => 0,
                'documentsIOwn' => [],
                'summary' => ['expired' => 0, 'expiring' => 0, 'blocking' => 0],
            ]);
        }

        $personId = (int) $person->id;
        $competencies = $this->myCompetencies($companyId, $personId);

        return Inertia::render('Modules/Sheq/MyCompliance/Index', [
            'noCompany' => false,
            'noRecord' => false,
            'person' => [
                'name' => trim(($person->first_name ?? '').' '.($person->surname ?? '')),
                'employee_number' => $person->employee_number ?? null,
            ],
            'competencies' => $competencies,

            /*
             * Required of me and never held at all.
             *
             * A different thing from a lapsed ticket and listed separately: a lapse is a renewal,
             * and this is training that has to be arranged. `gapsFor` resolves requirement
             * precedence, so an exemption at the winning level removes the row rather than leaving
             * somebody permanently on a non-compliance list.
             *
             * Scope is requirements assigned to the person directly. Role, department and site
             * requirements resolve from a context map SHEQ does not own — that mapping lives in HR —
             * and guessing at it here would tell people they need training they do not.
             */
            'gaps' => $this->competencies->gapsFor($companyId, $personId, []),

            'medicals' => $this->medical->fitnessFor($companyId, $personId),

            'induction' => [
                // The audience is passed explicitly: `isCurrent` defaults to CONTRACTOR, so an
                // employee's induction would never be found and everybody would read as not
                // inducted — the kind of wrong answer that sends people to redo training they
                // have already sat.
                'current' => $this->inductions->isCurrent($companyId, 'EMPLOYEE', $personId, 'EMPLOYEE'),
                // Only meaningful where the company has published one. A tenant that has not
                // authored an induction must not show its whole workforce as out of induction.
                'required' => DB::table('sheq_inductions')
                    ->where('company_id', $companyId)->where('audience', 'EMPLOYEE')
                    ->where('is_active', true)->whereNull('deleted_at')->exists(),
            ],

            /*
             * Procedures I have been issued and not confirmed reading.
             *
             * A count and a link rather than the list, because the library *is* the list — it
             * applies the audience rule, shows the version in force and carries the acknowledge
             * button. Reproducing it here would be the second implementation this class exists to
             * avoid.
             */
            'unacknowledged' => $this->unacknowledgedCount($companyId, $personId, $request),

            // The other half of what the reminder emails say: procedures whose review date has
            // passed and which I am the owner of.
            'documentsIOwn' => array_values(array_filter(
                $this->documents->dueForReview($companyId),
                fn (array $document) => (int) ($document['owner_id'] ?? 0) === $userId,
            )),

            'summary' => [
                'expired' => count(array_filter($competencies, fn ($c) => $c['status'] === 'EXPIRED')),
                'expiring' => count(array_filter($competencies, fn ($c) => $c['status'] === 'EXPIRING')),
                // The number that decides whether somebody can work tomorrow, counted separately
                // from the rest: a lapsed ticket that blocks work is not the same news as a lapsed
                // ticket that does not.
                'blocking' => count(array_filter($competencies,
                    fn ($c) => $c['status'] === 'EXPIRED' && $c['blocks_work'])),
            ],
        ]);
    }

    /**
     * My competency records, worst first.
     *
     * Status is `deriveStatus`, never the stored column: an appointment nobody signed is not in
     * force however its row reads, a revoked one is expired whatever its date says, and the whole
     * point of deriving it is that the register and this screen cannot disagree.
     *
     * @return list<array<string,mixed>>
     */
    private function myCompetencies(int $companyId, int $personId): array
    {
        $today = Carbon::today();

        return DB::table('sheq_competency_records as r')
            ->join('sheq_competencies as c', 'c.id', '=', 'r.competency_id')
            ->where('r.company_id', $companyId)->where('r.hr_person_id', $personId)
            ->whereNull('r.deleted_at')
            ->orderByRaw('r.expires_at asc nulls last')
            ->get([
                'r.uuid', 'r.status', 'r.achieved_at', 'r.expires_at', 'r.revoked_at',
                'r.appointment_scope', 'r.accepted_at', 'r.certificate_number', 'r.issuing_body',
                'c.code', 'c.title', 'c.competency_type', 'c.blocks_work_when_expired',
                'c.regulatory_reference',
            ])
            ->map(fn ($record) => [
                'uuid' => $record->uuid,
                'code' => $record->code,
                'title' => $record->title,
                'competency_type' => $record->competency_type,
                'achieved_at' => $record->achieved_at,
                'expires_at' => $record->expires_at,
                'certificate_number' => $record->certificate_number,
                'issuing_body' => $record->issuing_body,
                'regulatory_reference' => $record->regulatory_reference,
                'appointment_scope' => $record->appointment_scope,
                // An appointment takes effect when it is signed. Said here because the person
                // reading this is the one who has to sign it.
                'awaiting_my_signature' => $record->appointment_scope !== null
                    && $record->accepted_at === null && $record->revoked_at === null,
                'blocks_work' => (bool) $record->blocks_work_when_expired,
                'status' => $this->competencies->deriveStatus($record),
                // Signed, so the screen never has to work out which side of the date a number is
                // on. Negative means it has already lapsed.
                'days_left' => $record->expires_at === null ? null
                    : (int) round($today->diffInDays(Carbon::parse($record->expires_at), false)),
            ])
            ->sortBy(fn (array $record) => match ($record['status']) {
                'EXPIRED' => 0,
                'EXPIRING' => 1,
                'NOT_STARTED', 'FAILED' => 2,
                default => 3,
            })
            ->values()
            ->all();
    }

    /**
     * How many published procedures I owe an acknowledgement on.
     *
     * Audience resolved by {@see DocumentAudience} — the same rule the library filters on and the
     * register counts against — so this number cannot disagree with either.
     */
    private function unacknowledgedCount(int $companyId, int $personId, Request $request): int
    {
        $roles = $request->user()?->getRoleNames()->map(fn ($name) => (string) $name)->all() ?? [];

        $siteIds = DB::table('sheq_sites')
            ->where('company_id', $companyId)
            // The pivot's column is named `company_id` and holds the *unit* id — a long-standing
            // naming trap. Read as a company it matches every site in the tenant.
            ->whereIn('company_unit_id', DB::table('company_unit_user_pivots')
                ->where('user_id', $request->user()?->id)->pluck('company_id'))
            ->whereNull('deleted_at')
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        $acknowledged = DB::table('sheq_document_acknowledgements')
            ->where('company_id', $companyId)->where('hr_person_id', $personId)
            ->whereNotNull('acknowledged_at')
            ->pluck('document_version_id')->all();

        return DB::table('sheq_documents as d')
            ->join('sheq_document_versions as v', function ($join) {
                $join->on('v.document_id', '=', 'd.id')->where('v.status', '=', 'PUBLISHED');
            })
            ->where('d.company_id', $companyId)
            ->where('d.status', 'PUBLISHED')
            ->where('d.requires_acknowledgement', true)
            ->whereNull('d.deleted_at')
            ->get(['d.applies_to', 'd.site_id', 'v.id as version_id'])
            ->filter(fn ($row) => ! in_array($row->version_id, $acknowledged, true))
            ->filter(fn ($row) => DocumentAudience::applies(
                $row->applies_to,
                $row->site_id === null ? null : (int) $row->site_id,
                $roles,
                $siteIds,
            ))
            ->count();
    }
}
