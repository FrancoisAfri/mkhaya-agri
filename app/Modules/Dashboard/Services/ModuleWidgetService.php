<?php

namespace App\Modules\Dashboard\Services;

use App\Modules\Tenancy\Services\CompanyEntitlements;
use Illuminate\Support\Facades\DB;

/**
 * Builds the home-dashboard "module widgets" — one compact KPI card per ACTIVE
 * module. A widget is only included when the company's plan/entitlement allows the
 * module (so the dashboard adapts to whatever modules are active), and each
 * provider is wrapped so a failing query silently omits that widget rather than
 * breaking the page. All counts are RLS-scoped to the active company on web.
 */
class ModuleWidgetService
{
    public function __construct(private CompanyEntitlements $entitlements)
    {
    }

    /** @return array<int, array> */
    public function widgets(): array
    {
        $out = [];

        foreach ($this->providers() as $code => $provider) {
            if (! $this->entitlements->currentAllowsModule($code)) {
                continue;
            }

            try {
                $widget = $provider();
                if ($widget) {
                    $out[] = $widget;
                }
            } catch (\Throwable) {
                // A single module's metric failing must never break the dashboard.
            }
        }

        return $out;
    }

    /** @return array<string, callable> */
    private function providers(): array
    {
        return [
            'asset_management' => fn () => [
                'code' => 'asset_management', 'name' => 'Assets', 'icon' => 'fa-boxes-stacked', 'color' => 'indigo', 'link' => '/assets/dashboard',
                'metrics' => [
                    ['label' => 'Total assets', 'value' => DB::table('assets')->whereNull('deleted_at')->count()],
                    ['label' => 'Book value', 'value' => round((float) DB::table('assets')->whereNull('deleted_at')->sum('current_value')), 'format' => 'currency'],
                ],
            ],
            'leave_management' => fn () => [
                'code' => 'leave_management', 'name' => 'Leave', 'icon' => 'fa-umbrella-beach', 'color' => 'teal', 'link' => '/leave-management',
                'metrics' => [
                    ['label' => 'Pending requests', 'value' => DB::table('leave_requests')->where('status', 'like', 'pending%')->count()],
                    ['label' => 'On leave today', 'value' => DB::table('leave_requests')->where('status', 'approved')->whereDate('start_date', '<=', now())->whereDate('end_date', '>=', now())->count()],
                ],
            ],
            'user_management' => fn () => [
                'code' => 'user_management', 'name' => 'People', 'icon' => 'fa-users', 'color' => 'blue', 'link' => '/settings/manage-users',
                'metrics' => [
                    ['label' => 'Active employees', 'value' => DB::table('hr_people')->where('status', 1)->count()],
                ],
            ],
            'recruitment' => fn () => [
                'code' => 'recruitment', 'name' => 'Recruitment', 'icon' => 'fa-user-plus', 'color' => 'green', 'link' => '/recruitment',
                'metrics' => [
                    ['label' => 'Open vacancies', 'value' => DB::table('recruitment_vacancies')->when(\Illuminate\Support\Facades\Schema::hasColumn('recruitment_vacancies', 'status'), fn ($q) => $q->where('status', 'open'))->count()],
                ],
            ],
            'fleet_management' => fn () => [
                'code' => 'fleet_management', 'name' => 'Fleet', 'icon' => 'fa-car', 'color' => 'orange', 'link' => '/fleet',
                'metrics' => [
                    ['label' => 'Vehicles', 'value' => DB::table('fleet_vehicles')->count()],
                ],
            ],
            'crm' => fn () => [
                'code' => 'crm', 'name' => 'CRM', 'icon' => 'fa-handshake', 'color' => 'purple', 'link' => '/crm',
                'metrics' => [
                    ['label' => 'Deals', 'value' => DB::table('crm_deals')->count()],
                ],
            ],
            'payroll' => fn () => [
                'code' => 'payroll', 'name' => 'Payroll', 'icon' => 'fa-money-check-dollar', 'color' => 'emerald', 'link' => '/payroll/dashboard',
                'metrics' => [
                    ['label' => 'Payslips', 'value' => DB::table('payslips')->count()],
                ],
            ],
            'accounting' => fn () => [
                'code' => 'accounting', 'name' => 'Accounting', 'icon' => 'fa-file-invoice-dollar', 'color' => 'sky', 'link' => '/accounting',
                'metrics' => [
                    ['label' => 'Invoices', 'value' => DB::table('fin_invoices')->count()],
                ],
            ],
            'time_attendance' => fn () => [
                'code' => 'time_attendance', 'name' => 'Attendance', 'icon' => 'fa-clock', 'color' => 'amber', 'link' => '/attendance',
                'metrics' => [
                    ['label' => 'Clock-ins today', 'value' => DB::table('attendance_clockings')->whereDate('created_at', now()->toDateString())->count()],
                ],
            ],
            'learning_management' => fn () => [
                'code' => 'learning_management', 'name' => 'Learning', 'icon' => 'fa-graduation-cap', 'color' => 'violet', 'link' => '/lms',
                'metrics' => [
                    ['label' => 'Courses', 'value' => DB::table('lms_courses')->count()],
                    ['label' => 'Enrollments', 'value' => DB::table('lms_enrollments')->count()],
                ],
            ],
            'disciplinary' => fn () => [
                'code' => 'disciplinary', 'name' => 'Disciplinary', 'icon' => 'fa-gavel', 'color' => 'rose', 'link' => '/disciplinary',
                'metrics' => [
                    ['label' => 'Cases', 'value' => DB::table('disciplinary_cases')->count()],
                ],
            ],
            'claims' => fn () => [
                'code' => 'claims', 'name' => 'Claims', 'icon' => 'fa-file-medical', 'color' => 'pink', 'link' => '/claims',
                'metrics' => [
                    ['label' => 'Claims', 'value' => DB::table('claims')->count()],
                ],
            ],
            'staff_loans' => fn () => [
                'code' => 'staff_loans', 'name' => 'Staff Loans', 'icon' => 'fa-hand-holding-dollar', 'color' => 'lime', 'link' => '/staff-loans',
                'metrics' => [
                    ['label' => 'Loans', 'value' => DB::table('staff_loans')->count()],
                ],
            ],
            'petty_cash' => fn () => [
                'code' => 'petty_cash', 'name' => 'Petty Cash', 'icon' => 'fa-coins', 'color' => 'amber', 'link' => '/petty-cash',
                'metrics' => [
                    ['label' => 'Floats', 'value' => DB::table('petty_cash_floats')->count()],
                ],
            ],
            'boarding' => fn () => [
                'code' => 'boarding', 'name' => 'Onboarding', 'icon' => 'fa-user-check', 'color' => 'green', 'link' => '/onboarding',
                'metrics' => [
                    ['label' => 'In progress', 'value' => DB::table('onboarding_plans')->whereNull('completed_at')->count()],
                ],
            ],
            'offboarding' => fn () => [
                'code' => 'offboarding', 'name' => 'Offboarding', 'icon' => 'fa-door-open', 'color' => 'slate', 'link' => '/offboarding',
                'metrics' => [
                    ['label' => 'In progress', 'value' => DB::table('offboarding_plans')->whereNotIn('status', ['completed', 'cancelled'])->count()],
                ],
            ],
            'esign' => fn () => [
                'code' => 'esign', 'name' => 'E-Sign', 'icon' => 'fa-signature', 'color' => 'blue', 'link' => '/esign',
                'metrics' => [
                    ['label' => 'Pending', 'value' => DB::table('esign_envelopes')->whereNull('completed_at')->count()],
                ],
            ],
            'popia_compliance' => fn () => [
                'code' => 'popia_compliance', 'name' => 'POPIA', 'icon' => 'fa-shield-halved', 'color' => 'red', 'link' => '/compliance',
                'metrics' => [
                    ['label' => 'Breach incidents', 'value' => DB::table('data_breach_incidents')->count()],
                ],
            ],
        ];
    }
}
