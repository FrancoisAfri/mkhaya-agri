<?php

namespace App\Modules\Operations\Sheq\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Site induction delivery — content, questions, NDA and signature.
 *
 * The induction is the last thing standing between somebody who has never been on the site and the
 * site itself. It has to work on a phone, at a gate, on a bad connection, in a language the person
 * reads — which is why the content lives in the database as structured sections rather than as a
 * linked PDF. A 4 MB slide deck is not an induction; it is a file nobody opens.
 *
 * Two rules carry the weight:
 *
 * **The version they saw is stamped on the completion.** Induction content changes — a new hazard,
 * a moved assembly point. A completion that pointed at "the induction" would silently claim the
 * person was briefed on content published after they left.
 *
 * **The pass mark defaults to 100%.** These are not exams to be graded on a curve. If a question is
 * worth asking at an induction, getting it wrong means the person does not yet know something they
 * need to know before they walk on site — and the remedy is to show them again, not to average it
 * away against the ones they got right.
 */
class InductionService
{
    /** Publish a version. Editing content always makes a new one. */
    public function publish(int $companyId, array $data, ?int $actorId = null): array
    {
        if (empty($data['content'])) {
            throw new InvalidArgumentException('An induction needs content to present.');
        }

        $code = strtoupper(trim($data['code'] ?? ''));

        if ($code === '') {
            throw new InvalidArgumentException('An induction needs a code so its versions line up.');
        }

        $latest = (int) DB::table('sheq_inductions')
            ->where('company_id', $companyId)->where('code', $code)
            ->max('version');

        $uuid = (string) Str::uuid();
        $version = $latest + 1;

        // Supersede rather than edit: completions against the old version stay truthful about
        // what that person was actually shown.
        DB::table('sheq_inductions')->where('company_id', $companyId)
            ->where('code', $code)->update(['is_active' => false, 'updated_at' => now()]);

        $id = DB::table('sheq_inductions')->insertGetId([
            'uuid' => $uuid,
            'company_id' => $companyId,
            'site_id' => $data['site_id'] ?? null,
            'code' => $code,
            'title' => $data['title'] ?? $code,
            'audience' => strtoupper($data['audience'] ?? 'VISITOR'),
            'content' => json_encode($data['content']),
            'questions' => json_encode($data['questions'] ?? []),
            'pass_mark' => $data['pass_mark'] ?? 100,
            'nda_text' => $data['nda_text'] ?? null,
            'requires_nda' => (bool) ($data['requires_nda'] ?? false),
            'requires_signature' => (bool) ($data['requires_signature'] ?? true),
            'validity_months' => $data['validity_months'] ?? 12,
            'version' => $version,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['id' => $id, 'uuid' => $uuid, 'code' => $code, 'version' => $version];
    }

    /**
     * What a person is shown.
     *
     * Answers are stripped before this leaves the server. Sending the correct answer to a device
     * and trusting the client not to look at it is not a quiz, it is an honesty system with a
     * network trace.
     */
    public function present(int $companyId, string $audience, ?int $siteId = null,
        ?string $sector = null): ?array
    {
        $sector = strtoupper($sector ?? $this->sectorFor($companyId));

        $induction = DB::table('sheq_inductions')
            ->where('audience', strtoupper($audience))
            ->where('is_active', true)->whereNull('deleted_at')
            // The tenant's own induction, or one from the shared sector library.
            ->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'))
            ->where(fn ($q) => $q->where('sector', $sector)->orWhere('sector', 'ALL'))
            ->when($siteId !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('site_id')
                ->orWhere('site_id', $siteId)))
            /*
             * Most specific wins, in this order:
             *   1. the tenant's own content over the shipped library — they know their site;
             *   2. a site-specific induction over a company-wide one;
             *   3. their sector over the generic ALL induction — a mine visitor must be told about
             *      self-rescuers, and a warehouse visitor must not have that minute wasted.
             */
            ->orderByRaw('company_id is null')
            ->orderByRaw('site_id is null')
            ->orderByRaw("sector = 'ALL'")
            ->first();

        if ($induction === null) {
            return null;
        }

        $questions = json_decode((string) $induction->questions, true) ?: [];

        return [
            'uuid' => $induction->uuid,
            'code' => $induction->code,
            'title' => $induction->title,
            'version' => (int) $induction->version,
            'content' => json_decode((string) $induction->content, true) ?: [],
            'questions' => array_map(fn ($q) => [
                'id' => $q['id'] ?? null,
                'text' => $q['text'] ?? '',
                'options' => $q['options'] ?? [],
            ], $questions),
            'pass_mark' => (int) $induction->pass_mark,
            'nda_text' => $induction->nda_text,
            'requires_nda' => (bool) $induction->requires_nda,
            'requires_signature' => (bool) $induction->requires_signature,
        ];
    }

    /**
     * The tenant's sector, inferred from the regulatory pack they run under.
     *
     * A company on the MHSA pack is a mine — that is what the pack means. Nothing else in the
     * platform records an industry, and asking for it again during SHEQ setup would be a question
     * whose answer we already hold.
     */
    private function sectorFor(int $companyId): string
    {
        $packId = DB::table('sheq_regulatory_pack_activations as a')
            ->join('sheq_regulatory_pack_versions as v', 'v.id', '=', 'a.pack_version_id')
            ->join('sheq_regulatory_packs as p', 'p.id', '=', 'v.pack_id')
            ->where('a.company_id', $companyId)->where('a.status', 'ACTIVE')
            ->whereNull('a.deactivated_at')
            ->value('p.pack_id');

        return match ($packId) {
            'za-mhsa' => 'MINING',
            default => 'ALL',
        };
    }

    /** The sectors the shared library covers, for a setup screen to offer. */
    public function sectors(): array
    {
        return DB::table('sheq_inductions')->whereNull('company_id')
            ->where('is_active', true)->distinct()->orderBy('sector')
            ->pluck('sector')->all();
    }

    /**
     * Record a completion.
     *
     * @param  array{subject_type:string, subject_id?:?int, person_name:string,
     *               person_identifier?:?string, answers?:array, nda_accepted?:bool,
     *               signature?:?string, language?:string, via?:string}  $data
     */
    public function complete(int $companyId, string $inductionUuid, array $data): array
    {
        $induction = DB::table('sheq_inductions')
            ->where('company_id', $companyId)->where('uuid', $inductionUuid)
            ->whereNull('deleted_at')->first();

        if ($induction === null) {
            throw new RuntimeException("No induction {$inductionUuid} in this company.");
        }

        if ($induction->requires_nda && empty($data['nda_accepted'])) {
            throw new InvalidArgumentException(
                'The agreement must be accepted before entry. It is the record that the person was '
                .'told the site rules and agreed to work under them.'
            );
        }

        if ($induction->requires_signature && empty($data['signature'])) {
            throw new InvalidArgumentException(
                'The induction must be signed. An unsigned induction records only that we intended '
                .'to brief them.'
            );
        }

        $questions = json_decode((string) $induction->questions, true) ?: [];
        $answers = $data['answers'] ?? [];
        $score = $this->score($questions, $answers);
        $passed = $questions === [] || $score >= (int) $induction->pass_mark;

        $uuid = (string) Str::uuid();

        $id = DB::table('sheq_induction_completions')->insertGetId([
            'uuid' => $uuid,
            'company_id' => $companyId,
            'induction_id' => $induction->id,
            'subject_type' => strtoupper($data['subject_type']),
            'subject_id' => $data['subject_id'] ?? null,
            'person_name' => $data['person_name'],
            'person_identifier' => $data['person_identifier'] ?? null,
            // The version they actually saw.
            'induction_version' => (int) $induction->version,
            'answers' => json_encode($answers),
            'score' => $score,
            'passed' => $passed,
            'nda_accepted' => (bool) ($data['nda_accepted'] ?? false),
            'nda_accepted_at' => ! empty($data['nda_accepted']) ? now() : null,
            'signature' => $data['signature'] ?? null,
            'completed_at' => now(),
            // Only a pass carries an expiry; a fail is not a shorter-lived induction, it is none.
            'expires_on' => $passed
                ? Carbon::today()->addMonths((int) $induction->validity_months)->toDateString()
                : null,
            'language' => $data['language'] ?? 'en',
            'completed_via' => strtoupper($data['via'] ?? 'WEB'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['id' => $id, 'uuid' => $uuid, 'score' => $score, 'passed' => $passed,
            'expires_on' => $passed
                ? Carbon::today()->addMonths((int) $induction->validity_months)->toDateString()
                : null,
            'failed_questions' => $this->failedQuestions($questions, $answers)];
    }

    /** Whether somebody holds a current induction — what the gate asks. */
    public function isCurrent(int $companyId, string $subjectType, int $subjectId,
        string $audience = 'CONTRACTOR'): bool
    {
        return DB::table('sheq_induction_completions as c')
            ->join('sheq_inductions as i', 'i.id', '=', 'c.induction_id')
            ->where('c.company_id', $companyId)
            ->where('c.subject_type', strtoupper($subjectType))
            ->where('c.subject_id', $subjectId)
            ->where('c.passed', true)
            ->where('i.audience', strtoupper($audience))
            ->where(fn ($q) => $q->whereNull('c.expires_on')
                ->orWhere('c.expires_on', '>=', now()->toDateString()))
            ->exists();
    }

    /** Inductions lapsing, so people are re-inducted before they are turned away at the gate. */
    public function expiringWithin(int $companyId, int $days = 30): array
    {
        $today = Carbon::today();

        return DB::table('sheq_induction_completions as c')
            ->join('sheq_inductions as i', 'i.id', '=', 'c.induction_id')
            ->where('c.company_id', $companyId)->where('c.passed', true)
            ->whereNotNull('c.expires_on')
            ->where('c.expires_on', '<=', $today->copy()->addDays($days)->toDateString())
            ->orderBy('c.expires_on')
            ->get(['c.uuid', 'c.person_name', 'c.person_identifier', 'c.expires_on',
                'c.subject_type', 'i.title'])
            ->map(fn ($c) => (array) $c + [
                'expired' => Carbon::parse($c->expires_on)->isPast(),
            ])->values()->all();
    }

    /** @return int percentage */
    private function score(array $questions, array $answers): int
    {
        if ($questions === []) {
            return 100;
        }

        $correct = 0;

        foreach ($questions as $question) {
            $id = $question['id'] ?? null;

            if ($id !== null && ($answers[$id] ?? null) == ($question['answer'] ?? null)) {
                $correct++;
            }
        }

        return (int) round(($correct / count($questions)) * 100);
    }

    /**
     * Which questions were wrong.
     *
     * Returned so the person can be shown the section again rather than simply told they failed —
     * an induction is teaching, not assessment, and "you scored 60%" teaches nothing.
     *
     * @return list<string>
     */
    private function failedQuestions(array $questions, array $answers): array
    {
        $failed = [];

        foreach ($questions as $question) {
            $id = $question['id'] ?? null;

            if ($id !== null && ($answers[$id] ?? null) != ($question['answer'] ?? null)) {
                $failed[] = $question['text'] ?? (string) $id;
            }
        }

        return $failed;
    }
}
