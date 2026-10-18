<?php

namespace App\Modules\Operations\Sheq\Approvals;

use App\Core\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Decides whether a given person may act on a given approval step.
 *
 * Kept apart from {@see ApprovalService} because this is the part that has to know about the rest
 * of the platform — roles, line management, competency records — and the approval state machine
 * should not.
 *
 * The resolution is deliberately done **at the moment of approval**, not stored as a list of user
 * ids when the workflow is designed. A workflow written last year that says "the line manager" must
 * still find the right person after a reorganisation, and one that says "a holder of a valid 2.6.1
 * appointment" must stop finding somebody whose appointment lapsed last month. A stored list of
 * names would quietly keep granting authority to people who no longer hold it, which is the exact
 * failure that makes an approval chain worthless.
 */
class ApproverResolver
{
    /** Whether this user may decide this step. */
    public function canAct(int $companyId, array $step, int $userId, object $request): bool
    {
        return match (strtoupper($step['approver_type'] ?? '')) {
            'USER' => $this->isNamedUser($step, $userId),
            'ROLE' => $this->holdsRole($userId, (string) ($step['approver_ref'] ?? '')),
            'SITE_ROLE' => $this->holdsRole($userId, (string) ($step['approver_ref'] ?? ''))
                && $this->isAtSite($companyId, $userId, $request->site_id),
            'LINE_MANAGER' => $this->isLineManagerOf($companyId, $userId, (int) $request->requested_by),
            'COMPETENCY_HOLDER' => $this->holdsCompetency($companyId, $userId,
                (string) ($step['approver_ref'] ?? '')),
            // Any authenticated user in the tenant. Useful for a light acknowledgement step, and
            // still subject to the self-approval refusal in ApprovalService.
            'ANY_APPROVER' => true,
            default => false,
        };
    }

    /** Human-readable, for the audit record and for the refusal message. */
    public function describe(array $step): string
    {
        $ref = $step['approver_ref'] ?? null;

        return match (strtoupper($step['approver_type'] ?? '')) {
            'USER' => 'a named approver',
            'ROLE' => "anyone with the {$ref} role",
            'SITE_ROLE' => "a {$ref} at this site",
            'LINE_MANAGER' => "the requester's line manager",
            'COMPETENCY_HOLDER' => "a holder of {$ref}",
            'ANY_APPROVER' => 'any authorised user',
            default => 'nobody (this step is misconfigured)',
        };
    }

    /**
     * Everyone who could act on a step — used to notify them, and to warn a workflow designer
     * that a step has no possible approver before it deadlocks a real request.
     *
     * @return list<int>
     */
    public function candidates(int $companyId, array $step, ?int $siteId = null,
        ?int $requesterId = null): array
    {
        $type = strtoupper($step['approver_type'] ?? '');

        if ($type === 'USER') {
            $id = (int) ($step['approver_ref'] ?? 0);

            return $id > 0 ? [$id] : [];
        }

        if ($type === 'LINE_MANAGER') {
            $manager = $requesterId === null ? null : $this->lineManagerId($companyId, $requesterId);

            return $manager === null ? [] : [$manager];
        }

        if ($type === 'COMPETENCY_HOLDER') {
            return $this->competencyHolders($companyId, (string) ($step['approver_ref'] ?? ''));
        }

        if (in_array($type, ['ROLE', 'SITE_ROLE'], true)) {
            return $this->roleHolders($companyId, (string) ($step['approver_ref'] ?? ''));
        }

        return [];
    }

    private function isNamedUser(array $step, int $userId): bool
    {
        return (int) ($step['approver_ref'] ?? 0) === $userId;
    }

    private function holdsRole(int $userId, string $role): bool
    {
        if ($role === '') {
            return false;
        }

        $user = User::withoutGlobalScopes()->find($userId);

        return $user !== null && $user->hasRole($role);
    }

    /** @return list<int> */
    private function roleHolders(int $companyId, string $role): array
    {
        if ($role === '' || ! Schema::hasTable('model_has_roles')) {
            return [];
        }

        return DB::table('model_has_roles as mhr')
            ->join('roles as r', 'r.id', '=', 'mhr.role_id')
            ->join('users as u', 'u.id', '=', 'mhr.model_id')
            ->where('r.name', $role)
            ->where('u.company_id', $companyId)
            ->whereNull('u.deleted_at')
            ->pluck('u.id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Whether the user is the requester's manager.
     *
     * Read from HR rather than held in SHEQ. The reporting line changes when somebody is promoted,
     * and a copy here would keep routing approvals to their old manager.
     */
    private function isLineManagerOf(int $companyId, int $userId, int $requesterId): bool
    {
        $manager = $this->lineManagerId($companyId, $requesterId);

        return $manager !== null && $manager === $userId;
    }

    private function lineManagerId(int $companyId, int $requesterId): ?int
    {
        if (! Schema::hasTable('hr_people')) {
            return null;
        }

        $person = DB::table('hr_people')->where('user_id', $requesterId)
            ->where('company_id', $companyId)->first(['id', 'manager_id']);

        if ($person === null || empty($person->manager_id)) {
            return null;
        }

        $managerUserId = DB::table('hr_people')->where('id', $person->manager_id)
            ->value('user_id');

        return $managerUserId === null ? null : (int) $managerUserId;
    }

    /**
     * Whether the user holds a live competency.
     *
     * Expiry and revocation both disqualify. This is the whole reason a workflow can name a
     * competency rather than a person: a legal appointment that has lapsed must stop conferring
     * the authority it granted, immediately and without anybody remembering to update a list.
     */
    private function holdsCompetency(int $companyId, int $userId, string $code): bool
    {
        if ($code === '') {
            return false;
        }

        return in_array($userId, $this->competencyHolders($companyId, $code), true);
    }

    /** @return list<int> */
    private function competencyHolders(int $companyId, string $code): array
    {
        if ($code === '' || ! Schema::hasTable('sheq_competency_records')) {
            return [];
        }

        return DB::table('sheq_competency_records as cr')
            ->join('sheq_competencies as c', 'c.id', '=', 'cr.competency_id')
            ->join('hr_people as p', 'p.id', '=', 'cr.hr_person_id')
            ->where('cr.company_id', $companyId)
            ->where('c.code', $code)
            ->whereNull('cr.deleted_at')->whereNull('cr.revoked_at')
            ->where(fn ($q) => $q->whereNull('cr.expires_at')->orWhere('cr.expires_at', '>=', now()))
            ->whereNotNull('p.user_id')
            ->pluck('p.user_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * Whether the user is associated with the site an approval concerns.
     *
     * Missing site data resolves to true rather than false: a SITE_ROLE step should degrade to a
     * plain role check rather than deadlocking every request at a company that has not mapped its
     * people to sites yet.
     */
    private function isAtSite(int $companyId, int $userId, $siteId): bool
    {
        if ($siteId === null || ! Schema::hasColumn('hr_people', 'sheq_site_id')) {
            return true;
        }

        $personSite = DB::table('hr_people')->where('user_id', $userId)
            ->where('company_id', $companyId)->value('sheq_site_id');

        return $personSite === null || (int) $personSite === (int) $siteId;
    }
}
