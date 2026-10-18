<?php

namespace App\Modules\Operations\Sheq\Adapters;

use App\Modules\Operations\Sheq\Contracts\CompetencyLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Derives competency state from LMS certificates (SHEQ-COMP-4, SHEQ-ADR-002).
 *
 * SHEQ stores the *reference* and the derived status — never the assessment, the score breakdown or
 * the content. Expiry is read from the certificate rather than recomputed, because LMS owns the
 * validity rules and two systems computing the same expiry is two systems disagreeing about it.
 *
 * Degrades per SHEQ-ENT-3: with LMS absent, `isAvailable()` is false and callers fall back to
 * manually captured certificates. The snapshot then records that the source was unavailable, which
 * is materially different from recording that the person was compliant.
 */
class LmsCompetencyLedger implements CompetencyLedger
{
    /** Days before expiry at which a competency is reported as EXPIRING (SHEQ-COMP-5's first gate). */
    private const EXPIRING_WINDOW_DAYS = 90;

    public function statusFor(int $hrPersonId): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        $rows = DB::table('lms_certificates as c')
            ->leftJoin('lms_certifications as d', 'd.id', '=', 'c.certification_id')
            ->where('c.hr_person_id', $hrPersonId)
            ->whereNull('c.deleted_at')
            ->orderByDesc('c.issued_at')
            ->get(['c.certificate_number', 'c.issued_at', 'c.expires_at', 'd.name as certification_name',
                'd.actions_blocked_when_expired']);

        // One row per competency: the most recently issued certificate is the governing one.
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            $competency = $row->certification_name ?: 'Certificate '.$row->certificate_number;
            if (isset($seen[$competency])) {
                continue;
            }
            $seen[$competency] = true;
            $out[] = $this->present($competency, $row);
        }

        return $out;
    }

    public function statusForCompetency(int $hrPersonId, string $competencyCode): ?array
    {
        foreach ($this->statusFor($hrPersonId) as $entry) {
            if (strcasecmp($entry['competency'], $competencyCode) === 0) {
                return [
                    'status' => $entry['status'],
                    'expires_at' => $entry['expires_at'],
                    'days_overdue' => $entry['days_until_expiry'] !== null && $entry['days_until_expiry'] < 0
                        ? abs($entry['days_until_expiry'])
                        : null,
                ];
            }
        }

        return null;
    }

    public function isAvailable(): bool
    {
        return Schema::hasTable('lms_certificates') && Schema::hasTable('lms_certifications');
    }

    /** @return array<string,mixed> */
    private function present(string $competency, object $row): array
    {
        $expiresAt = $row->expires_at ? Carbon::parse($row->expires_at) : null;

        // A certificate with no expiry never expires — do not invent one.
        $daysUntilExpiry = $expiresAt?->startOfDay()->diffInDays(Carbon::now()->startOfDay(), false);
        $daysUntilExpiry = $daysUntilExpiry === null ? null : -$daysUntilExpiry;

        $status = match (true) {
            $expiresAt === null => self::COMPLIANT,
            $daysUntilExpiry < 0 => self::EXPIRED,
            $daysUntilExpiry <= self::EXPIRING_WINDOW_DAYS => self::EXPIRING,
            default => self::COMPLIANT,
        };

        return [
            'competency' => $competency,
            'status' => $status,
            'achieved_at' => $row->issued_at ? Carbon::parse($row->issued_at)->toDateString() : null,
            'expires_at' => $expiresAt?->toDateString(),
            'days_until_expiry' => $daysUntilExpiry,
            'certificate_ref' => $row->certificate_number,
            'blocks_work' => (bool) ($row->actions_blocked_when_expired ?? false),
            'source' => 'LMS',
        ];
    }
}
