<?php

namespace App\Modules\Admin\Setup\Controllers;

use App\Modules\Admin\Setup\Models\CompanyIdentity;
use App\Core\SMS\Models\SmsConfiguration;
use App\Core\User\Models\User;
use App\Core\User\Trait\FileStorage;
use App\Core\UserManagement\Models\PasswordSecurity;
use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class SetupController extends Controller
{
    use FileStorage;

    /**
     * The CURRENT TENANT's company identity, created on first use.
     *
     * company_identities is RLS-scoped per company, so CompanyIdentity::first() returns
     * NULL for any tenant that has never saved its identity — which silently broke both
     * loading (empty form) and saving (nothing to update). Platform-admin / console
     * context has no current company and falls back to the first visible row.
     */
    private function tenantIdentity(): CompanyIdentity
    {
        $cid = app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id();

        if ($cid) {
            return CompanyIdentity::firstOrCreate(['company_id' => $cid]);
        }

        return CompanyIdentity::first() ?? CompanyIdentity::create([]);
    }

    public function index()
    {
        $company  = $this->tenantIdentity();
        // HR managers / payroll officers live in company_user_pivots, whose company_id is a
        // FK to company_identities.id — an IDENTITY id, not the tenant's companies.id, despite
        // the column name. The table also has no RLS, so it must be filtered explicitly here or
        // every tenant sees every other tenant's managers.
        $managers = DB::table('company_user_pivots')
            ->where('company_id', $company->id)->where('type', 1)->pluck('user_id');
        $payrolls = DB::table('company_user_pivots')
            ->where('company_id', $company->id)->where('type', 2)->pluck('user_id');

        $allUsers = User::with('person')
            ->where('status', 1)
            ->get()
            ->map(fn($u) => [
                'id'      => $u->id,
                'name'    => $u->person?->full_name ?? $u->email,
                'email'   => $u->email,
                'avatar'  => $u->person?->profile_pic_url ?? null,
                'initial' => strtoupper(
                    substr($u->person?->first_name ?? $u->email, 0, 1) .
                    substr($u->person?->surname ?? '', 0, 1)
                ),
            ]);

        $smsConfig = SmsConfiguration::first();

        return Inertia::render('Core/Setup/Index', [
            'company'         => $company,
            // The tenant's own saved logo, independent of white-label: the settings page
            // must show what is stored even when the branding share is switched off,
            // otherwise a saved logo looks like it never uploaded.
            'logoUrl'         => $company->company_logo
                ? '/settings/branding/logo?v='.substr(md5($company->company_logo), 0, 10)
                : null,
            // White-label: only these companies may change their in-system logo.
            'whiteLabel'      => app(\App\Modules\Tenancy\Services\CompanyEntitlements::class)->hasWhiteLabel(),
            'managers'        => $managers->values(),
            'payrollOfficers' => $payrolls->values(),
            'users'           => $allUsers,
            'smsConfig'       => $smsConfig ? [
                'provider'           => $smsConfig->provider,
                'sms_username'       => $smsConfig->sms_username ?? '',
                'is_active'          => $smsConfig->is_active,
                'two_factor_enabled' => $smsConfig->two_factor_enabled,
                'has_password'       => !empty($smsConfig->getAttributes()['sms_password']),
            ] : null,
            'apiKeys' => [
                'google_maps_api_key' => AppSetting::get('google_maps_api_key', ''),
            ],
        ]);
    }

    public function updateCompany(Request $request)
    {
        $company = $this->tenantIdentity();

        $request->validate([
            'header_name_bold'       => 'required|string',
            'header_acronym_bold'    => 'required|string',
            'header_name_regular'    => 'required|string',
            'header_acronym_regular' => 'required|string',
            'company_logo'           => 'nullable|image|max:2048',
            'login_background_image' => 'nullable|image|max:5120',
            'managers'               => 'nullable|array',
            'managers.*'             => 'integer|exists:users,id',
            'payroll_officers'       => 'nullable|array',
            'payroll_officers.*'     => 'integer|exists:users,id',
        ]);

        $data = $request->except(['company_logo', 'login_background_image', 'managers', 'payroll_officers']);

        if ($request->hasFile('company_logo')) {
            // Store the logo IN the database (base64 JSON blob) so it survives deploys
            // and works across servers — the app's standard file-storage pattern. The
            // branding route decodes + serves it. Falls back to filesystem if encoding fails.
            try {
                $blob = $this->saveFileToDatabase($request->file('company_logo'));
                $data['company_logo'] = json_encode($blob);
            } catch (\Throwable) {
                $data['company_logo'] = $request->file('company_logo')->store('logos', 'public');
            }
        }
        if ($request->hasFile('login_background_image')) {
            $data['login_background_image'] = $request->file('login_background_image')->store('backgrounds', 'public');
        }

        $oldMonths = $company->password_expiring_month;
        $company->update($data);

        $newMonths = $company->fresh()->password_expiring_month;
        if ($newMonths && $newMonths > 0 && $newMonths !== $oldMonths) {
            PasswordSecurity::applyCompanyPolicy($newMonths);
        }

        // Stamp the pivot with the company IDENTITY id — company_user_pivots.company_id is a
        // FK to company_identities.id. Using the tenant's companies.id here violates that FK
        // for every tenant whose identity row id differs from its company id.
        $this->syncPivot((int) $company->id, $request->managers ?? [], 1);
        $this->syncPivot((int) $company->id, $request->payroll_officers ?? [], 2);

        return redirect()->back()->with('success', 'Settings saved.');
    }

    public function updateSmsConfig(Request $request)
    {
        $data = $request->validate([
            'provider'           => 'required|string|in:bulksms',
            'sms_username'       => 'required|string|max:255',
            'sms_password'       => 'nullable|string|max:255',
            'is_active'          => 'boolean',
            'two_factor_enabled' => 'boolean',
        ]);

        $config = SmsConfiguration::first() ?? new SmsConfiguration();
        $config->provider           = $data['provider'];
        $config->sms_username       = $data['sms_username'];
        $config->is_active          = $data['is_active'] ?? true;
        $config->two_factor_enabled = $data['two_factor_enabled'] ?? false;

        if (!empty($data['sms_password'])) {
            $config->sms_password = $data['sms_password'];
        }

        $config->save();
        return redirect()->back()->with('smsSuccess', 'SMS configuration saved successfully.');
    }

    public function updateApiKeys(Request $request)
    {
        $data = $request->validate(['google_maps_api_key' => 'nullable|string|max:200']);
        AppSetting::set('google_maps_api_key', $data['google_maps_api_key'] ?? '');
        return redirect()->back()->with('apiKeySuccess', 'API keys saved successfully.');
    }

    private function syncPivot(int $companyId, array $userIds, int $type): void
    {
        DB::table('company_user_pivots')
            ->where('company_id', $companyId)
            ->where('type', $type)
            ->delete();

        foreach (array_unique($userIds) as $userId) {
            DB::table('company_user_pivots')->insertOrIgnore([
                'company_id' => $companyId,
                'user_id'    => $userId,
                'type'       => $type,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
