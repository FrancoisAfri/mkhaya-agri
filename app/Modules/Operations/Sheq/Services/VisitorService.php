<?php

namespace App\Modules\Operations\Sheq\Services;

use App\Modules\Operations\Sheq\Approvals\ApprovalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Visitor management (§13 of the SHEQ scope).
 *
 * The sequence is the product: **requested → approved → inducted → on site → signed out.** Each
 * gate exists because skipping it has killed people.
 *
 * *Approval* — a host accepts responsibility for someone who does not know the site. Configured
 * per company through the approvals engine, because a mine gate, a construction site and an office
 * reception have genuinely different ideas of who may authorise entry.
 *
 * *Induction before entry, not after* — a visitor is the person on site least likely to survive an
 * evacuation, because they do not know where the assembly point is and nobody will notice they are
 * missing. An approved visitor who has not been inducted is refused entry here.
 *
 * *Sign-out* — the register's real purpose is the roll call. After the alarm, the only question
 * that matters is who is still inside, and a system that records arrivals but not departures
 * answers it wrongly in the direction that gets a fire crew sent into an empty building.
 */
class VisitorService
{
    public function __construct(private readonly ApprovalService $approvals) {}

    /** Request a visit. Goes to whoever the company's workflow says approves entry. */
    public function request(int $companyId, array $data, int $requestedBy): array
    {
        if (trim($data['visitor_name'] ?? '') === '') {
            throw new InvalidArgumentException('A visit needs the visitor’s name for the roll call.');
        }

        return DB::transaction(function () use ($companyId, $data, $requestedBy) {
            $uuid = (string) Str::uuid();
            $reference = $this->nextReference($companyId);

            $id = DB::table('sheq_visits')->insertGetId([
                'uuid' => $uuid,
                'company_id' => $companyId,
                'site_id' => $data['site_id'] ?? null,
                'reference' => $reference,
                'visitor_name' => $data['visitor_name'],
                'visitor_company' => $data['visitor_company'] ?? null,
                'visitor_contact' => $data['visitor_contact'] ?? null,
                'vehicle_registration' => $data['vehicle_registration'] ?? null,
                'host_user_id' => $data['host_user_id'] ?? $requestedBy,
                'purpose' => $data['purpose'] ?? null,
                'expected_from' => $data['expected_from'] ?? null,
                'expected_to' => $data['expected_to'] ?? null,
                'status' => 'REQUESTED',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $approval = $this->approvals->open($companyId, [
                'subject_type' => 'VISITOR',
                'subject_id' => $id,
                'subject_label' => $reference.' — '.$data['visitor_name'],
                'site_id' => $data['site_id'] ?? null,
                'requested_by' => $requestedBy,
                'note' => $data['purpose'] ?? null,
            ]);

            return ['id' => $id, 'uuid' => $uuid, 'reference' => $reference,
                'approval_uuid' => $approval['uuid'], 'status' => 'REQUESTED'];
        });
    }

    /** Apply the approval outcome. */
    public function applyApproval(int $companyId, int $visitId, bool $approved): void
    {
        DB::table('sheq_visits')->where('company_id', $companyId)->where('id', $visitId)
            ->update(['status' => $approved ? 'APPROVED' : 'REJECTED', 'updated_at' => now()]);
    }

    /**
     * Record the safety induction.
     *
     * The signature is the evidence. "We told them where the assembly point is" is not a defence
     * after somebody is unaccounted for; a signed acknowledgement is.
     */
    public function recordInduction(int $companyId, string $uuid, array $data): void
    {
        $visit = $this->find($companyId, $uuid);

        if ($visit->status !== 'APPROVED') {
            throw new RuntimeException(sprintf(
                'Visit %s is %s. A visit must be approved before induction — inducting somebody '
                .'nobody has agreed to host puts them on site on the strength of a form.',
                $visit->reference, strtolower($visit->status),
            ));
        }

        if (empty($data['signature'])) {
            throw new InvalidArgumentException(
                'The induction must be signed by the visitor. An unsigned induction is a record '
                .'that we intended to brief them.'
            );
        }

        DB::table('sheq_visits')->where('id', $visit->id)->update([
            'status' => 'INDUCTED',
            'induction_completed_at' => now(),
            'induction_signature' => $data['signature'],
            'nda_accepted' => (bool) ($data['nda_accepted'] ?? false),
            'ppe_issued' => json_encode($data['ppe_issued'] ?? []),
            'badge_code' => $this->badgeCode(),
            'updated_at' => now(),
        ]);
    }

    /** Sign in at the gate. */
    public function signIn(int $companyId, string $uuid): array
    {
        $visit = $this->find($companyId, $uuid);

        if ($visit->status !== 'INDUCTED') {
            throw new RuntimeException(sprintf(
                'Visit %s cannot be signed in: it is %s. A visitor who has not been inducted does '
                .'not know where the assembly point is, and is the person least likely to get out.',
                $visit->reference, strtolower($visit->status),
            ));
        }

        DB::table('sheq_visits')->where('id', $visit->id)->update([
            'status' => 'ON_SITE', 'signed_in_at' => now(), 'updated_at' => now(),
        ]);

        return ['badge_code' => $visit->badge_code, 'reference' => $visit->reference];
    }

    public function signOut(int $companyId, string $uuid): void
    {
        $visit = $this->find($companyId, $uuid);

        if ($visit->status !== 'ON_SITE') {
            throw new RuntimeException("Visit {$visit->reference} is not signed in.");
        }

        DB::table('sheq_visits')->where('id', $visit->id)->update([
            'status' => 'SIGNED_OUT', 'signed_out_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Who is on site right now.
     *
     * The roll call. This is the screen somebody opens at the assembly point with an alarm
     * sounding, so it returns names and contact numbers rather than identifiers.
     */
    public function onSite(int $companyId, ?int $siteId = null): array
    {
        return DB::table('sheq_visits as v')
            ->leftJoin('users as u', 'u.id', '=', 'v.host_user_id')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->where('v.company_id', $companyId)->where('v.status', 'ON_SITE')
            ->whereNull('v.deleted_at')
            ->when($siteId !== null, fn ($q) => $q->where('v.site_id', $siteId))
            ->orderBy('v.signed_in_at')
            ->get(['v.uuid', 'v.reference', 'v.visitor_name', 'v.visitor_company',
                'v.visitor_contact', 'v.vehicle_registration', 'v.signed_in_at', 'v.badge_code',
                'p.first_name', 'p.surname'])
            ->map(fn ($v) => [
                'uuid' => $v->uuid,
                'reference' => $v->reference,
                'visitor_name' => $v->visitor_name,
                'visitor_company' => $v->visitor_company,
                'visitor_contact' => $v->visitor_contact,
                'vehicle_registration' => $v->vehicle_registration,
                'badge_code' => $v->badge_code,
                'signed_in_at' => $v->signed_in_at,
                'host' => trim(($v->first_name ?? '').' '.($v->surname ?? '')) ?: '—',
            ])->values()->all();
    }

    /**
     * Visitors still signed in after their expected departure.
     *
     * Usually somebody who forgot to sign out, occasionally somebody still inside. Both need
     * chasing, and only one of them is harmless.
     */
    public function overstaying(int $companyId): array
    {
        return collect($this->onSite($companyId))->filter(function ($visit) use ($companyId) {
            $expected = DB::table('sheq_visits')->where('company_id', $companyId)
                ->where('uuid', $visit['uuid'])->value('expected_to');

            return $expected !== null && Carbon::parse($expected)->isPast();
        })->values()->all();
    }

    private function find(int $companyId, string $uuid): object
    {
        $visit = DB::table('sheq_visits')->where('company_id', $companyId)
            ->where('uuid', $uuid)->whereNull('deleted_at')->first();

        if ($visit === null) {
            throw new RuntimeException("No visit {$uuid} in this company.");
        }

        return $visit;
    }

    /** Short, unambiguous on a printed badge — no O/0 or I/1 confusion at a gate. */
    private function badgeCode(): string
    {
        return 'V-'.strtoupper(Str::random(3)).'-'
            .str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
    }

    private function nextReference(int $companyId): string
    {
        $prefix = 'VIS-'.now()->format('Ym').'-';

        $last = DB::table('sheq_visits')->where('company_id', $companyId)
            ->where('reference', 'like', $prefix.'%')
            ->orderByDesc('reference')->value('reference');

        $next = $last === null ? 1 : ((int) Str::afterLast($last, '-')) + 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
