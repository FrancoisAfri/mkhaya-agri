<?php

namespace App\Modules\Operations\Sheq\Regulatory;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Whether a regulatory pack is fit to be the live authority (REG-FIX-4, REG-FIX-5).
 *
 * A pack decides whether an incident is reportable to the Department of Employment and Labour
 * within seven days, what the statutory deadline is, and which clauses appear in the legal
 * register. It is the most consequential content in the module and the least visible: nobody opens
 * it, everybody relies on it.
 *
 * Four questions, and only the first is absolute:
 *
 * **Has it been tampered with?** The checksum stored on the version row is recomputed from the
 * manifest itself. A mismatch means the stored manifest is not the one that was installed and
 * signed, and there is no acknowledgement anybody can give that makes running it acceptable —
 * because the whole point of the checksum is that it is not a matter of opinion.
 *
 * **Is it signed?** Absence of a signature is not evidence of tampering; it is a pack built in
 * development, or one a customer has authored themselves. It blocks nothing and requires an
 * acknowledgement.
 *
 * **Has a lawyer read it?** REG-FIX-5's requirement, and the one this module most needed a field
 * for: nothing recorded that a person qualified to say so had read the pack and agreed it states
 * the law. Also an acknowledgement, not a block.
 *
 * **Is the review still current?** A 2024 review does not cover a 2027 amendment, so a review
 * carries an expiry and an expired one counts as absent.
 *
 * The reason only tampering is a hard block is a practical one. A hard block on unreviewed packs
 * would stop every existing installation reporting incidents the day it shipped, and would be
 * worked around within a week — after which the gate exists and means nothing. An acknowledgement
 * that names the gaps, requires a sentence, and is kept against the activation is a control that
 * survives contact with a deadline.
 */
class PackAssurance
{
    /** A gap that cannot be acknowledged away. */
    public const TAMPERED = 'CHECKSUM_MISMATCH';

    /** Gaps an operator may accept responsibility for. */
    public const UNSIGNED = 'NOT_SIGNED';

    public const SIGNATURE_UNVERIFIED = 'SIGNATURE_NOT_VERIFIED';

    public const NOT_LEGALLY_REVIEWED = 'NOT_LEGALLY_REVIEWED';

    public const REVIEW_EXPIRED = 'LEGAL_REVIEW_EXPIRED';

    public const NOT_YET_EFFECTIVE = 'NOT_YET_EFFECTIVE';

    public const SUPERSEDED = 'EFFECTIVE_PERIOD_ENDED';

    /**
     * What each gap means and what to do about it.
     *
     * Declared so a screen and a refusal message say the same thing, and so a gap added later
     * cannot be one with no explanation — a compliance warning nobody can act on is a warning
     * that gets dismissed.
     */
    public const GAPS = [
        self::TAMPERED => [
            'blocking' => true,
            'label' => 'Contents do not match the installed checksum',
            'detail' => 'The stored manifest is not the one that was installed. Either it has '
                .'been edited in the database or the install was interrupted. Reinstall the pack '
                .'from its source — this is the one gap no acknowledgement can cover, because a '
                .'checksum is not a matter of judgement.',
        ],
        self::UNSIGNED => [
            'blocking' => false,
            'label' => 'Not signed',
            'detail' => 'No publisher signature accompanied this pack. That is normal for a pack '
                .'authored in-house and not for one supplied as statutory content.',
        ],
        self::SIGNATURE_UNVERIFIED => [
            'blocking' => false,
            'label' => 'Signature not verified',
            'detail' => 'A signature is present and was not checked against a trusted key at '
                .'install time. Reinstall with --require-signature to verify it.',
        ],
        self::NOT_LEGALLY_REVIEWED => [
            'blocking' => false,
            'label' => 'No legal review recorded',
            'detail' => 'Nobody qualified has recorded that this pack states the law correctly. '
                .'Until that is done the classifications and statutory deadlines it produces are '
                .'this system\'s reading of the regulations, not advice, and must not be '
                .'presented to a customer as statutory compliance.',
        ],
        self::REVIEW_EXPIRED => [
            'blocking' => false,
            'label' => 'Legal review has expired',
            'detail' => 'The review on this pack has passed the date it was certified to. '
                .'Regulations are amended; a review of an earlier text does not cover a later '
                .'one.',
        ],
        self::NOT_YET_EFFECTIVE => [
            'blocking' => false,
            'label' => 'Not yet effective',
            'detail' => 'This version comes into force on a future date. Activating it now means '
                .'incidents are classified against rules that are not yet law.',
        ],
        self::SUPERSEDED => [
            'blocking' => false,
            'label' => 'Effective period has ended',
            'detail' => 'This version was superseded. Incidents that occurred while it was in '
                .'force are still classified against it, which is correct — but activating it as '
                .'today\'s authority is not.',
        ],
    ];

