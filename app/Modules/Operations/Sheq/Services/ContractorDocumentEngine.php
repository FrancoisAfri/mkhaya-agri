<?php

namespace App\Modules\Operations\Sheq\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * When a contractor document warns, where it applies, and what it is for (CON-8).
 *
 * Expiry on its own is a date comparison anybody can write. What makes this an engine rather than a
 * `where expires_on < today` is the three questions around it:
 *
 * **When should it warn?** Per document, because the lead time to replace one is a property of the
 * document and not of the system. A public liability certificate renewed through a broker needs
 * three months; a site permit needs a week. One global window either nags about everything or warns
 * too late about the thing that takes longest to replace — and a system that nags gets filtered.
 *
 * **Where does it apply?** A contractor working at four sites holds one COID letter and four
 * site-specific approvals. Demanding the site document at every gate blocks them at three sites for
 * no reason, and a practitioner's answer to that is to stop recording site documents.
 *
 * **What is it for?** A rigging ticket matters for lifting and not for painting. Without activity
 * applicability the file cannot say "they may paint but not lift", which is precisely the question
 * a permit issuer is asking.
 *
 * The gates are 90, 60, 30, 14, 7 and 0 days, and each fires once per document per expiry date. The
 * guard is a claimed row rather than a check-then-write: a retried command, two queue workers and
 * somebody pressing the button by hand must between them produce one alert, and only a unique index
 * guarantees that. A renewal moves the expiry, which is a new cycle and earns its own gates — the
 * same date would otherwise suppress the warnings for the document that replaced it.
 */
class ContractorDocumentEngine
{
    /** CON-8's gates. The same ladder the competency reminders use, for the same reason. */
    public const GATES = [90, 60, 30, 14, 7, 0];

    /** Used where neither the document nor the tenant has an opinion. */
    public const DEFAULT_WARNING_DAYS = 30;

    /** A document that has been supplied but not yet accepted does not satisfy anything. */
    public const SUPPLIED = 'SUPPLIED';

    public const ACCEPTED = 'ACCEPTED';

    public const REJECTED = 'REJECTED';

    public const STATUSES = [self::SUPPLIED, self::ACCEPTED, self::REJECTED];

    /**
     * The documents in a contractor's live file, with everything the gates and the status need.
     *
     * Superseded and soft-deleted rows are excluded here, once, so no caller has to remember to.
     *
     * @return list<object>
     */
    public function liveDocuments(int $companyId, int $contractorId): array
    {
        return DB::table('sheq_contractor_documents')
            ->where('company_id', $companyId)->where('contractor_id', $contractorId)
            ->whereNull('deleted_at')->whereNull('superseded_at')
            ->orderByRaw('expires_on desc nulls last')
            ->get(['id', 'uuid', 'document_type', 'title', 'reference', 'issuing_body',
                'issued_on', 'expires_on', 'warning_days', 'site_id', 'activities', 'status',
                'version', 'replaces_document_id', 'is_mandatory'])
            ->all();
    }

    /**
     * Does this document count towards compliance for the work being asked about?
     *
     * A null site or null activity list means "everywhere" and "all activities". Every document on
     * this installation predates these columns and therefore has both null, and the safe reading of
     * silence for a compliance document is that it applies.
     */
    public function appliesTo(object $document, ?int $siteId = null, ?string $activity = null): bool
    {
        if ($document->site_id !== null && $siteId !== null
            && (int) $document->site_id !== $siteId) {
            return false;
        }

        $activities = $this->activitiesOf($document);

        if ($activities === [] || $activity === null) {
            return true;
        }

        return in_array(strtoupper($activity), $activities, true);
    }

    /** @return list<string> */
    public function activitiesOf(object $document): array
    {
        $decoded = json_decode((string) ($document->activities ?? ''), true);

        return is_array($decoded)
            ? array_values(array_map(fn ($a) => strtoupper((string) $a), array_filter($decoded)))
            : [];
    }

