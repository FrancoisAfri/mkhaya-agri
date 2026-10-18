<?php

namespace App\Modules\Operations\Sheq\Contracts;

/**
 * Whether a person is competent to do the work, as far as SHEQ is concerned (SHEQ-COMP-1).
 *
 * LMS is the engine; SHEQ is the governance layer. SHEQ never stores training content, assessments
 * or completion detail — only the derived state and the certificate reference.
 *
 * SHEQ-ENT-3 requires graceful degradation: when LMS is not licensed this must fall back to
 * manually captured certificates rather than fail, because "the customer has not bought LMS" is not
 * a reason to stop telling a safety officer that a ticket has expired.
 */
interface CompetencyLedger
{
    public const COMPLIANT = 'COMPLIANT';

    public const EXPIRING = 'EXPIRING';

    public const EXPIRED = 'EXPIRED';

    public const NOT_STARTED = 'NOT_STARTED';

    public const UNKNOWN = 'UNKNOWN';

    /**
     * Every competency SHEQ can see for this person, with derived status.
     *
     * @return list<array{
     *   competency:string, status:string, achieved_at:?string, expires_at:?string,
     *   days_until_expiry:?int, certificate_ref:?string, source:string
     * }>
     */
    public function statusFor(int $hrPersonId): array;

    /** @return array{status:string, expires_at:?string, days_overdue:?int}|null */
    public function statusForCompetency(int $hrPersonId, string $competencyCode): ?array;

    public function isAvailable(): bool;
}
