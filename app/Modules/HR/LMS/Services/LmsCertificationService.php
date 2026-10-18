<?php

namespace App\Modules\HR\LMS\Services;

use App\Core\User\Models\User;
use App\Modules\HR\LMS\Models\CertificationExpiryLog;
use App\Modules\HR\LMS\Models\EmployeeCertification;
use App\Modules\HR\LMS\Models\LmsCertificate;
use App\Modules\HR\LMS\Models\LmsCertificateTemplate;
use App\Modules\HR\LMS\Models\LmsCertification;
use App\Modules\HR\LMS\Models\LmsEnrollment;
use App\Modules\HR\LMS\Models\LmsUnderstandingScore;
use App\Modules\HR\LMS\Notifications\CertificateExpiryReminderNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LmsCertificationService
{
    // ── Template management ───────────────────────────────────────────────────

    /**
     * Create a certificate template for customised visual design.
     */
    public function createCertificateTemplate(array $data, int $createdBy): LmsCertificateTemplate
    {
        return LmsCertificateTemplate::create(array_merge($data, ['created_by' => $createdBy]));
    }

    /**
     * Update an existing template.
     */
    public function updateCertificateTemplate(LmsCertificateTemplate $template, array $data): LmsCertificateTemplate
    {
        $template->update($data);
        return $template->fresh();
    }

    // ── Certification program management ─────────────────────────────────────

    /**
     * Create a certification program definition linking a course to cert rules.
     */
    public function createCertificationProgram(array $data, int $createdBy): LmsCertification
    {
        return LmsCertification::create(array_merge($data, ['created_by' => $createdBy]));
    }

    // ── Issue certificates ────────────────────────────────────────────────────

    /**
     * Issue a training certificate for a completed enrollment.
     *
     * Gates:
     *   1. Enrollment must be 'completed' with a passing score.
     *   2. If the linked certification requires an understanding score, that
     *      score must meet the minimum before we issue.
     *   3. If the enrollment already has a certificate, return it (idempotent).
     *
     * @throws \RuntimeException when gating conditions are not met.
     */
    public function issueTrainingCertificate(LmsEnrollment $enrollment, int $actorId): LmsCertificate
    {
        $enrollment->loadMissing(['course', 'user']);

        // Gate 1: must be completed and passed
        if ($enrollment->status !== 'completed') {
            throw new \RuntimeException("Cannot issue certificate — enrollment is not completed.");
        }
        if ($enrollment->score < ($enrollment->course?->pass_score ?? 0)) {
            throw new \RuntimeException(
                "Cannot issue certificate — score ({$enrollment->score}) is below pass mark ({$enrollment->course?->pass_score})."
            );
        }

        // Idempotent — return existing cert if already issued
        $existing = LmsCertificate::where('enrollment_id', $enrollment->id)->first();
        if ($existing) {
            return $existing;
        }

        // Find linked certification program (if any)
        $certification = LmsCertification::where('course_id', $enrollment->course_id)
            ->where('is_active', true)
            ->first();

        // Gate 2: understanding score requirement
        if ($certification?->requires_understanding_score) {
            $understandingScore = LmsUnderstandingScore::where('enrollment_id', $enrollment->id)
                ->whereNotNull('total_score')
                ->orderByDesc('id')
                ->value('total_score');

            if (is_null($understandingScore)) {
                throw new \RuntimeException(
                    "Cannot issue certificate — understanding score has not been calculated yet."
                );
            }
            if ($understandingScore < $certification->min_understanding_score) {
                throw new \RuntimeException(
                    "Cannot issue certificate — understanding score ({$understandingScore}) is below minimum ({$certification->min_understanding_score})."
                );
            }
        }

        return DB::transaction(function () use ($enrollment, $certification, $actorId) {
            $issuedAt  = now();
            $expiresAt = $certification?->expiryDate($issuedAt);
            $certNumber = 'CERT-' . $issuedAt->year . '-' . str_pad((string) $enrollment->id, 6, '0', STR_PAD_LEFT);

            $certificate = LmsCertificate::create([
                'uuid'              => Str::uuid()->toString(),
                'enrollment_id'     => $enrollment->id,
                'template_id'       => $certification?->template_id,
                'certification_id'  => $certification?->id,
                'certificate_number'=> $certNumber,
                'nqf_level'         => $enrollment->course?->nqf_level,
                'saqa_qual_id'      => $enrollment->course?->saqa_id,
                'issued_at'         => $issuedAt,
                'expires_at'        => $expiresAt,
                'issued_by'         => $actorId,
            ]);

            // Update or create employee_certification record
            if ($certification) {
                $empCert = EmployeeCertification::updateOrCreate(
                    ['user_id' => $enrollment->user_id, 'certification_id' => $certification->id],
                    [
                        'certificate_id' => $certificate->id,
                        'status'         => 'active',
                        'issued_at'      => $issuedAt,
                        'expires_at'     => $expiresAt,
                    ]
                );

                $this->logEvent($empCert, 'issued', null, null, "Certificate issued: {$certNumber}", $actorId);
            }

            return $certificate;
        });
    }

    // ── Expiry tracking ───────────────────────────────────────────────────────

    /**
     * Scan all active employee_certifications, mark those that have passed their
     * expiry date as 'expired'. Returns the count of newly expired records.
     */
    public function trackCertificateExpiry(): int
    {
        $count = 0;

        EmployeeCertification::where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->with(['user', 'certification'])
            ->lazyById()
            ->each(function (EmployeeCertification $ec) use (&$count) {
                $ec->update(['status' => 'expired']);
                $this->logEvent($ec, 'expired', null, null, 'Certificate expiry threshold crossed.');
                $count++;
            });

        return $count;
    }

    /**
     * Send expiry reminders according to each certification's reminder_days schedule.
     * Deduplicates — will not re-send the same day.
     * Returns the count of notifications dispatched.
     */
    public function sendCertificateExpiryReminder(): int
    {
        $sent = 0;

        $activeCerts = EmployeeCertification::where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->with(['user', 'certification.course'])
            ->get();

        foreach ($activeCerts as $ec) {
            $daysLeft  = $ec->daysUntilExpiry();
            $schedule  = $ec->certification?->reminder_days ?? [90, 60, 30, 14, 7, 1];

            if (!in_array($daysLeft, $schedule)) {
                continue;
            }

            // Deduplicate: only one reminder_sent log per day per cert
            $alreadySent = CertificationExpiryLog::where('employee_certification_id', $ec->id)
                ->where('event_type', 'reminder_sent')
                ->where('days_until_expiry', $daysLeft)
                ->whereDate('created_at', today())
                ->exists();

            if ($alreadySent) {
                continue;
            }

            try {
                $ec->user->notify(new CertificateExpiryReminderNotification($ec, $daysLeft));
                $ec->increment('reminders_sent');
                $ec->update(['last_reminder_at' => now()]);
                $this->logEvent($ec, 'reminder_sent', $daysLeft, null, "Expiry reminder sent ({$daysLeft} days remaining).");
                $sent++;
            } catch (\Throwable $e) {
                Log::error("Certificate expiry reminder failed for EmployeeCertification#{$ec->id}: " . $e->getMessage());
            }
        }

        return $sent;
    }

    /**
     * Assign refresher training for certifications that are expiring within
     * the certification's refresher_before_days window, or already expired.
     * Returns count of refresher assignments created.
     */
    public function assignRefresherTraining(?EmployeeCertification $specific = null, int $actorId = 0): int
    {
        $query = EmployeeCertification::with(['user', 'certification.refresherCourse'])
            ->whereIn('status', ['active', 'expired', 'refresher_required'])
            ->whereNull('refresher_assigned_at');

        if ($specific) {
            $query->where('id', $specific->id);
        } else {
            // Only candidates within the refresher window or already expired
            $query->whereHas('certification', fn ($q) => $q->whereNotNull('refresher_course_id'))
                  ->where(function ($q) {
                      $q->where('status', 'expired')
                        ->orWhere(function ($q2) {
                            $q2->where('status', 'active')
                               ->whereNotNull('expires_at')
                               ->whereRaw("expires_at <= NOW() + (SELECT refresher_before_days || ' days' FROM lms_certifications WHERE id = employee_certifications.certification_id)::interval");
                        });
                  });
        }

        $count = 0;
        $query->lazyById()->each(function (EmployeeCertification $ec) use (&$count, $actorId) {
            $refresherCourse = $ec->certification?->refresherCourse;
            if (!$refresherCourse) return;

            // Enroll user in refresher course
            $refresherEnrollment = LmsEnrollment::firstOrCreate(
                ['user_id' => $ec->user_id, 'course_id' => $refresherCourse->id],
                [
                    'status'       => 'enrolled',
                    'progress_pct' => 0,
                    'enrolled_at'  => now(),
                ]
            );

            $ec->update([
                'status'                 => 'refresher_required',
                'refresher_assigned_at'  => now(),
                'refresher_enrollment_id'=> $refresherEnrollment->id,
            ]);

            $this->logEvent($ec, 'refresher_assigned', $ec->daysUntilExpiry(),
                null, "Refresher enrolled in course: {$refresherCourse->title}", $actorId);
            $count++;
        });

        return $count;
    }

    /**
     * Block an action if the user has an expired certificate that lists the
     * action key in its actions_blocked_when_expired list.
     * Returns true if the action should be blocked.
     */
    public function blockExpiredCertificateAction(int $userId, string $actionKey): bool
    {
        $hasExpiredBlock = EmployeeCertification::where('user_id', $userId)
            ->whereIn('status', ['expired', 'revoked'])
            ->whereHas('certification', function ($q) use ($actionKey) {
                $q->whereRaw("actions_blocked_when_expired::jsonb @> ?", [json_encode([$actionKey])]);
            })
            ->with(['certification'])
            ->first();

        if ($hasExpiredBlock) {
            $this->logEvent($hasExpiredBlock, 'action_blocked', null, $actionKey,
                "Action '{$actionKey}' blocked due to expired certification.");
            return true;
        }

        return false;
    }

    /**
     * Revoke a certificate explicitly (e.g., discovered fraud, role change).
     */
    public function revokeCertificate(EmployeeCertification $ec, string $reason, int $actorId): void
    {
        $ec->update(['status' => 'revoked', 'notes' => $reason]);
        $this->logEvent($ec, 'revoked', null, null, "Revoked: {$reason}", $actorId);
    }

    // ── Certification compliance dashboard ────────────────────────────────────

    /**
     * Build the certification compliance dashboard data.
     *
     * Returns:
     *  - kpi: total active, total expired, total expiring soon, overall compliance rate
     *  - by_certification: per-program breakdown with counts
     *  - expiring_soon: list of employees whose certs expire within N days
     *  - recent_issued: last 20 issued certificates
     *  - recent_expired: last 20 expired
     */
    public function getCertificationDashboard(array $filters = []): array
    {
        $daysWindow   = (int) ($filters['days_window'] ?? 30);
        $certId       = $filters['certification_id'] ?? null;

        $base = EmployeeCertification::query()
            ->when($certId, fn ($q) => $q->where('certification_id', $certId));

        $totalActive      = (clone $base)->where('status', 'active')->count();
        $totalExpired     = (clone $base)->where('status', 'expired')->count();
        $totalExpiringSoon = (clone $base)->expiringSoon($daysWindow)->count();
        $totalAll         = (clone $base)->whereIn('status', ['active', 'expired'])->count();
        $complianceRate   = $totalAll > 0 ? round(($totalActive / $totalAll) * 100, 1) : 0;

        $byCertification = LmsCertification::active()
            ->with(['course', 'employeeCertifications' => fn ($q) => $q->whereIn('status', ['active', 'expired', 'refresher_required'])])
            ->get()
            ->map(function (LmsCertification $c) use ($daysWindow) {
                $all     = $c->employeeCertifications;
                $active  = $all->where('status', 'active')->count();
                $expired = $all->whereIn('status', ['expired', 'revoked'])->count();
                $expiring = $all->filter(fn ($e) => $e->status === 'active'
                    && $e->expires_at
                    && $e->expires_at->diffInDays(now(), false) <= 0
                    && $e->expires_at->diffInDays(now()->addDays($daysWindow), false) >= 0
                )->count();
                $total   = $all->count();
                return [
                    'id'           => $c->id,
                    'name'         => $c->name,
                    'course_title' => $c->course?->title,
                    'valid_days'   => $c->valid_days,
                    'active'       => $active,
                    'expired'      => $expired,
                    'expiring'     => $expiring,
                    'total'        => $total,
                    'rate'         => $total > 0 ? round(($active / $total) * 100, 1) : 0,
                ];
            });

        $expiringSoon = EmployeeCertification::with(['user.person', 'certification.course'])
            ->expiringSoon($daysWindow)
            ->orderBy('expires_at')
            ->limit(50)
            ->get()
            ->map(fn ($ec) => [
                'id'                => $ec->id,
                'user_id'           => $ec->user_id,
                'employee_name'     => $ec->user?->person
                    ? "{$ec->user->person->first_name} {$ec->user->person->surname}"
                    : ($ec->user?->email ?? '—'),
                'certification'     => $ec->certification?->name,
                'course_title'      => $ec->certification?->course?->title,
                'expires_at'        => $ec->expires_at?->toDateString(),
                'days_until_expiry' => $ec->daysUntilExpiry(),
                'has_refresher'     => !is_null($ec->certification?->refresher_course_id),
            ]);

        $recentIssued = CertificationExpiryLog::with(['user.person', 'certification.course'])
            ->where('event_type', 'issued')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($l) => [
                'employee_name'  => $l->user?->person
                    ? "{$l->user->person->first_name} {$l->user->person->surname}"
                    : ($l->user?->email ?? '—'),
                'certification'  => $l->certification?->name,
                'course_title'   => $l->certification?->course?->title,
                'notes'          => $l->notes,
                'created_at'     => $l->created_at?->toDateTimeString(),
            ]);

        $recentExpired = CertificationExpiryLog::with(['user.person', 'certification.course'])
            ->where('event_type', 'expired')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($l) => [
                'employee_name'  => $l->user?->person
                    ? "{$l->user->person->first_name} {$l->user->person->surname}"
                    : ($l->user?->email ?? '—'),
                'certification'  => $l->certification?->name,
                'course_title'   => $l->certification?->course?->title,
                'created_at'     => $l->created_at?->toDateTimeString(),
            ]);

        return [
            'kpi' => [
                'totalActive'       => $totalActive,
                'totalExpired'      => $totalExpired,
                'totalExpiringSoon' => $totalExpiringSoon,
                'complianceRate'    => $complianceRate,
            ],
            'byCertification' => $byCertification,
            'expiringSoon'    => $expiringSoon,
            'recentIssued'    => $recentIssued,
            'recentExpired'   => $recentExpired,
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function logEvent(
        EmployeeCertification $ec,
        string $eventType,
        ?int $daysUntilExpiry,
        ?string $actionKey,
        string $notes,
        int $createdBy = 0,
    ): void {
        CertificationExpiryLog::create([
            'employee_certification_id' => $ec->id,
            'user_id'                   => $ec->user_id,
            'certification_id'          => $ec->certification_id,
            'event_type'                => $eventType,
            'days_until_expiry'         => $daysUntilExpiry,
            'action_key'                => $actionKey,
            'notes'                     => $notes,
            'created_by'                => $createdBy ?: null,
        ]);
    }
}
