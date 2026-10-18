<?php

namespace App\Http\Middleware;

use App\Core\User\Models\User;
use App\Models\AppSetting;
use App\Models\OfflinePolicy;
use App\Models\UserThemeSetting;
use App\Http\Middleware\ViewAcrossBusinesses;
use App\Modules\Finance\Accounting\Models\FinEntity;
use App\Modules\Finance\Accounting\Services\CurrentEntity;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\AiQuota;
use App\Modules\Tenancy\Services\CurrentCompany;
use App\Modules\Tenancy\Services\Edition;
use App\Modules\Tenancy\Services\SmsQuota;
use App\Modules\Tenancy\Services\StorageQuota;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app'; // points to resources/views/app.blade.php

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();

        return array_merge(parent::share($request), [
            /*
             * Which edition this installation is.
             *
             * Shared globally because the difference is user-visible, not merely architectural: on
             * a self-hosted install there is one company and no tenant switcher, so any screen that
             * tells somebody to "view as company" is sending them to a menu that does not exist.
             */
            'edition' => Edition::current(),

            /*
             * The companies a platform admin may switch into, so the "no active company" notices
             * can offer the switch instead of describing it.
             *
             * Those notices used to say «use "View as company" from the user menu». It is not in
             * the user menu — it is a button on Platform → Companies — so the one instruction shown
             * to somebody who is already stuck sent them somewhere that does not exist. Handing the
             * page the list lets it just do the thing.
             *
             * Only built for a platform admin who has not resolved a tenant; everybody else gets
             * null and no query runs.
             */
            'switchableCompanies' => $this->switchableCompanies($user),

            'auth' => [
                'user' => $user ? array_merge(
                    $user->only('id', 'name', 'email'),
                    [
                        'avatar' => $user->avatar_url,
                        'locale' => $user->locale ?? 'en',
                        'is_platform_admin' => (bool) ($user->is_platform_admin ?? false),
                        'company_id' => $user->company_id ?? null,
                        // The original account admin (the user who created the company).
                        // Only they see/manage subscription + usage billing.
                        'is_company_owner' => $this->isCompanyOwner($user),
                    ]
                ) : null,
                'impersonating' => $request->session()->get('impersonate_company_id'),
                // Days left on a trial, for the countdown bar at the bottom of every page (null when not on a trial).
                'company_trial' => fn () => $this->companyTrial($user),
                'roles' => $user ? $user->getRoleNames()->values()->toArray() : [],
                // Pass roles/permissions for CASL on the frontend
                'abilities' => $user
                    ? $this->getUserAbilities($user)
                    : [],
            ],
            // White-label branding: the company's own in-system logo/name when the feature
            // is active (else null → the app falls back to the default). Shared at the TOP
            // LEVEL — every consumer reads page.props.branding, so nesting it under `auth`
            // silently resolved to undefined and the UI always used the default branding.
            'branding' => $this->resolveBranding($user),

            /*
             * Which of the tenant's businesses Accounting is currently showing.
             *
             * Shared rather than passed page by page: the choice has to be visible and changeable
             * on every screen in the module — a customer list that does not say which business's
             * customers it is listing is a list somebody will read as the wrong one. Every
             * Accounting controller would otherwise have to remember to pass it, and the one that
             * forgot would be the one that misled.
             *
             * Built only for Accounting routes, so no other page pays for the two queries.
             */
            'financeEntity' => $this->financeEntity($request),
            'flash' => [
                'message' => fn () => $request->session()->get('message'),
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                // Which rows a bulk task import could not use. Flashed rather than toasted: these
                // are lines somebody has to correct in a spreadsheet, and a message that vanishes
                // costs them the whole upload again.
                'taskImport' => fn () => $request->session()->get('taskImport'),
                'info' => fn () => $request->session()->get('info'),
                // The network that just connected with more than one Facebook Page behind it: the
                // first was picked so something could post, and the screen opens the chooser so
                // nobody discovers weeks later that announcements went to the wrong page.
                'choose_page' => fn () => $request->session()->get('choose_page'),
                // The one-time payslip key (PDF open-password) shown right after generation,
                // and the bureau self-service payslip link — both flashed once and displayed to share.
                'payslip_key' => fn () => $request->session()->get('payslip_key'),
                'portal_link' => fn () => $request->session()->get('portal_link'),
                // The project-portal invitation just issued, flashed once so the link can be
                // copied and passed on by hand when the email does not arrive.
                'inviteLink' => fn () => $request->session()->get('inviteLink'),
                'reaction_id' => $request->session()->get('reaction_id'),
                'newPosition' => fn () => $request->session()->get('newPosition'),
                'importPreview' => fn () => $request->session()->get('importPreview'),
                'importIssues' => fn () => $request->session()->get('importIssues'),
                'coaImport' => fn () => $request->session()->get('coaImport'),
                'apiToken' => fn () => $request->session()->get('apiToken'),
                // A client just created from the project form, so the picker can select it without
                // re-querying and without the user having to find it again in a list.
                'pmClient' => fn () => $request->session()->get('client'),
                // And a calendar created from the same form, for the same reason: a project cannot
                // be scheduled without one, and sending somebody to Setup to make it loses the form.
                'pmCalendar' => fn () => $request->session()->get('calendar'),
                'credentials' => fn () => $request->session()->get('credentials'),
            ],
            'otpResult' => session('otpResult'),
            'notifications' => fn () => $user
                ? $user->unreadNotifications()
                    ->latest()
                    ->limit(15)
                    ->get()
                    ->map(fn ($n) => [
                        'id' => $n->id,
                        'type' => data_get($n->data, 'type', 'info'),
                        'title' => self::notificationTitle($n->data),
                        'body' => self::notificationBody($n->data),
                        'url' => data_get($n->data, 'url'),
                        'created_at' => $n->created_at->diffForHumans(),
                    ])
                    ->values()
                : [],
            'unread_count' => fn () => $user
                ? $user->unreadNotifications()->count()
                : 0,
            'theme_settings' => fn () => $this->resolveThemeSettings($user),
            'offline_policy' => fn () => OfflinePolicy::current()->toSharedArray(),
            'quota' => fn () => $this->resolveQuota($user),
        ]);
    }

    /** AI + SMS monthly + storage usage snapshot for the user's company (null if none). */
    /**
     * Is this user the ORIGINAL account admin (the person who created the company)?
     * Platform admins always qualify (incl. when impersonating). Only owners may
     * manage the subscription and usage/billing.
     */
    private function isCompanyOwner(?User $user): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->is_platform_admin ?? false) {
            return true;
        }
        if (! ($user->company_id ?? null)) {
            return false;
        }

        static $ownerByCompany = [];
        $cid = (int) $user->company_id;
        if (! array_key_exists($cid, $ownerByCompany)) {
            $ownerByCompany[$cid] = (int) DB::table('companies')->where('id', $cid)->value('created_by');
        }

        return $ownerByCompany[$cid] === (int) $user->id;
    }

    /**
     * Companies a stuck platform admin can switch into.
     *
     * Returns null — not an empty list — whenever a switcher would be meaningless, so the notice
     * can tell "nothing to offer" apart from "not applicable to you". Suspended companies are left
     * out because ResolveCompany refuses them anyway, and offering a switch that then 403s is worse
     * than not offering it.
     *
     * @return list<array{id:int,name:string}>|null
     */
    /**
     * The business Accounting is working in, and the others available to switch to.
     *
     * Null everywhere outside the module, which is also what the picker checks before rendering
     * itself: an entity selector on a payroll screen would be a control that changes nothing.
     *
     * @return array{current: ?array, acrossAll: bool, options: array}|null
     */
    private function financeEntity(Request $request): ?array
    {
        $routeName = $request->route()?->getName();

        if ($routeName === null || ! str_starts_with($routeName, 'finance.')) {
            return null;
        }

        /*
         * The same question CurrentEntity asks, answered in the same place — not a second copy of
         * it here. The copy that used to live here stopped at `users.company_id` and so went blind
         * on a self-hosted install, where a platform administrator has neither a CurrentCompany
         * nor a company on their user: every finance screen was scoped to a business the picker
         * then refused to name, because it had decided there was no tenant to name one for.
         */
        $companyId = app(CurrentEntity::class)->companyId();

        if ($companyId === null) {
            return null;
        }

        $entities = FinEntity::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->orderByDesc('is_default')->orderBy('name')
            ->get(['id', 'name', 'slug', 'is_default']);

        // One business is the normal case and needs no switcher; the picker hides itself.
        if ($entities->isEmpty()) {
            return null;
        }

        /*
         * The group view is a property of this URL, not of the session, so it is read back off the
         * request rather than from CurrentEntity — which the middleware has already set to null,
         * making "all businesses" and "no business resolved" indistinguishable from here.
         */
        $acrossAll = $request->isMethod('GET') && $request->boolean(ViewAcrossBusinesses::FLAG);

        $currentId = app(CurrentEntity::class)->id();
        $current = $entities->firstWhere('id', $currentId) ?? $entities->first();

        return [
            'current'   => $acrossAll ? null : ['id' => $current->id, 'name' => $current->name],
            'acrossAll' => $acrossAll,
            'options'   => $entities->map(fn (FinEntity $e) => [
                'id'         => $e->id,
                'name'       => $e->name,
                'is_default' => (bool) $e->is_default,
                'is_current' => ! $acrossAll && $e->id === $current->id,
            ])->values()->all(),
        ];
    }

    private function switchableCompanies(?User $user): ?array
    {
        $isAdmin = ($user?->is_platform_admin ?? false) || ($user?->is_master_admin ?? false);

        if (! $isAdmin || app(CurrentCompany::class)->isSet()) {
            return null;
        }

        return DB::table('companies')
            ->whereNull('suspended_at')
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name'])
            ->map(fn ($c) => ['id' => (int) $c->id, 'name' => (string) $c->name])
            ->all();
    }

    /**
     * White-label branding shared to the frontend: whether the feature is active for
     * this user's company, and the company's in-system logo URL when it is. self_hosted
     * is always white-label. Falls back to null so the UI uses the default logo.
     *
     * @return array{white_label: bool, system_logo: ?string, system_name: ?string}
     */
    private function resolveBranding(?User $user): array
    {
        $off = ['white_label' => false, 'system_logo' => null, 'system_name' => null];
        if (! $user) {
            return $off;
        }

        $cid = $user->company_id ?? null;
        $whiteLabel = Edition::isSelfHosted()
            || ($cid && (bool) DB::table('companies')->where('id', $cid)->value('white_label'));

        if (! $whiteLabel) {
            return $off;
        }

        $identity = $cid
            ? DB::table('company_identities')
                ->where('company_id', $cid)
                ->first(['company_logo', 'company_name', 'full_company_name'])
            : null;

        $raw = $identity->company_logo ?? null;
        $name = trim((string) ($identity->company_name ?? '')) ?: trim((string) ($identity->full_company_name ?? ''));

        return [
            'white_label' => true,
            // Served from the DB via the branding route; the version tag busts the
            // browser cache whenever the logo changes.
            'system_logo' => $raw ? '/settings/branding/logo?v='.substr(md5($raw), 0, 10) : null,
            // The company's own name replaces the product name in the header.
            'system_name' => $name !== '' ? $name : null,
        ];
    }

    private function resolveQuota(?User $user): ?array
    {
        if (! $user || ! ($user->company_id ?? null)) {
            return null;
        }
        try {
            $company = Company::find($user->company_id);
            if (! $company) {
                return null;
            }

            return [
                'ai' => app(AiQuota::class)->snapshot($company),
                'sms' => app(SmsQuota::class)->snapshot($company),
                'storage' => app(StorageQuota::class)->snapshot($company),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private static function notificationTitle(array $data): string
    {
        return match (data_get($data, 'type')) {
            // Project Management. Without these every notification this module raises — an
            // approval waiting, a contract needing a signature — rendered as the word
            // "Notification", because the fallback reads a `title` key these payloads do not carry.
            'pm_approval_requested'          => 'Approval Requested',
            'pm_approval_decided'            => 'Approval Decided',
            'pm_contract_awaiting_signature' => 'Contract Awaiting Your Signature',
            'pm_contract_reopened'           => 'Contract Reopened',
            'pm_announcement'                => 'Project Announcement',
            'pm_project_alert'               => 'Project Alert',

            // Leave
            'leave_submitted' => 'Leave Request Submitted',
            'leave_submitted_employee' => 'Leave Request Received',
            'leave_approved' => 'Leave Approved',
            'leave_rejected' => 'Leave Rejected',
            'leave_escalated' => 'Leave Escalated',
            'leave_medical_required' => 'Medical Certificate Required',
            'leave_balance_low' => 'Leave Balance Low',
            'leave_cycle_ending' => 'Leave Cycle Ending Soon',
            'leave_anomaly_detected' => 'Anomaly Detected',
            'leave_report_ready' => 'Leave Report Ready',
            'policy_override_requested' => 'Policy Override Request',
            // Policies
            'policy_violation' => 'Policy Violation',
            'policy_violation_escalation' => 'Policy Violation — Action Required',
            'policy_violation_critical' => 'Critical Policy Violation',
            'policy_violation_alert' => 'Policy Violation Alert',
            'attestation_reminder' => 'Attestation Due',
            'attestation_escalation' => 'Overdue Attestation Escalated',
            'quiz_failed' => 'Policy Quiz Failed',
            // Attendance
            'attendance_late_arrival' => 'Late Arrival',
            'attendance_absent' => 'Absent — No Clock-in',
            'attendance_overtime' => 'Overtime Detected',
            'attendance_early_departure' => 'Early Departure',
            'attendance_missing_clockout' => 'Missing Clock-out',
            'attendance_weekly_summary' => 'Weekly Attendance Summary',
            // Profile
            'profile_change_submitted' => 'Profile Update Submitted',
            'profile_change_pending_approval' => 'Profile Update Pending Approval',
            'profile_change_approved' => 'Profile Update Approved',
            'profile_change_rejected' => 'Profile Update Declined',
            // Assets
            'asset_assigned' => 'Asset Allocated to You',
            // Account
            'user_account_created' => 'Welcome to the System',
            // Fleet — Bookings
            'fleet_booking_approval' => 'Booking Awaiting Your Approval',
            'fleet_booking_approved' => 'Fleet Booking Approved',
            'fleet_booking_rejected' => 'Fleet Booking Rejected',
            'fleet_booking_cancelled' => 'Fleet Booking Cancelled',
            'fleet_booking_returned' => 'Vehicle Returned',
            // Fleet — Vehicles & Maintenance
            'fleet_high_risk_vehicle' => 'High-Risk Vehicle Alert',
            'fleet_maintenance_due' => 'Maintenance Due',
            'fleet_document_expiry' => 'Document Expiring Soon',
            // Fleet — AI & Anomalies
            'fleet_anomaly_detected' => 'Fuel Anomaly Detected',
            // Fleet — Incidents
            'fleet_incident_reported' => 'Incident Reported',
            // Content & Interactions
            'comment_added_content' => 'New Comment on Your Post',
            'comment_added_spotlight' => 'New Comment on Your Spotlight',
            'like_added_content' => 'Someone Liked Your Post',
            'like_added_spotlight' => 'Someone Liked Your Spotlight',
            'content_created' => 'New Post Published',
            'spotlight_created' => 'New Spotlight Published',
            default => data_get($data, 'title', 'Notification'),
            // Performance Appraisal — KPIs
            'pa_kpi_assigned' => 'New KPI Assigned',
            // Performance Appraisal — Check-Ins
            'pa_checkin_scheduled' => 'Check-In Scheduled',
            'pa_checkin_reminder_day_before' => 'Check-In Tomorrow',
            'pa_checkin_reminder_day_of' => 'Check-In Today',
            // Performance Appraisal — Coaching
            'pa_coaching_scheduled' => 'Coaching Session Scheduled',
            'pa_coaching_reminder_day_before' => 'Coaching Session Tomorrow',
            'pa_coaching_reminder_day_of' => 'Coaching Session Today',
            // Performance Appraisal — Feedback
            'pa_feedback_received' => 'New Feedback Received',
            // Performance Appraisal — 360 Reviews
            'pa_360_review_created' => '360° Review Created',
            'pa_360_added_as_rater' => 'You Are Invited to Rate',
            // Performance Appraisal — Probation
            'pa_probation_started' => 'Probation Period Started',
            'pa_probation_checkin_scheduled' => 'Probation Check-In Scheduled',
            'pa_probation_checkin_reminder_day_before' => 'Probation Check-In Tomorrow',
            'pa_probation_checkin_reminder_day_of' => 'Probation Check-In Today',
            'pa_probation_feedback' => 'Probation Feedback Received',
            // Claims
            'claim_submitted' => 'Claim Awaiting Your Approval',
            'claim_approved' => 'Claim Approved',
            'claim_rejected' => 'Claim Rejected',
            'claim_paid' => 'Claim Payment Processed',
            'claim_under_review' => 'Claim Under Review',
            'claim_high_risk' => 'High-Risk Claim Flagged',
            'claim_payment_ready' => 'Claim Ready for Payment',
            'default' => data_get($data, 'title', 'Notification'),
        };
    }

    private static function notificationBody(array $data): string
    {
        return data_get($data, 'message')
            ?? data_get($data, 'request_number')
            ?? data_get($data, 'employee_name')
            ?? '';
    }

    private function resolveThemeSettings(?User $user): array
    {
        try {
            $global = AppSetting::get('global_theme', []) ?? [];
            $personal = $user
                ? (UserThemeSetting::where('user_id', $user->id)->first()?->settings ?? [])
                : [];

            return array_merge(
                is_array($global) ? $global : [],
                is_array($personal) ? $personal : [],
            );
        } catch (\Throwable) {
            return [];
        }
    }

    private function getUserAbilities(User $user): array
    {
        // Using spatie/laravel-permission (which you have from permission.php config)
        return $user->getAllPermissions()->map(fn ($p) => [
            'action' => $p->name,
            'subject' => 'all',
        ])->toArray();
    }

    /**
     * Trial countdown for a signed-in company user: null for platform administrators, for a company
     * that is not on a trial, and for one with no end date. days_left is 0 on the last day.
     *
     * @return array{days_left:int, ends_at:string}|null
     */
    private function companyTrial($user): ?array
    {
        if (! $user || ($user->is_platform_admin ?? false) || ($user->is_master_admin ?? false) || ! $user->company_id) {
            return null;
        }
        try {
            $company = \App\Modules\Tenancy\Models\Company::query()->find($user->company_id);
            if (! $company || $company->status !== 'trial' || ! $company->trial_ends_at) {
                return null;
            }

            return [
                'days_left' => max(0, (int) ceil(now()->floatDiffInDays($company->trial_ends_at, false))),
                'ends_at' => $company->trial_ends_at->format('Y-m-d'),
            ];
        } catch (\Throwable) {
            return null; // a countdown is never worth breaking a page over
        }
    }
}
