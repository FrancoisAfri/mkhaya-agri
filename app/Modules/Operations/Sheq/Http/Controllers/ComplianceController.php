<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Compliance\ComplianceDecisionService;
use App\Modules\Operations\Sheq\Compliance\ReasonCode;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ask the question directly: may this person do this work here, right now (COM-1)?
 *
 * A page of its own, and the reason is not convenience. Until now the only way to find out what
 * this system thought of somebody was to go and stand at a boom with their card, or to read a
 * status column that summarised the answer into one word. Neither tells you WHY, and why is the
 * only part anybody can act on: a contractor whose status reads NON_COMPLIANT has to be told
 * which certificate, and a worker turned away at 06:00 has to be told what to bring tomorrow.
 *
 * So this screen asks the engine the real question and shows the whole of its working — every
 * check, including the ones that passed and the ones that did not apply. A list of only the
 * failures cannot distinguish "we checked and it was fine" from "we never looked", and that
 * distinction is what COM-3 is about.
 *
 * It is also the only place a host can see what an unverifiable check will do before the morning
 * it happens.
 */
class ComplianceController extends Controller
{
    use ResolvesCompany;

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Modules/Sheq/Registers/ComplianceCheck', [
            'noCompany' => $companyId === null,
            'sites' => $companyId === null ? [] : $this->sites($companyId),
            'activities' => $companyId === null ? [] : $this->activities($companyId),
            'workers' => $companyId === null ? []
                : $this->workers($companyId, $request->query('contractor')),
            /*
             * Narrowed when somebody arrived from a contractor's profile. Without this the link
             * lands on a picker of every contractor worker in the company and they have to find
             * the firm they were already looking at.
             */
            'contractorFilter' => $companyId === null ? null
                : $this->contractorName($companyId, $request->query('contractor')),
            /*
             * The declared checks, so the page can show the full shape of a decision before
             * anybody has asked one. A host who has never run a check still needs to know what
             * this system looks at — that list is the argument for trusting the answer.
             */
            'checkCatalogue' => collect(ComplianceDecisionService::CHECKS)
                ->map(fn (string $description, string $key) => [
                    'key' => $key, 'description' => $description,
                ])->values()->all(),
            'outcomes' => ComplianceDecisionService::OUTCOMES,
            'unverifiedOptions' => ComplianceDecisionService::UNVERIFIED_OUTCOMES,
            'unverifiedOutcome' => $companyId === null
                ? ComplianceDecisionService::DEFAULT_UNVERIFIED_OUTCOME
                : app(ComplianceDecisionService::class)->unverifiedOutcome($companyId),
            'defaultUnverified' => ComplianceDecisionService::DEFAULT_UNVERIFIED_OUTCOME,
            'reasonSubjects' => collect(ReasonCode::CATALOGUE)
                ->map(fn (array $meta, string $code) => [
                    'code' => $code, 'subject' => $meta['subject'],
                    'activity_specific' => $meta['activity_specific'],
                ])->values()->all(),
            'canManage' => (bool) $request->user()?->can('sheq.configure'),
        ]);
    }

    /** Run one check and hand back the whole decision. */
    public function check(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'worker_uuid' => ['required', 'uuid'],
            'site_id' => ['nullable', 'integer'],
            'activity' => ['nullable', 'string', 'max:60'],
            // A moment, not only "now". The same question gets asked about a shift that has
            // already happened — "were they eligible when they walked in at 05:48" — and an
            // engine that can only speak about the present cannot answer it.
            'at' => ['nullable', 'date'],
        ]);

        $worker = DB::table('sheq_contractor_workers')
            ->where('company_id', $companyId)->where('uuid', $data['worker_uuid'])
            ->whereNull('deleted_at')->first(['id', 'name']);

        if ($worker === null) {
            return back()->withErrors(['worker_uuid' => 'That person is not on any roster here.']);
        }

        $decision = app(ComplianceDecisionService::class)->forWorker(
            $companyId,
            (int) $worker->id,
            $data['site_id'] === null ? null : (int) $data['site_id'],
            $data['activity'] ?: null,
            empty($data['at']) ? null : Carbon::parse($data['at']),
        );

        /*
         * Flashed rather than returned as JSON, so the answer survives a refresh and the page
         * stays a page. A guard or a safety officer reading this will scroll it, screenshot it
         * and send it to somebody — and a result that vanishes on reload is a result they have to
         * generate twice.
         */
        return back()->with('decision', $decision);
    }

    /** What an unverifiable mandatory check does (COM-3). */
    public function updateFailSafe(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'outcome' => ['required', Rule::in(ComplianceDecisionService::UNVERIFIED_OUTCOMES)],
        ]);

        $before = app(ComplianceDecisionService::class)->unverifiedOutcome($companyId);

        DB::table('sheq_settings')->updateOrInsert(
            ['company_id' => $companyId],
            ['compliance_unverified_outcome' => $data['outcome'], 'updated_at' => now()],
        );

        DB::table('sheq_audit_log')->insert([
            'company_id' => $companyId, 'actor_id' => $request->user()?->id,
            'actor_type' => 'USER', 'action' => 'compliance.fail_safe_changed',
            'subject_type' => 'SheqSettings', 'subject_id' => 0,
            'before' => json_encode(['outcome' => $before]),
            'after' => json_encode(['outcome' => $data['outcome']]),
            'reason' => 'What happens when mandatory compliance cannot be verified.',
        ]);

        return back()->with('success', match ($data['outcome']) {
            ComplianceDecisionService::DENIED =>
                'A check that cannot be run will now refuse access.',
            ComplianceDecisionService::UNKNOWN =>
                'A check that cannot be run will be recorded and will not refuse access.',
            default => 'A check that cannot be run will now ask for a human decision.',
        });
    }

    /** The firm somebody arrived here from, so the screen can say whose roster it is showing. */
    private function contractorName(int $companyId, ?string $uuid): ?string
    {
        if ($uuid === null) {
            return null;
        }

        return DB::table('sheq_contractors')
            ->where('company_id', $companyId)->where('uuid', $uuid)->whereNull('deleted_at')
            ->value('name');
    }

    /** @return list<array<string,mixed>> */
    private function sites(int $companyId): array
    {
        return DB::table('sheq_sites')
            ->where('company_id', $companyId)->whereNull('deleted_at')->where('is_active', true)
            ->orderBy('name')->get(['id', 'name'])->map(fn ($s) => (array) $s)->all();
    }

    /** @return list<array<string,mixed>> */
    private function activities(int $companyId): array
    {
        return DB::table('sheq_activities')
            ->where('company_id', $companyId)->whereNull('deleted_at')->where('is_active', true)
            ->orderBy('name')
            ->get(['code', 'name', 'is_high_risk'])->map(fn ($a) => (array) $a)->all();
    }

    /**
     * The roster, named by employer.
     *
     * Capped, and ordered so the useful names are first. A tenant with four thousand contractor
     * workers would otherwise ship four thousand rows into a picker on every page load, and a
     * picker that takes six seconds to open is a picker nobody uses at a gate.
     */
    private function workers(int $companyId, ?string $contractorUuid = null,
        int $limit = 1000): array
    {
        return DB::table('sheq_contractor_workers as w')
            ->join('sheq_contractors as c', 'c.id', '=', 'w.contractor_id')
            ->where('w.company_id', $companyId)->whereNull('w.deleted_at')
            ->whereNull('c.deleted_at')->where('w.is_active', true)
            ->when($contractorUuid !== null, fn ($q) => $q->where('c.uuid', $contractorUuid))
            ->orderBy('c.name')->orderBy('w.name')->limit($limit)
            ->get(['w.uuid', 'w.name', 'w.trade', 'c.name as contractor_name'])
            ->map(fn ($w) => (array) $w)->all();
    }
}
