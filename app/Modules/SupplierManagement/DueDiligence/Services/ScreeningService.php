<?php

namespace App\Modules\SupplierManagement\DueDiligence\Services;

use App\Modules\SupplierManagement\DueDiligence\Support\CheckType;
use App\Modules\SupplierManagement\DueDiligence\Support\VerificationStatus;
use App\Modules\SupplierManagement\Models\ScreeningHit;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierPerson;
use App\Modules\SupplierManagement\Models\SupplierVerification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Screens a supplier and its people against sanctions, PEP and watchlist sources.
 *
 * The screening itself is the easy half. What makes it usable month after month is that a decision
 * somebody made once stays made: hits are upserted per (subject, list, name), so re-screening bumps
 * what was already there instead of raising it again. Without that, the second monthly run produces
 * the same forty alerts as the first, and by the third nobody is reading them.
 *
 * Who gets screened matters as much as against what. The supplier is screened as an entity, and
 * every current director, shareholder and beneficial owner as a person — because the entity is
 * rarely the one on a list. That includes the people the ownership graph resolved to, who are
 * frequently not on any form the supplier filled in.
 */
class ScreeningService
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly ScreeningMatcher $matcher,
        private readonly VerificationEngine $engine,
        private readonly OwnershipGraph $ownership,
    ) {}

    /**
     * Screen everybody connected to a supplier.
     *
     * @return array{subjects:int, new_hits:int, seen_again:int, unavailable:bool}
     */
    public function screen(Supplier $supplier, string $listType = ScreeningHit::TYPE_SANCTIONS): array
    {
        $checkType = $this->checkTypeFor($listType);
        $provider = $this->registry->forCheck($checkType);

        if (! $provider) {
            // Recorded, not skipped: an unscreened supplier has to look unscreened.
            $this->engine->verify($supplier, $checkType);

            return ['subjects' => 0, 'new_hits' => 0, 'seen_again' => 0, 'unavailable' => true];
        }

        $subjects = $this->subjects($supplier);
        $new = 0;
        $again = 0;

        foreach ($subjects as $subject) {
            $observation = $this->engine->verify(
                supplier: $supplier,
                checkType: $checkType,
                claimed: ['name' => $subject['name']],
                context: ['list_type' => $listType] + array_filter([
                    'dob' => $subject['dob'] ?? null,
                    'nationality' => $subject['nationality'] ?? null,
                ]),
                subjectType: $subject['type'],
                subjectId: $subject['id'],
                subjectLabel: $subject['name'],
            );

            $candidates = $this->matcher->rank($subject, $this->candidatesFrom($observation));

            foreach ($candidates as $candidate) {
                $result = $this->upsertHit($supplier, $subject, $candidate, $listType, $provider->code());
                $result === 'new' ? $new++ : $again++;
            }
        }

        return ['subjects' => count($subjects), 'new_hits' => $new, 'seen_again' => $again, 'unavailable' => false];
    }

    /**
     * Record a hit a person found themselves.
     *
     * The manual path matters more here than anywhere else in the module: the FIC's sanctions list is
     * published and somebody can check it today, whereas a licensed screening feed is a procurement
     * exercise. A hit recorded by hand adjudicates, scores and monitors exactly like a fetched one.
     */
    public function recordHit(
        Supplier $supplier,
        string $listType,
        string $matchedName,
        string $subjectType = 'supplier',
        ?int $subjectId = null,
        ?string $subjectName = null,
        ?string $listName = null,
        int $matchScore = 100,
        array $details = [],
        ?string $externalRef = null,
    ): ScreeningHit {
        $subject = [
            'type' => $subjectType,
            'id' => $subjectId,
            'name' => $subjectName ?: ($subjectType === 'person'
                ? (SupplierPerson::find($subjectId)?->name() ?? 'Unknown person')
                : $supplier->company_name),
        ];

        $this->upsertHit($supplier, $subject, [
            'name' => $matchedName,
            'list_name' => $listName,
            'match_score' => $matchScore,
            'match_basis' => [['factor' => 'manual', 'effect' => 'confirms', 'detail' => 'Recorded by a person from the published list.']],
            'details' => $details,
            'external_ref' => $externalRef,
        ], $listType, 'manual');

        return ScreeningHit::query()
            ->where('supplier_id', $supplier->id)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('list_type', $listType)
            ->where('matched_name', $matchedName)
            ->firstOrFail();
    }

    /**
     * Everybody who should be screened for this supplier.
     *
     * The people the ownership graph resolved to are included even when they are not on any form —
     * an owner nobody disclosed is exactly the one worth screening.
     *
     * @return array<int, array{type:string, id:int|null, name:string, dob:string|null, nationality:string|null, identifier:string|null}>
     */
    public function subjects(Supplier $supplier): array
    {
        $subjects = [[
            'type' => 'supplier',
            'id' => null,
            'name' => (string) $supplier->company_name,
            'dob' => null,
            'nationality' => null,
            'identifier' => $supplier->company_registration_number,
        ]];

        $people = SupplierPerson::query()
            ->where('supplier_id', $supplier->id)
            ->screenable()
            ->get();

        foreach ($people as $person) {
            $subjects[] = [
                'type' => 'person',
                'id' => $person->id,
                'name' => $person->name(),
                'dob' => $person->date_of_birth?->toDateString(),
                'nationality' => $person->nationality,
                'identifier' => $person->id_number,
            ];
        }

        return $subjects;
    }

    /**
     * Upsert one hit, preserving whatever a person already decided about it.
     *
     * @return string 'new' or 'seen_again'
     */
    private function upsertHit(Supplier $supplier, array $subject, array $candidate, string $listType, string $providerCode): string
    {
        $existing = ScreeningHit::query()
            ->where('supplier_id', $supplier->id)
            ->where('subject_type', $subject['type'])
            ->where('subject_id', $subject['id'])
            ->where('list_type', $listType)
            ->where('matched_name', $candidate['name'])
            ->first();

        if ($existing) {
            // Status, adjudicator and note are deliberately untouched. Re-raising a cleared hit is
            // the single fastest way to make continuous screening worthless.
            $existing->forceFill([
                'last_seen_at' => Carbon::now(),
                'times_seen' => $existing->times_seen + 1,
                'match_score' => $candidate['match_score'] ?? $existing->match_score,
                'match_basis' => $candidate['match_basis'] ?? $existing->match_basis,
                'details' => $candidate['details'] ?? $existing->details,
            ])->save();

            return 'seen_again';
        }

        ScreeningHit::create([
            'supplier_id' => $supplier->id,
            'subject_type' => $subject['type'],
            'subject_id' => $subject['id'],
            'subject_name' => $subject['name'],
            'list_type' => $listType,
            'list_name' => $candidate['list_name'] ?? null,
            'matched_name' => $candidate['name'],
            'external_ref' => $candidate['external_ref'] ?? null,
            'match_score' => $candidate['match_score'] ?? 0,
            'match_basis' => $candidate['match_basis'] ?? null,
            'details' => $candidate['details'] ?? null,
            'provider_code' => $providerCode,
            'status' => ScreeningHit::STATUS_OPEN,
            'first_seen_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
            'times_seen' => 1,
        ]);

        return 'new';
    }

    /**
     * A person's decision on a hit.
     *
     * Clearing something requires a reason. "This is not our supplier" is a judgement somebody may
     * have to defend a year later, and an unexplained dismissal cannot be told apart from a mis-click.
     */
    public function adjudicate(ScreeningHit $hit, string $status, ?string $note = null): ScreeningHit
    {
        if (in_array($status, [ScreeningHit::STATUS_FALSE_POSITIVE, ScreeningHit::STATUS_DISMISSED], true)
            && trim((string) $note) === '') {
            throw new \InvalidArgumentException('Clearing a screening match requires a reason.');
        }

        return $hit->adjudicate($status, Auth::id(), $note);
    }

    /**
     * The candidates a provider returned, off the observation it produced.
     *
     * Providers hand back their payload rather than writing hits themselves, which keeps every hit —
     * fetched or hand-recorded — on one path through this service.
     */
    private function candidatesFrom(SupplierVerification $observation): array
    {
        if ($observation->status === VerificationStatus::UNAVAILABLE || $observation->status === VerificationStatus::ERROR) {
            return [];
        }

        $observed = $observation->observed ?? [];

        return array_values(array_filter(
            (array) ($observed['matches'] ?? $observed['candidates'] ?? []),
            fn ($candidate) => is_array($candidate) && ! empty($candidate['name'])
        ));
    }

    private function checkTypeFor(string $listType): string
    {
        return match ($listType) {
            ScreeningHit::TYPE_PEP => CheckType::SCREENING_PEP,
            ScreeningHit::TYPE_ADVERSE => CheckType::MEDIA_ADVERSE,
            default => CheckType::SCREENING_SANCTIONS,
        };
    }
}
