<?php

namespace App\Console\Commands\LMS;

use App\Modules\HR\LMS\Models\LmsCourse;
use App\Modules\HR\LMS\Models\Qcto\LmsRegisteredPerson;
use App\Modules\HR\LMS\Models\Qcto\LmsSdf;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use App\Core\User\Models\User;

class CheckQctoAccreditationExpiry extends Command
{
    use RunsPerTenant;

    protected $signature   = 'lms:check-accreditation-expiry';
    protected $description = 'Alert LMS admins when SETA accreditations are expiring within 90/60/30 days';

    private const WINDOWS = [90, 60, 30];

    public function handle(): int
    {
        $this->eachTenant(app(TenantContext::class), function () {
        /*
         * Roles live in Spatie's model_has_roles, not in a `users.role` column — there is no such
         * column, so this query threw on every run and took the whole per-company sweep with it.
         *
         * Matched case-insensitively and only against roles that exist: role() throws
         * RoleDoesNotExist on a miss, and 'hr' is stored as 'HR' while 'lms_admin' is not a role on
         * any site. Finding nobody to alert is a quiet outcome; an exception is not.
         */
        $wanted = ['super admin', 'administrator', 'hr', 'lms_admin'];
        $roles = Role::query()
            ->whereIn(DB::raw('lower(name)'), array_map('mb_strtolower', $wanted))
            ->pluck('name')
            ->all();

        $admins = $roles === [] ? collect() : User::role($roles)->get();

        if ($admins->isEmpty()) {
            $this->warn('No LMS admin users found — skipping notifications.');
            return;
        }

        $alerts = collect();

        // ── Course SETA accreditations ─────────────────────────────────────────
        LmsCourse::whereNotNull('seta_accreditation_expires_at')
            ->get(['id', 'title', 'seta_accreditation_expires_at', 'seta_accreditation_number'])
            ->each(function ($course) use (&$alerts) {
                $days = (int) now()->diffInDays($course->seta_accreditation_expires_at, false);
                if ($days < 0 || !in_array($days, self::WINDOWS)) return;
                $alerts->push([
                    'type'       => 'Course Accreditation',
                    'entity'     => $course->title,
                    'reference'  => $course->seta_accreditation_number,
                    'expires_at' => $course->seta_accreditation_expires_at->toDateString(),
                    'days_left'  => $days,
                ]);
            });

        // ── Registered persons (assessors/moderators) ─────────────────────────
        LmsRegisteredPerson::whereNotNull('expires_at')
            ->get(['id', 'first_name', 'last_name', 'registration_number', 'person_type', 'expires_at'])
            ->each(function ($person) use (&$alerts) {
                $days = (int) now()->diffInDays($person->expires_at, false);
                if ($days < 0 || !in_array($days, self::WINDOWS)) return;
                $alerts->push([
                    'type'       => ucfirst($person->person_type) . ' Registration',
                    'entity'     => "{$person->first_name} {$person->last_name}",
                    'reference'  => $person->registration_number,
                    'expires_at' => $person->expires_at->toDateString(),
                    'days_left'  => $days,
                ]);
            });

        // ── SDF registrations ─────────────────────────────────────────────────
        LmsSdf::whereNotNull('expires_at')
            ->get(['id', 'registration_number', 'expires_at'])
            ->each(function ($sdf) use (&$alerts) {
                $days = (int) now()->diffInDays($sdf->expires_at, false);
                if ($days < 0 || !in_array($days, self::WINDOWS)) return;
                $alerts->push([
                    'type'       => 'SDF Registration',
                    'entity'     => "SDF #{$sdf->id}",
                    'reference'  => $sdf->registration_number,
                    'expires_at' => $sdf->expires_at->toDateString(),
                    'days_left'  => $days,
                ]);
            });

        if ($alerts->isEmpty()) {
            $this->info('No accreditations expiring in the 30/60/90-day windows today.');
            return;
        }

        $this->info("Found {$alerts->count()} expiring accreditation(s) — sending notifications to {$admins->count()} admin(s).");

        foreach ($admins as $admin) {
            try {
                $admin->notify(new \App\Modules\HR\LMS\Notifications\QctoAccreditationExpiryNotification($alerts->all()));
            } catch (\Throwable $e) {
                Log::error("QCTO accreditation expiry notification failed for user #{$admin->id}: " . $e->getMessage());
            }
        }

        // Log summary to activity log
        activity()
            ->withProperties(['alerts' => $alerts->toArray()])
            ->log("QCTO accreditation expiry check: {$alerts->count()} item(s) expiring within 30/60/90 days.");

        $this->table(
            ['Type', 'Entity', 'Reference', 'Expires', 'Days Left'],
            $alerts->map(fn($a) => [$a['type'], $a['entity'], $a['reference'], $a['expires_at'], $a['days_left']])->toArray()
        );
        });

        return self::SUCCESS;
    }
}
