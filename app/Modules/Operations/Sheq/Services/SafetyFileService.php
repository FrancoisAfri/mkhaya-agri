<?php

namespace App\Modules\Operations\Sheq\Services;

use App\Modules\Operations\Sheq\Support\SafetyFileSections;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The digital contractor safety file (CON-6).
 *
 * A safety file is a lever-arch folder with dividers. What makes it a folder rather than a pile of
 * certificates is that an **empty divider is itself a finding** — the gap is the information. So
 * this assembles the sections a tenant demands and reports each one's state, including the ones
 * with nothing in them, which is precisely what a flat list of uploaded documents cannot do.
 *
 * It assembles; it does not store a second copy of anything. Documents live in
 * `sheq_contractor_documents`, workers in `sheq_contractor_workers`, inductions in
 * `sheq_induction_completions`, incidents in `sheq_incidents`, corrective actions in
 * `sheq_actions`. A safety file that held its own copies would be a third place for a contractor's
 * standing to disagree with itself.
 *
 * Industry-agnostic by construction. Nothing in here names an industry: the sections are rows, the
 * profiles are shipped starting points, and a tenant whose industry nobody anticipated uses the
 * general profile and adds what their work needs. The engine cannot tell the difference between a
 * mine and a bookkeeper, which is the property that makes it work for both.
 */
class SafetyFileService
{
    /** Shown as expiring, in the file and at the gate, when a document lapses within this. */
    private const EXPIRING_WINDOW_DAYS = 30;

    public function __construct(private readonly ContractorService $contractors) {}

    /**
     * The sections in force for this tenant.
     *
     * Their own rows if they have any; otherwise the shipped profile, so a fresh installation has a
     * working file rather than an empty one. This is why nothing had to be backfilled across every
     * company: the default is computed, not stored, until somebody changes it.
     *
     * @return list<array<string, mixed>>
     */
    public function effectiveSections(int $companyId): array
    {
        $own = DB::table('sheq_safety_file_sections')
            ->where('company_id', $companyId)->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('position')->orderBy('code')
            ->get(['code', 'name', 'describes', 'is_required', 'position']);

        if ($own->isNotEmpty()) {
            return $own->map(fn ($row) => $this->describe($row->code, [
                'name' => $row->name,
                'describes' => $row->describes,
                'is_required' => (bool) $row->is_required,
                'position' => (int) $row->position,
                'configured' => true,
            ]))->filter()->values()->all();
        }

        $profile = $this->profileFor($companyId);
        $required = array_flip(SafetyFileSections::profiles()[$profile]['required'] ?? []);
        $position = 0;

        return array_values(array_filter(array_map(
            fn (string $code) => $this->describe($code, [
                'is_required' => isset($required[$code]),
                'position' => $position++,
                'configured' => false,
            ]),
            array_keys(SafetyFileSections::catalogue()),
        )));
    }

    /** The shipped profile this tenant runs under. Null means they have not chosen: general. */
    public function profileFor(int $companyId): string
    {
        $stored = DB::table('sheq_settings')->where('company_id', $companyId)
            ->value('contractor_safety_file_profile');

        return isset(SafetyFileSections::profiles()[$stored]) ? (string) $stored : 'GENERAL';
    }

