<?php

namespace App\Console\Commands\LMS;

use App\Core\User\Models\User;
use App\Modules\HR\LMS\Models\Qcto\LmsRegisteredPerson;
use App\Modules\HR\LMS\Models\Qcto\LmsSdf;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Mail;

class SendRegistrationExpiryAlertsCommand extends Command
{
    use RunsPerTenant;

    protected $signature   = 'lms:send-registration-expiry-alerts';
    protected $description = 'Alert HR and Admin about assessors, moderators, and SDFs expiring within 6 months';

    public function handle(): int
    {
        $this->eachTenant(app(TenantContext::class), function () {
        $within6Months = now()->addMonths(6);

        $assessors = LmsRegisteredPerson::with('seta')
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', $within6Months)
            ->orderBy('expires_at')
            ->get();

        $sdfs = LmsSdf::with(['user.person', 'seta'])
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', $within6Months)
            ->orderBy('expires_at')
            ->get();

        // Also flag anyone already expired (status still active but date passed)
        $expiredAssessors = LmsRegisteredPerson::with('seta')
            ->where('status', 'active')
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->get();

        $expiredSdfs = LmsSdf::with(['user.person', 'seta'])
            ->where('status', 'active')
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->get();

        if ($assessors->isEmpty() && $sdfs->isEmpty() && $expiredAssessors->isEmpty() && $expiredSdfs->isEmpty()) {
            $this->info('No expiring or expired registrations found.');
            return self::SUCCESS;
        }

        /*
         * Only roles that actually exist reach the scope. Spatie's role() throws RoleDoesNotExist
         * for a name it cannot resolve, so one stale entry — 'HR manager', which no site has —
         * killed this before it sent anything and took every company after the first with it. A
         * nightly alert must not die because a role was renamed; at worst it finds nobody to tell.
         */
        $wanted = ['HR', 'HR manager', 'administrator', 'super admin'];
        $roles  = Role::whereIn('name', $wanted)->pluck('name')->all();

        if ($roles === []) {
            $this->warn('None of the recipient roles exist — no alerts sent.');
            return self::SUCCESS;
        }

        $recipients = User::role($roles)
            ->get()
            ->pluck('email')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($recipients)) {
            $this->warn('No HR/Admin recipients found — no alerts sent.');
            return self::SUCCESS;
        }

        $body = $this->buildEmailBody($assessors, $sdfs, $expiredAssessors, $expiredSdfs);

        foreach ($recipients as $email) {
            Mail::raw($body, function ($msg) use ($email) {
                $msg->to($email)
                    ->subject('LMS — Registration Expiry Alert — ' . now()->format('d M Y'));
            });
        }

        $this->info('Expiry alerts sent to ' . count($recipients) . ' recipient(s).');
        });

        $this->reportTenantFailures('Registration expiry alerting');

        return $this->hadTenantFailures() ? self::FAILURE : self::SUCCESS;
    }

    private function buildEmailBody($assessors, $sdfs, $expiredAssessors, $expiredSdfs): string
    {
        $lines = [
            'LMS REGISTRATION EXPIRY ALERT',
            'Generated: ' . now()->format('d M Y H:i'),
            str_repeat('=', 60),
            '',
        ];

        if ($expiredAssessors->isNotEmpty() || $expiredSdfs->isNotEmpty()) {
            $lines[] = '⚠ ALREADY EXPIRED (status still Active — action required)';
            $lines[] = str_repeat('-', 60);

            foreach ($expiredAssessors as $p) {
                $daysAgo = abs((int) now()->diffInDays($p->expires_at, false));
                $lines[] = "[EXPIRED {$daysAgo}d ago] {$p->full_name} ({$p->person_type}) — "
                    . ($p->seta?->acronym ?? 'No SETA')
                    . " — Reg: " . ($p->registration_number ?? 'N/A')
                    . " — Expired: " . $p->expires_at?->toDateString();
            }

            foreach ($expiredSdfs as $s) {
                $name    = $s->user?->person ? $s->user->person->first_name . ' ' . $s->user->person->surname : "User #{$s->user_id}";
                $daysAgo = abs((int) now()->diffInDays($s->expires_at, false));
                $lines[] = "[EXPIRED {$daysAgo}d ago] {$name} (SDF) — "
                    . ($s->seta?->acronym ?? 'No SETA')
                    . " — Reg: " . ($s->registration_number ?? 'N/A')
                    . " — Expired: " . $s->expires_at?->toDateString();
            }

            $lines[] = '';
        }

        if ($assessors->isNotEmpty()) {
            $lines[] = 'ASSESSORS & MODERATORS EXPIRING WITHIN 6 MONTHS';
            $lines[] = str_repeat('-', 60);

            foreach ($assessors as $p) {
                $daysLeft = (int) now()->diffInDays($p->expires_at, false);
                $lines[]  = "{$p->full_name} ({$p->person_type}) — "
                    . ($p->seta?->acronym ?? 'No SETA')
                    . " — Reg: " . ($p->registration_number ?? 'N/A')
                    . " — Expires: " . $p->expires_at?->toDateString()
                    . " ({$daysLeft} days)";
            }

            $lines[] = '';
        }

        if ($sdfs->isNotEmpty()) {
            $lines[] = 'SDFs EXPIRING WITHIN 6 MONTHS';
            $lines[] = str_repeat('-', 60);

            foreach ($sdfs as $s) {
                $name     = $s->user?->person ? $s->user->person->first_name . ' ' . $s->user->person->surname : "User #{$s->user_id}";
                $daysLeft = (int) now()->diffInDays($s->expires_at, false);
                $lines[]  = "{$name} (SDF) — "
                    . ($s->seta?->acronym ?? 'No SETA')
                    . " — Reg: " . ($s->registration_number ?? 'N/A')
                    . " — Expires: " . $s->expires_at?->toDateString()
                    . " ({$daysLeft} days)";
            }

            $lines[] = '';
        }

        $lines[] = 'Please update registrations before they expire to avoid blocking PoE, moderation, and WSP/ATR submissions.';
        $lines[] = 'Manage registrations at: /lms/admin/registered-persons and /lms/admin/sdfs';

        return implode("\n", $lines);
    }
}