    /**
     * Assess one pack version.
     *
     * @return array{version_id:int, pack_id:string, version:string, gaps:list<array<string,mixed>>,
     *     blocking:list<string>, acknowledgeable:list<string>, clean:bool,
     *     may_activate_accepted:bool, legal_review:?array<string,mixed>}
     */
    public function assess(int $versionId, ?Carbon $at = null): array
    {
        $at ??= Carbon::now();

        // Joined for the pack's slug and name: `pack_version.pack_id` is a numeric foreign key,
        // and a screen showing "257@2026.1.0" tells nobody which rulebook they are looking at.
        $row = DB::table('sheq_regulatory_pack_versions as v')
            ->leftJoin('sheq_regulatory_packs as p', 'p.id', '=', 'v.pack_id')
            ->where('v.id', $versionId)
            ->first(['v.*', 'p.pack_id as pack_slug', 'p.name as pack_name',
                'p.jurisdiction']);

        if ($row === null) {
            throw new InvalidArgumentException('No such regulatory pack version.');
        }

        $gaps = [];

        /*
         * Recomputed, not trusted. The stored checksum and the stored manifest are two columns on
         * the same row, so comparing them catches an edit to either — which is the realistic
         * failure here, since the realistic attacker on a self-hosted install is somebody with
         * psql and a deadline.
         */
        $manifest = json_decode((string) $row->manifest, true);

        if (! is_array($manifest)) {
            $gaps[] = self::TAMPERED;
        } else {
            $recomputed = hash('sha256', PackManifest::canonicalJson($manifest));

            if (! hash_equals((string) $row->checksum, $recomputed)) {
                $gaps[] = self::TAMPERED;
            }
        }

        if (empty($row->signature)) {
            $gaps[] = self::UNSIGNED;
        } elseif (! $row->signature_verified) {
            $gaps[] = self::SIGNATURE_UNVERIFIED;
        }

        if ($row->legal_reviewed_at === null) {
            $gaps[] = self::NOT_LEGALLY_REVIEWED;
        } elseif ($row->legal_review_valid_until !== null
            && Carbon::parse($row->legal_review_valid_until)->lt($at->copy()->startOfDay())) {
            $gaps[] = self::REVIEW_EXPIRED;
        }

        if ($row->effective_from !== null
            && Carbon::parse($row->effective_from)->gt($at->copy()->endOfDay())) {
            $gaps[] = self::NOT_YET_EFFECTIVE;
        }

        if ($row->effective_to !== null
            && Carbon::parse($row->effective_to)->lt($at->copy()->startOfDay())) {
            $gaps[] = self::SUPERSEDED;
        }

        $detailed = array_map(fn (string $code) => array_merge(
            self::GAPS[$code] ?? ['blocking' => false, 'label' => $code, 'detail' => ''],
            ['code' => $code],
        ), $gaps);

        $blocking = array_values(array_map(fn (array $g) => $g['code'],
            array_filter($detailed, fn (array $g) => $g['blocking'])));

        $acknowledgeable = array_values(array_map(fn (array $g) => $g['code'],
            array_filter($detailed, fn (array $g) => ! $g['blocking'])));

        return [
            'version_id' => (int) $row->id,
            'pack_id' => $row->pack_slug ?? (string) $row->pack_id,
            'pack_name' => $row->pack_name,
            'jurisdiction' => $row->jurisdiction,
            'version' => $row->version,
            'effective_from' => $row->effective_from,
            'effective_to' => $row->effective_to,
            'checksum' => $row->checksum,
            'signed' => ! empty($row->signature),
            'signature_verified' => (bool) $row->signature_verified,
            'gaps' => $detailed,
            'blocking' => $blocking,
            'acknowledgeable' => $acknowledgeable,
            'clean' => $detailed === [],
            // The question the activation screen actually asks.
            'may_activate_accepted' => $blocking === [],
            'requires_acknowledgement' => $blocking === [] && $acknowledgeable !== [],
            'legal_review' => $row->legal_reviewed_at === null ? null : [
                'reviewed_at' => $row->legal_reviewed_at,
                'reviewer_name' => $row->legal_reviewer_name,
                'reviewer_capacity' => $row->legal_reviewer_capacity,
                'reference' => $row->legal_review_reference,
                'valid_until' => $row->legal_review_valid_until,
                'notes' => $row->legal_review_notes,
            ],
            'release_metadata' => json_decode((string) $row->release_metadata, true) ?: null,
        ];
    }