    /**
     * Where this document stands: ACCEPTED and in date, or one of the ways it does not count.
     *
     * `NOT_ACCEPTED` is reported separately from `EXPIRED` and `MISSING` because it is a different
     * conversation: the paperwork arrived and nobody has checked it. Collapsing it into "valid"
     * is how an unchecked certificate satisfies a requirement; collapsing it into "missing" sends
     * somebody to chase a document that is sitting in the system.
     *
     * @return array{state:string, days_left:?int, warns_at:int, warning:bool}
     */
    public function stateOf(object $document, int $defaultWarningDays, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $warnsAt = (int) ($document->warning_days ?? $defaultWarningDays);

        $daysLeft = $document->expires_on === null ? null
            : (int) round($today->diffInDays(Carbon::parse($document->expires_on), false));

        if (strtoupper((string) $document->status) === self::REJECTED) {
            return ['state' => 'REJECTED', 'days_left' => $daysLeft,
                'warns_at' => $warnsAt, 'warning' => false];
        }

        if (strtoupper((string) $document->status) === self::SUPPLIED) {
            return ['state' => 'NOT_ACCEPTED', 'days_left' => $daysLeft,
                'warns_at' => $warnsAt, 'warning' => false];
        }

        if ($daysLeft === null) {
            return ['state' => 'VALID', 'days_left' => null,
                'warns_at' => $warnsAt, 'warning' => false];
        }

        return [
            'state' => $daysLeft < 0 ? 'EXPIRED' : 'VALID',
            'days_left' => $daysLeft,
            'warns_at' => $warnsAt,
            // Its own window, not a global one: the whole point of warning_days.
            'warning' => $daysLeft >= 0 && $daysLeft <= $warnsAt,
        ];
    }

    /** The tenant's default warning window. */
    public function defaultWarningDays(int $companyId): int
    {
        $stored = DB::table('sheq_settings')->where('company_id', $companyId)
            ->value('contractor_document_warning_days');

        return $stored === null ? self::DEFAULT_WARNING_DAYS : (int) $stored;
    }

    /**
     * The gates that have come due for a document and have not been alerted yet.
     *
     * A gate is "due" when the document is inside it and no alert has been claimed for that gate
     * at that expiry date. Returns the gates in descending order so a document that was never
     * alerted — because it was filed a week before expiry, or because the cron was off — reports
     * the most urgent gate it has passed rather than silently skipping all of them.
     *
     * @return list<int>
     */
    public function gatesDue(int $companyId, object $document, ?Carbon $today = null): array
    {
        if ($document->expires_on === null) {
            return [];
        }

        $today ??= Carbon::today();
        $daysLeft = (int) round($today->diffInDays(Carbon::parse($document->expires_on), false));

        if ($daysLeft < 0) {
            // Past expiry. The expiry-day gate is the last thing this ladder says; after that it
            // is the compliance status's problem, not a notification's.
            return $this->unalerted($companyId, $document, [0]);
        }

        $warnsAt = (int) ($document->warning_days ?? $this->defaultWarningDays($companyId));

        $reached = array_values(array_filter(
            self::GATES,
            // Inside the document's own warning window and inside the gate.
            fn (int $gate) => $daysLeft <= $gate && $gate <= $warnsAt,
        ));

        // The tightest gate reached, not every gate above it: a document filed eight days before
        // expiry should warn once at 7, not four times for 90, 60, 30 and 14 as well.
        return $this->unalerted($companyId, $document, $reached === [] ? [] : [min($reached)]);
    }

    /**
     * Claim a gate. True the first time, false ever after.
     *
     * The unique index is the guarantee, not this read: between a retried command and two workers
     * the check-then-insert would both pass. CON-8 says the same gate must not alert twice, and
     * this is the only thing that makes that true under concurrency.
     */
    public function claimGate(int $companyId, int $documentId, int $gate, string $expiresOn): bool
    {
        return DB::table('sheq_contractor_document_alerts')->insertOrIgnore([
            'company_id' => $companyId,
            'document_id' => $documentId,
            'gate' => $gate,
            'expires_on' => $expiresOn,
            'notified_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]) > 0;
    }

    /** @return list<int> the gates with no alert claimed yet */
    private function unalerted(int $companyId, object $document, array $gates): array
    {
        if ($gates === []) {
            return [];
        }

        $sent = DB::table('sheq_contractor_document_alerts')
            ->where('company_id', $companyId)
            ->where('document_id', $document->id)
            ->where('expires_on', $document->expires_on)
            ->pluck('gate')->map(fn ($g) => (int) $g)->all();

        return array_values(array_diff($gates, $sent));
    }
}
