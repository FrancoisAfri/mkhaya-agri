<?php

namespace App\Modules\Tenancy\Services;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\Reseller;
use App\Modules\Tenancy\Models\ResellerUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Turns a tenant company into a reseller. The company stops being a tenant.
 *
 * - Its profile (name, registration, VAT, contact, address, website, support email, logo) is copied
 *   onto a new company-type reseller.
 * - The users it keeps move to the reseller: they leave the company (company_id null), lose every
 *   tenant role and module grant, get the reseller role, and keep their login, password and 2FA.
 *   Each is an owner or staff in the portal. Users not kept are deactivated with the company.
 * - The subscription is cancelled: no further invoices; invoices already raised stay owed.
 * - The company is archived (blocked, not billed, not scheduled) with a purge date. Its business
 *   data stays untouched until then, when tenancy:purge-archived deletes it.
 *
 * All of it happens in one transaction: a failure leaves the tenant exactly as it was.
 */
class CompanyToReseller
{
    public const DEFAULT_RETENTION_YEARS = 5;

    public function __construct(private readonly TenantContext $tenant)
    {
    }

    /**
     * People who can move, with the portal role each is offered by default: the company owner
     * and its administrators are owners, everyone else staff.
     *
     * @return list<array{id:int, name:string, email:string, active:bool, suggested:string}>
     */
    public function candidates(Company $company): array
    {
        $users = User::query()->where('company_id', $company->id)->orderBy('id')->get();
        $names = $this->names($company, $users->pluck('id')->all());
        $admins = DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->whereIn('model_has_roles.model_id', $users->pluck('id'))->where('model_has_roles.model_type', User::class)
            ->whereIn('roles.name', ['administrator', 'super admin'])->pluck('model_has_roles.model_id')->map(fn ($id) => (int) $id)->all();

        return $users->reject(fn (User $u) => $u->is_platform_admin || $u->is_master_admin)->map(fn (User $u) => [
            'id' => (int) $u->id,
            'name' => trim(($names[$u->id]['first_name'] ?? '') . ' ' . ($names[$u->id]['surname'] ?? '')) ?: $u->email,
            'email' => (string) $u->email,
            'active' => (int) $u->status === 1,
            'suggested' => ((int) $company->created_by === (int) $u->id || in_array((int) $u->id, $admins, true)) ? 'owner' : 'staff',
        ])->values()->all();
    }