    /**
     * Adopt a shipped profile as this tenant's own sections.
     *
     * Copy-on-write: the rows are written so the tenant can then rename and re-order them. A
     * section they have already filed documents under keeps its documents whatever they rename it
     * to, because what satisfies a section is the catalogue's business and not the row's.
     *
     * Sections they added that the profile does not mention are left alone. Adopting a profile is
     * not meant to be destructive — somebody choosing "Mining" after adding three of their own
     * sections should not lose them.
     */
    public function applyProfile(int $companyId, string $profile, ?int $actorId = null): int
    {
        $definition = SafetyFileSections::profiles()[$profile] ?? null;

        if ($definition === null) {
            throw new RuntimeException("'{$profile}' is not a safety file profile.");
        }

        $required = array_flip($definition['required']);
        $written = 0;
        $position = 0;

        DB::transaction(function () use ($companyId, $profile, $definition, $required, &$written, &$position, $actorId) {
            foreach (SafetyFileSections::catalogue() as $code => $section) {
                $isRequired = isset($required[$code]);

                $existing = DB::table('sheq_safety_file_sections')
                    ->where('company_id', $companyId)->where('code', $code)
                    ->first(['id', 'name', 'is_required']);

                if ($existing === null) {
                    DB::table('sheq_safety_file_sections')->insert([
                        'uuid' => (string) Str::uuid(),
                        'company_id' => $companyId,
                        'code' => $code,
                        'name' => $section['name'],
                        'describes' => $section['describes'],
                        'is_required' => $isRequired,
                        'position' => $position++,
                        'is_active' => true,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $written++;

                    continue;
                }

                // Only what the profile decides — the name stays as the tenant has it, because a
                // profile change is a statement about what is demanded, not about wording.
                DB::table('sheq_safety_file_sections')->where('id', $existing->id)->update([
                    'is_required' => $isRequired,
                    'position' => $position++,
                    'deleted_at' => null,
                    'updated_at' => now(),
                ]);
                $written++;
            }

            DB::table('sheq_settings')->updateOrInsert(
                ['company_id' => $companyId],
                ['contractor_safety_file_profile' => $profile, 'updated_at' => now()],
            );

            $this->audit($companyId, $actorId, 'safety_file.profile_applied', 0,
                null, ['profile' => $profile, 'sections' => $written],
                sprintf('Safety file sections set from the %s profile.', $definition['name']));
        });

        return $written;
    }

    /**
     * One contractor's file, section by section, with the empty dividers shown as empty.
     *
     * @return array{profile:string, sections:list<array<string,mixed>>, summary:array<string,mixed>}
     */
    public function assemble(int $companyId, string $contractorUuid): array
    {
        $contractor = DB::table('sheq_contractors')
            ->where('company_id', $companyId)->where('uuid', $contractorUuid)
            ->whereNull('deleted_at')->first(['id', 'name']);

        if ($contractor === null) {
            throw new RuntimeException("No contractor {$contractorUuid} in this company.");
        }

        $contractorId = (int) $contractor->id;
        $documents = $this->documentsByType($companyId, $contractorId);
        $sections = [];

        foreach ($this->effectiveSections($companyId) as $section) {
            $sections[] = $section['kind'] === SafetyFileSections::RECORD
                ? $section + $this->fromRecords($companyId, $contractorId, $section)
                : $section + $this->fromDocuments($documents, $section);
        }

        $required = array_values(array_filter($sections, fn (array $s) => $s['is_required']));
        $satisfied = array_values(array_filter($required, fn (array $s) => $s['state'] === 'SATISFIED'));

        return [
            'profile' => $this->profileFor($companyId),
            'sections' => $sections,
            'summary' => [
                'sections' => count($sections),
                'required' => count($required),
                'satisfied' => count($satisfied),
                // Null rather than 100 where nothing is demanded: a file with no requirements is
                // not a complete file, it is an unconfigured one, and the difference matters to
                // whoever has to trust the number.
                'percent' => $required === []
                    ? null : round((count($satisfied) / count($required)) * 100, 1),
                'complete' => $required !== [] && count($satisfied) === count($required),
                'missing' => array_values(array_map(
                    fn (array $s) => ['code' => $s['code'], 'name' => $s['name'], 'state' => $s['state']],
                    array_filter($required, fn (array $s) => $s['state'] !== 'SATISFIED'),
                )),
            ],
        ];
    }

    /**
     * Rename a section, or change whether it is demanded.
     *
     * The first write makes the tenant's own rows exist, which is what stops the shipped profile
     * applying from then on. So a tenant editing one section inherits the rest of the profile as
     * rows rather than silently losing them.
     */
    public function configureSection(int $companyId, string $code, array $input, ?int $actorId = null): void
    {
        if (! isset(SafetyFileSections::catalogue()[$code])) {
            throw new RuntimeException("'{$code}' is not a safety file section.");
        }

        DB::transaction(function () use ($companyId, $code, $input, $actorId) {
            // Materialise the profile first, or editing one section would leave a file of one.
            if (! DB::table('sheq_safety_file_sections')->where('company_id', $companyId)->exists()) {
                $this->applyProfile($companyId, $this->profileFor($companyId), $actorId);
            }

            $before = DB::table('sheq_safety_file_sections')
                ->where('company_id', $companyId)->where('code', $code)
                ->first(['name', 'is_required', 'is_active']);

            $changes = array_filter([
                'name' => $input['name'] ?? null,
                'describes' => $input['describes'] ?? null,
            ], fn ($value) => $value !== null);

            foreach (['is_required', 'is_active'] as $flag) {
                if (array_key_exists($flag, $input)) {
                    $changes[$flag] = (bool) $input[$flag];
                }
            }

            if ($changes === []) {
                return;
            }

            DB::table('sheq_safety_file_sections')
                ->where('company_id', $companyId)->where('code', $code)
                ->update($changes + ['updated_at' => now()]);

            $this->audit($companyId, $actorId, 'safety_file.section_configured', 0,
                $before === null ? null : (array) $before, $changes,
                sprintf('Section %s changed.', $code));
        });
    }

    /** @return array<string, list<object>> contractor documents, grouped by type */
    private function documentsByType(int $companyId, int $contractorId): array
    {
        return DB::table('sheq_contractor_documents')
            ->where('company_id', $companyId)->where('contractor_id', $contractorId)
            ->whereNull('deleted_at')
            /*
             * Live documents, plus superseded ones that were replaced by a later version (CON-24).
             *
             * These two are different facts and this query used to treat them as one. A document
             * somebody SET ASIDE — issued to the wrong company, a mistyped expiry — leaves the
             * file, because the file is what the contractor is asserting and they are no longer
             * asserting that. A document superseded by its own renewal is a PRIOR VERSION: it was
             * genuinely on file, it is evidence of what was in place then, and a safety file is
             * an evidence pack that goes to a client.
             *
             * The distinction is whether anything replaced it. Automatic supersession happens
             * when the next version is filed and points back at it; a manual set-aside has no
             * successor. Nothing is counted as satisfying a section unless it is live — see
             * `fromDocuments` — so including the prior versions adds evidence without softening
             * the verdict.
             */
            ->where(function ($q) use ($companyId, $contractorId) {
                $q->whereNull('superseded_at')
                    ->orWhereExists(fn ($sub) => $sub->from('sheq_contractor_documents as later')
                        ->whereColumn('later.replaces_document_id',
                            'sheq_contractor_documents.id')
                        ->where('later.company_id', $companyId)
                        ->where('later.contractor_id', $contractorId)
                        ->whereNull('later.deleted_at'));
            })
            ->orderByRaw('expires_on desc nulls last')
            ->get(['uuid', 'document_type', 'title', 'reference', 'issuing_body', 'issued_on',
                'expires_on', 'file_path', 'status', 'site_id', 'warning_days', 'activities',
                'superseded_at', 'version'])
            ->groupBy('document_type')
            ->map(fn ($group) => $group->all())
            ->all();
    }

    /**
     * A document section's state.
     *
     * EXPIRED beats SATISFIED: a section holding only lapsed paperwork is not satisfied, and
     * showing it as satisfied with a warning is how an expired certificate stays on a file for a
     * year. MISSING and EXPIRED are told apart because they are different conversations — one is
     * "send us this", the other is "renew this".
     */
    private function fromDocuments(array $documents, array $section): array
    {
        $today = Carbon::today();
        $items = [];
        $live = 0;
        $expired = 0;
        $expiringSoon = 0;

        foreach ($section['types'] ?? [] as $type) {
            foreach ($documents[$type] ?? [] as $document) {
                $daysLeft = $document->expires_on === null ? null
                    : (int) round($today->diffInDays(Carbon::parse($document->expires_on), false));

                /*
                 * Only an accepted document satisfies a section (CON-8).
                 *
                 * Supplied-and-unchecked is listed in the section — somebody needs to see that it
                 * arrived — and does not satisfy it. A rejected one likewise: if it still counted,
                 * rejecting a certificate would have no effect on the file it was rejected from.
                 */
                $status = strtoupper((string) ($document->status ?? 'ACCEPTED'));

                /*
                 * A prior version never satisfies a section, whatever its status and whatever its
                 * expiry (CON-24). It is in the pack as evidence of what was on file; treating it
                 * as live would mean a renewal made the section twice as satisfied as it was, and
                 * a superseded certificate with a longer run than its replacement would hold a
                 * section open after it stopped counting.
                 */
                $superseded = ($document->superseded_at ?? null) !== null;
                $counts = $status === 'ACCEPTED' && ! $superseded;

                $isExpired = $daysLeft !== null && $daysLeft < 0;
                $live += ($counts && ! $isExpired) ? 1 : 0;
                $expired += ($counts && $isExpired) ? 1 : 0;
                $expiringSoon += (! $isExpired && $daysLeft !== null
                    && $daysLeft <= self::EXPIRING_WINDOW_DAYS) ? 1 : 0;

                $items[] = [
                    'uuid' => $document->uuid,
                    'document_type' => $document->document_type,
                    'title' => $document->title,
                    'reference' => $document->reference,
                    'issuing_body' => $document->issuing_body,
                    'issued_on' => $document->issued_on,
                    'expires_on' => $document->expires_on,
                    'days_left' => $daysLeft,
                    'expired' => $isExpired,
                    'status' => $status,
                    'counts' => $counts,
                    // So a client reading the pack can tell which certificate is the current one.
                    'superseded' => $superseded,
                    'version' => (int) ($document->version ?? 1),
                ];
            }
        }

        return [
            'items' => $items,
            'count' => count($items),
            'expiring_soon' => $expiringSoon,
            'state' => match (true) {
                $live > 0 => 'SATISFIED',
                $expired > 0 => 'EXPIRED',
                // Arrived and unchecked. Its own state, because sending somebody to chase a
                // document that is sitting in the system is how a gate loses credibility.
                $items !== [] => 'NOT_ACCEPTED',
                default => 'MISSING',
            },
        ];
    }

    /**
     * A record section's state, counted from what this system already holds.
     *
     * Only the five sources where the schema carries a real contractor link. A section counting a
     * table with no such link would read "0" for ever and look like a contractor failing to supply
     * something they had already supplied — so those sections hold documents instead, and say so.
     */
    private function fromRecords(int $companyId, int $contractorId, array $section): array
    {
        [$source, $argument] = array_pad(explode(':', (string) ($section['source'] ?? '')), 2, null);

        $workerIds = fn () => DB::table('sheq_contractor_workers')
            ->where('company_id', $companyId)->where('contractor_id', $contractorId)
            ->whereNull('deleted_at')->pluck('id');

        $count = match ($source) {
            'WORKERS' => DB::table('sheq_contractor_workers')
                ->where('company_id', $companyId)->where('contractor_id', $contractorId)
                ->whereNull('deleted_at')->where('is_active', true)->count(),

            // Certificates and medicals are files attached to the named workers. Contractor
            // workers hold no competency records of their own — they have no personnel record
            // here — so the evidence is the certificate itself.
            'WORKER_FILES' => DB::table('sheq_files')
                ->where('company_id', $companyId)
                ->where('subject_type', 'CONTRACTOR_WORKER')
                ->whereIn('subject_id', $workerIds())
                ->where('category', (string) $argument)
                ->whereNull('deleted_at')->count(),

            'INDUCTIONS' => DB::table('sheq_induction_completions')
                ->where('company_id', $companyId)
                ->where('subject_type', 'CONTRACTOR_WORKER')
                ->whereIn('subject_id', $workerIds())
                ->where('passed', true)->count(),

            'INCIDENTS' => DB::table('sheq_incidents')
                ->where('company_id', $companyId)->where('contractor_id', $contractorId)
                ->whereNull('deleted_at')->count(),

            // Actions raised from this contractor's incidents. Two hops, because an action records
            // the incident it came from rather than the contractor.
            'CAPA' => DB::table('sheq_actions')
                ->where('company_id', $companyId)
                ->where('source_type', 'INCIDENT')
                ->whereIn('source_id', DB::table('sheq_incidents')
                    ->where('company_id', $companyId)->where('contractor_id', $contractorId)
                    ->whereNull('deleted_at')->select('id'))
                ->whereNull('deleted_at')->count(),

            default => 0,
        };

        /*
         * Incidents and corrective actions are history, not evidence.
         *
         * A contractor with no incidents has an empty section and a complete file; demanding an
         * incident before letting them on site would be absurd. So these sections are satisfied by
         * existing at all — which is why a profile never marks them required, and why the state
         * here is reported as RECORDED rather than as a gap.
         */
        $isHistory = in_array($source, ['INCIDENTS', 'CAPA'], true);

        return [
            'items' => [],
            'count' => $count,
            'expiring_soon' => 0,
            'state' => $isHistory ? 'SATISFIED' : ($count > 0 ? 'SATISFIED' : 'MISSING'),
            'is_history' => $isHistory,
        ];
    }

    /** A catalogue section, with the tenant's overrides laid on top. */
    private function describe(string $code, array $overrides): ?array
    {
        $section = SafetyFileSections::catalogue()[$code] ?? null;

        if ($section === null) {
            // A tenant row for a code the catalogue no longer ships. Skipped rather than guessed
            // at: nothing here knows how to satisfy it.
            return null;
        }

        return [
            'code' => $code,
            'kind' => $section['kind'],
            'name' => $overrides['name'] ?? $section['name'],
            'describes' => $overrides['describes'] ?? $section['describes'],
            'types' => $section['types'] ?? [],
            'source' => $section['source'] ?? null,
            'expires' => $section['expires'] ?? false,
            'is_required' => (bool) $overrides['is_required'],
            'position' => (int) $overrides['position'],
            'configured' => (bool) $overrides['configured'],
        ];
    }

    private function audit(int $companyId, ?int $actorId, string $action, int $subjectId,
        ?array $before, ?array $after, ?string $reason = null): void
    {
        DB::table('sheq_audit_log')->insert([
            'company_id' => $companyId,
            'actor_id' => $actorId,
            'actor_type' => 'USER',
            'action' => $action,
            'subject_type' => 'SafetyFile',
            'subject_id' => $subjectId,
            'before' => $before ? json_encode($before) : null,
            'after' => $after ? json_encode($after) : null,
            'reason' => $reason,
        ]);
    }
}