    /**
     * Record that a qualified person has read this version and agrees it states the law
     * (REG-FIX-5).
     *
     * The capacity is required alongside the name, because "reviewed by J Smith" is worth very
     * little to a customer's legal department and "reviewed by J Smith, admitted attorney,
     * occupational health and safety" is the thing they ask for.
     */
    public function recordLegalReview(int $versionId, array $data, ?int $actorId = null): array
    {
        foreach (['reviewer_name', 'reviewer_capacity'] as $required) {
            if (trim((string) ($data[$required] ?? '')) === '') {
                throw new InvalidArgumentException(sprintf(
                    'A legal review needs a %s. A review nobody can attribute is not evidence '
                    .'that anybody read it.', str_replace('_', ' ', $required),
                ));
            }
        }

        $exists = DB::table('sheq_regulatory_pack_versions')->where('id', $versionId)->exists();

        if (! $exists) {
            throw new InvalidArgumentException('No such regulatory pack version.');
        }

        DB::table('sheq_regulatory_pack_versions')->where('id', $versionId)->update([
            'legal_reviewed_at' => $data['reviewed_at'] ?? now(),
            'legal_reviewer_name' => trim((string) $data['reviewer_name']),
            'legal_reviewer_capacity' => trim((string) $data['reviewer_capacity']),
            'legal_review_reference' => $data['reference'] ?? null,
            'legal_review_notes' => $data['notes'] ?? null,
            'legal_review_valid_until' => $data['valid_until'] ?? null,
            'legal_review_recorded_by' => $actorId,
            'updated_at' => now(),
        ]);

        /*
         * Logged against the PLATFORM, not a tenant.
         *
         * `sheq_audit_log.company_id` is NOT NULL, correctly — that log is tenant evidence and
         * every row in it answers a question about one customer. A pack version is shared product
         * content: the same za-ohsa 2026.1.0 governs everybody who activates it, so recording its
         * review there would mean either picking a company arbitrarily or writing the same fact
         * once per tenant. `activity_log` is the platform-wide sink and its `company_id` is
         * nullable, which is what a product-level act needs.
         */
        DB::table('activity_log')->insert([
            'log_name' => 'sheq.regulatory',
            'description' => 'PACK_LEGAL_REVIEW_RECORDED',
            'subject_type' => 'RegulatoryPackVersion',
            'subject_id' => $versionId,
            'causer_type' => $actorId === null ? null : 'App\\Core\\User\\Models\\User',
            'causer_id' => $actorId,
            'company_id' => null,
            'properties' => json_encode([
                'reviewer_name' => $data['reviewer_name'],
                'reviewer_capacity' => $data['reviewer_capacity'],
                'reference' => $data['reference'] ?? null,
                'valid_until' => $data['valid_until'] ?? null,
            ]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->assess($versionId);
    }

    /** Record the release provenance of a version (REG-FIX-4). */
    public function recordRelease(int $versionId, array $metadata, ?int $actorId = null): array
    {
        $exists = DB::table('sheq_regulatory_pack_versions')->where('id', $versionId)->exists();

        if (! $exists) {
            throw new InvalidArgumentException('No such regulatory pack version.');
        }

        DB::table('sheq_regulatory_pack_versions')->where('id', $versionId)->update([
            'release_metadata' => json_encode(array_filter([
                'publisher' => $metadata['publisher'] ?? null,
                'published_on' => $metadata['published_on'] ?? null,
                // What instrument this rulebook is a reading of. A gazette number here, a
                // statutory instrument number elsewhere — hence free-form.
                'source_instrument' => $metadata['source_instrument'] ?? null,
                'source_reference' => $metadata['source_reference'] ?? null,
                'changelog' => $metadata['changelog'] ?? null,
                'recorded_by' => $actorId,
                'recorded_at' => now()->toIso8601String(),
            ], fn ($v) => $v !== null && $v !== '')),
            'updated_at' => now(),
        ]);

        return $this->assess($versionId);
    }

    /**
     * Every installed version with its assurance state, for the packs screen.
     *
     * @return array<int, array<string,mixed>>
     */
    public function all(?Carbon $at = null): array
    {
        $ids = DB::table('sheq_regulatory_pack_versions')->orderBy('pack_id')->orderBy('version')
            ->pluck('id');

        $out = [];

        foreach ($ids as $id) {
            $out[(int) $id] = $this->assess((int) $id, $at);
        }

        return $out;
    }
}
