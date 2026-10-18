<?php

namespace App\Modules\Operations\Sheq\Adapters;

use App\Modules\Operations\Sheq\Contracts\CompetencyLedger;
use App\Modules\Operations\Sheq\Services\CompetencyService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The competency view used by permits and the incident context snapshot.
 *
 * Merges SHEQ's own records — manually captured certificates and legal appointments (SHEQ-COMP-7,
 * COMP-8) — with whatever LMS knows. Both matter: an expired forklift licence issued by an external
 * provider blocks a permit exactly as an expired LMS course does, and a tenant without LMS still
 * gets gating (SHEQ-ENT-3).
 *
 * SHEQ's own record wins where both exist. It is the governed one: it carries the appointment
 * scope, the revocation and the grace period, and it is what an auditor is shown.
 */
class SheqCompetencyLedger implements CompetencyLedger
{
    public function __construct(
        private readonly LmsCompetencyLedger $lms,
        private readonly CompetencyService $competencies,
    ) {}

    public function statusFor(int $hrPersonId): array
    {
        $entries = [];
        $seen = [];

        foreach ($this->sheqRecords($hrPersonId) as $entry) {
            $entries[] = $entry;
            $seen[strtolower($entry['competency'])] = true;
        }

        // LMS fills the gaps, never overwrites: a competency SHEQ governs is answered by SHEQ.
        foreach ($this->lms->statusFor($hrPersonId) as $entry) {
            if (! isset($seen[strtolower($entry['competency'])])) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    public function statusForCompetency(int $hrPersonId, string $competencyCode): ?array
    {
        foreach ($this->statusFor($hrPersonId) as $entry) {
            if (strcasecmp($entry['competency'], $competencyCode) === 0
                || strcasecmp($entry['code'] ?? '', $competencyCode) === 0) {
                return [
                    'status' => $entry['status'],
                    'expires_at' => $entry['expires_at'],
                    'days_overdue' => ($entry['days_until_expiry'] ?? 0) < 0
                        ? abs((int) $entry['days_until_expiry']) : null,
                ];
            }
        }

        return null;
    }

    /**
     * Available when SHEQ's own store exists, regardless of LMS — that is precisely the graceful
     * degradation SHEQ-ENT-3 asks for.
     */
    public function isAvailable(): bool
    {
        return Schema::hasTable('sheq_competency_records') || $this->lms->isAvailable();
    }

    /** @return list<array<string,mixed>> */
    private function sheqRecords(int $hrPersonId): array
    {
        if (! Schema::hasTable('sheq_competency_records')) {
            return [];
        }

        return DB::table('sheq_competency_records as r')
            ->join('sheq_competencies as c', 'c.id', '=', 'r.competency_id')
            ->where('r.hr_person_id', $hrPersonId)
            ->whereNull('r.deleted_at')
            ->get([
                'r.status', 'r.achieved_at', 'r.expires_at', 'r.certificate_number', 'r.source',
                'r.revoked_at', 'r.accepted_at', 'r.appointment_scope',
                'c.code', 'c.title', 'c.competency_type', 'c.blocks_work_when_expired',
            ])
            ->map(function ($row) {
                $daysUntilExpiry = $row->expires_at
                    ? (int) Carbon::today()->diffInDays(Carbon::parse($row->expires_at), false)
                    : null;

                return [
                    'competency' => $row->title,
                    'code' => $row->code,
                    'type' => $row->competency_type,
                    'status' => $this->competencies->deriveStatus($row),
                    'achieved_at' => $row->achieved_at,
                    'expires_at' => $row->expires_at,
                    'days_until_expiry' => $daysUntilExpiry,
                    'certificate_ref' => $row->certificate_number,
                    'blocks_work' => (bool) $row->blocks_work_when_expired,
                    'appointment_scope' => $row->appointment_scope,
                    'source' => 'SHEQ:'.$row->source,
                ];
            })->all();
    }
}
