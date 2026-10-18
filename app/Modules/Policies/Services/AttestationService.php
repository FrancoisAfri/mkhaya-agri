<?php

namespace App\Modules\Policies\Services;

use App\Core\User\Models\User;
use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\Policies\Models\Policy;
use App\Modules\Policies\Models\PolicyAttestation;
use App\Modules\Policies\Models\PolicyAttestationReminder;
use App\Modules\Policies\Notifications\AttestationEscalationNotification;
use App\Modules\Policies\Notifications\AttestationReminderNotification;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AttestationService
{
    /**
     * Assign a policy attestation to one or more users.
     * Skips users who already have a pending/completed attestation for the same policy+version.
     */
    public function assignToUsers(Policy $policy, array $userIds, ?Carbon $deadline = null, ?int $campaignId = null): Collection
    {
        $versionId = $policy->current_version_id;
        $created   = collect();

        foreach ($userIds as $userId) {
            $existing = PolicyAttestation::where('policy_id', $policy->id)
                ->where('user_id', $userId)
                ->where('policy_version_id', $versionId)
                ->whereIn('status', ['pending', 'completed'])
                ->first();

            if ($existing) {
                continue;
            }

            $attestation = PolicyAttestation::create([
                'policy_id'         => $policy->id,
                'user_id'           => $userId,
                'policy_version_id' => $versionId,
                'campaign_id'       => $campaignId,
                'deadline'          => $deadline,
                'status'            => 'pending',
                'signature_token'   => Str::random(64),
            ]);

            $created->push($attestation);
        }

        return $created;
    }

    /**
     * Mark the attestation as viewed (employee opened the policy).
     */
    public function markViewed(PolicyAttestation $attestation): void
    {
        if (!$attestation->viewed_at) {
            $attestation->update(['viewed_at' => now()]);
        }
    }

    /**
     * Complete the attestation (employee acknowledged + signed).
     */
    public function complete(PolicyAttestation $attestation, array $data): PolicyAttestation
    {
        $attestation->update([
            'acknowledged_at' => now(),
            'signed_at'       => now(),
            'quiz_score'      => $data['quiz_score'] ?? null,
            'notes'           => $data['notes'] ?? null,
            'status'          => 'completed',
        ]);

        return $attestation->fresh();
    }

    /**
     * Mark overdue: set status=overdue for all pending attestations past their deadline.
     * Returns the count of records updated.
     */
    public function markOverdue(): int
    {
        return PolicyAttestation::where('status', 'pending')
            ->whereNotNull('deadline')
            ->where('deadline', '<', now())
            ->update(['status' => 'overdue']);
    }

    /**
     * Send reminder notifications to users whose deadline is within $daysThreshold days.
     * Skips users who already received a reminder within the last 24 h.
     */
    public function sendReminders(int $daysThreshold = 3): int
    {
        $windowEnd = now()->addDays($daysThreshold)->endOfDay();

        $pending = PolicyAttestation::with(['user', 'policy'])
            ->where('status', 'pending')
            ->whereNotNull('deadline')
            ->whereBetween('deadline', [now(), $windowEnd])
            ->get();

        $sent = 0;

        foreach ($pending as $attestation) {
            // Avoid duplicate reminders within 24 h
            $alreadySent = $attestation->reminders()
                ->where('type', 'reminder')
                ->where('sent_at', '>=', now()->subHours(24))
                ->exists();

            if ($alreadySent || !$attestation->user) {
                continue;
            }

            $daysLeft = (int) now()->diffInDays($attestation->deadline, false);

            $attestation->user->notify(new AttestationReminderNotification($attestation, max(0, $daysLeft)));

            PolicyAttestationReminder::create([
                'attestation_id' => $attestation->id,
                'type'           => 'reminder',
                'sent_at'        => now(),
            ]);

            $sent++;
        }

        return $sent;
    }

    /**
     * Escalate overdue attestations to each employee's direct manager.
     * Skips where escalation was already sent within the last 48 h.
     */
    public function escalateOverdue(): int
    {
        $overdue = PolicyAttestation::with(['user.person', 'policy'])
            ->where('status', 'overdue')
            ->get();

        $escalated = 0;

        foreach ($overdue as $attestation) {
            $alreadyEscalated = $attestation->reminders()
                ->where('type', 'escalation')
                ->where('sent_at', '>=', now()->subHours(48))
                ->exists();

            if ($alreadyEscalated) {
                continue;
            }

            $employee       = $attestation->user;
            $employeePerson = $employee?->person;

            if (!$employeePerson || !$employeePerson->manager_id) {
                continue;
            }

            // Resolve manager → User
            $managerPerson = HRPerson::with('user')
                ->find($employeePerson->manager_id);

            $manager = $managerPerson?->user;

            if (!$manager) {
                continue;
            }

            $employeeName = trim(($employeePerson->first_name ?? '') . ' ' . ($employeePerson->surname ?? ''));
            if ($employeeName === '') {
                $employeeName = $employee->email;
            }

            $manager->notify(new AttestationEscalationNotification($attestation, $employeeName));

            PolicyAttestationReminder::create([
                'attestation_id' => $attestation->id,
                'type'           => 'escalation',
                'sent_at'        => now(),
                'escalated_to'   => $manager->id,
            ]);

            $escalated++;
        }

        return $escalated;
    }

    /**
     * Dashboard stats: total assigned, completed, overdue, compliance % per department.
     */
    public function dashboardStats(): array
    {
        $total     = PolicyAttestation::count();
        $completed = PolicyAttestation::where('status', 'completed')->count();
        $overdue   = PolicyAttestation::whereIn('status', ['overdue'])
            ->orWhere(fn($q) => $q->where('status', 'pending')
                                   ->whereNotNull('deadline')
                                   ->where('deadline', '<', now()))
            ->count();
        $pending   = PolicyAttestation::where('status', 'pending')->count();

        $compliancePct = $total > 0 ? round(($completed / $total) * 100, 1) : 0;

        $byDepartment = $this->complianceByDepartment();

        return compact('total', 'completed', 'overdue', 'pending', 'compliancePct', 'byDepartment');
    }

    /**
     * Compliance percentage per department.
     */
    public function complianceByDepartment(): array
    {
        if (!Schema::hasTable('company_units') || !Schema::hasTable('company_unit_user_pivots')) {
            return [];
        }

        $rows = DB::table('policy_attestations as pa')
            ->join('users as u', 'u.id', '=', 'pa.user_id')
            ->join('company_unit_user_pivots as cup', 'cup.user_id', '=', 'u.id')
            ->join('company_units as cu', function ($j) {
                $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department');
            })
            ->select('cu.name as department')
            ->selectRaw('count(*) as total')
            ->selectRaw("sum(case when pa.status = 'completed' then 1 else 0 end) as completed_count")
            ->groupBy('cu.name')
            ->orderBy('cu.name')
            ->get();

        return $rows->map(fn($r) => [
            'department'        => $r->department,
            'total'             => (int) $r->total,
            'completed'         => (int) $r->completed_count,
            'compliance_pct'    => $r->total > 0 ? round(($r->completed_count / $r->total) * 100, 1) : 0,
        ])->toArray();
    }

    /**
     * List employees who have NOT acknowledged a given policy.
     */
    public function unacknowledgedFor(Policy $policy): Collection
    {
        return PolicyAttestation::with(['user.person'])
            ->where('policy_id', $policy->id)
            ->whereIn('status', ['pending', 'overdue'])
            ->get()
            ->map(fn($a) => [
                'id'          => $a->id,
                'user_id'     => $a->user_id,
                'name'        => $a->user?->name,
                'email'       => $a->user?->email,
                'deadline'    => $a->deadline?->toDateString(),
                'status'      => $a->status,
                'viewed_at'   => $a->viewed_at?->toDateTimeString(),
            ]);
    }
}