    /**
     * @param  array{commission_type:string, commission_value:float|int|string, retention_years?:int,
     *               users: array<int|string, string>, notes?:?string}  $options  users: user id => owner|staff|exclude
     */
    public function convert(Company $company, array $options, User $by): Reseller
    {
        $this->guard($company, $options);
        $retention = (int) ($options['retention_years'] ?? self::DEFAULT_RETENTION_YEARS);

        $reseller = DB::transaction(function () use ($company, $options, $by, $retention) {
            $company = Company::query()->lockForUpdate()->findOrFail($company->id);
            if ($company->status === 'archived') {
                throw new InvalidArgumentException("{$company->name} has already been converted.");
            }

            $identity = $this->tenant->run((int) $company->id, fn () => DB::table('company_identities')->where('company_id', $company->id)->first());
            $reseller = Reseller::create([
                'name' => $company->name, 'type' => 'company',
                'registration_number' => $company->registration_number, 'vat_number' => $company->vat_number,
                'contact_person' => $company->contact_person, 'contact_email' => $company->contact_email, 'contact_phone' => $company->contact_phone,
                'address' => $identity->mailing_address ?? null, 'website' => $identity->company_website ?? null,
                'support_email' => $identity->support_email ?? null, 'logo' => $identity->company_logo ?? null,
                'commission_type' => $options['commission_type'], 'commission_value' => $options['commission_value'],
                'notes' => $options['notes'] ?? null, 'status' => 'active', 'created_by' => $by->id,
                'converted_from_company_id' => $company->id, 'converted_at' => now(), 'converted_by' => $by->id,
            ]);

            $moved = $this->moveUsers($company, $reseller, $options['users']);

            DB::table('subscriptions')->where('company_id', $company->id)
                ->update(['status' => 'cancelled', 'current_period_end' => now(), 'updated_at' => now()]);

            $company->forceFill([
                'status' => 'archived', 'archived_at' => now(), 'suspended_at' => now(),
                'suspended_reason' => "Converted to reseller \"{$reseller->name}\"",
                'purge_after' => now()->addYears($retention)->toDateString(),
                'converted_to_reseller_id' => $reseller->id,
            ])->save();

            Log::info('Company converted to reseller', ['company_id' => $company->id, 'reseller_id' => $reseller->id,
                'moved_users' => $moved, 'by' => $by->id, 'purge_after' => $company->purge_after?->toDateString()]);

            return $reseller;
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $reseller->refresh();
    }

    private function guard(Company $company, array $options): void
    {
        if ((int) $company->id === 1) {
            throw new InvalidArgumentException('The primary company cannot become a reseller.');
        }
        if ($company->status === 'archived') {
            throw new InvalidArgumentException("{$company->name} has already been converted.");
        }
        if (! in_array($options['commission_type'] ?? null, Reseller::COMMISSION_TYPES, true)) {
            throw new InvalidArgumentException('Choose a commission type.');
        }
        $retention = (int) ($options['retention_years'] ?? self::DEFAULT_RETENTION_YEARS);
        if ($retention < 1 || $retention > 10) {
            throw new InvalidArgumentException('Keep the archived data for between 1 and 10 years.');
        }
        $choices = collect($options['users'] ?? []);
        if ($choices->contains(fn ($r) => ! in_array($r, ['owner', 'staff', 'exclude'], true))) {
            throw new InvalidArgumentException('Each person is an owner, staff or left behind.');
        }
        if (! $choices->contains('owner')) {
            throw new InvalidArgumentException('Choose at least one owner: someone has to run the reseller account.');
        }
        $ids = $choices->keys()->map(fn ($id) => (int) $id);
        $belongs = User::query()->where('company_id', $company->id)->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id);
        if ($belongs->count() !== $ids->count()) {
            throw new InvalidArgumentException('Only people in this company can move with it.');
        }
    }

    /** @return int how many users moved */
    private function moveUsers(Company $company, Reseller $reseller, array $choices): int
    {
        Role::findOrCreate('reseller', 'web');
        $users = User::query()->where('company_id', $company->id)->get();
        $names = $this->names($company, $users->pluck('id')->all());
        $moved = 0;
        $firstOwner = null;

        foreach ($users as $user) {
            $role = $choices[$user->id] ?? $choices[(string) $user->id] ?? 'exclude';
            if ($role === 'exclude' || $user->is_platform_admin || $user->is_master_admin) {
                // Stays with the archived company, which it can no longer use; deleted with it at the purge.
                $user->forceFill(['status' => 0])->save();

                continue;
            }

            ResellerUser::create(['reseller_id' => $reseller->id, 'user_id' => $user->id, 'role' => $role,
                'first_name' => $names[$user->id]['first_name'] ?? null, 'surname' => $names[$user->id]['surname'] ?? null,
                'phone' => $names[$user->id]['phone'] ?? null]);

            // Tenant access goes: roles, direct permissions and module / menu grants.
            DB::table('model_has_roles')->where('model_id', $user->id)->where('model_type', User::class)->delete();
            DB::table('model_has_permissions')->where('model_id', $user->id)->where('model_type', User::class)->delete();
            foreach (['security_module_access', 'security_ribbons_access', 'security_category_access'] as $table) {
                DB::table($table)->where('user_id', $user->id)->delete();
            }
            $user->forceFill(['company_id' => null, 'fin_entity_id' => null, 'status' => 1])->save();
            $user->unsetRelation('roles')->unsetRelation('permissions');
            $user->assignRole('reseller');

            $firstOwner ??= $role === 'owner' ? $user->id : null;
            $moved++;
        }

        // The legacy single login points at an owner, for code that still reads resellers.user_id.
        $reseller->update(['user_id' => $firstOwner]);

        return $moved;
    }

    /** @return array<int, array{first_name:?string, surname:?string, phone:?string}> names live in the company's HR records */
    private function names(Company $company, array $userIds): array
    {
        if (! $userIds) {
            return [];
        }

        return $this->tenant->run((int) $company->id, fn () => DB::table('hr_people')->whereIn('user_id', $userIds)
            ->get(['user_id', 'first_name', 'surname', 'cell_number'])
            ->mapWithKeys(fn ($p) => [(int) $p->user_id => ['first_name' => $p->first_name, 'surname' => $p->surname, 'phone' => $p->cell_number]])
            ->all());
    }
}
