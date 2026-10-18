<?php

namespace Database\Seeders;

use App\Core\Category\Models\CategoryAccess;
use App\Core\Module\Models\ModuleAccess;
use App\Core\Ribbon\Models\RibbonAccess;
use App\Core\User\Models\User;
use App\Core\UserManagement\Models\PasswordHistory;
use App\Core\UserManagement\Models\PasswordSecurity;
use App\Modules\HR\Hr\Models\HRPerson;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    const USER_TYPE_ADMIN          = 3;
    const USER_TYPE_REGULAR        = 1;
    const MODULE_ACCESS_LEVEL_FULL = 1;

    /** Passwords generated this run (env not set) — surfaced once at the end. */
    private array $generated = [];

    public function run(): void
    {
        // ── Master admin (François) — seeded first so others can reference his ID ──
        $this->createAdminUser(
            name: 'francois',
            email: 'francois@mkhayamk.co.za',
            password: $this->resolvePassword('SEED_MASTER_ADMIN_PASSWORD', 'francois@mkhayamk.co.za'),
            phoneNumber: env('SEED_MASTER_ADMIN_PHONE'),
            personData: ['first_name' => 'Francois', 'surname' => 'keou', 'initial' => 'F'],
            isMaster: true,
        );

        $masterId = User::where('email', 'francois@mkhayamk.co.za')->value('id');

        // ── Platform team members ─────────────────────────────────────────────────
        $this->createAdminUser(
            name: 'Support',
            email: 'support@mkhayamk.co.za',
            password: $this->resolvePassword('SEED_SUPPORT_PASSWORD', 'support@mkhayamk.co.za'),
            phoneNumber: env('SEED_SUPPORT_PHONE'),
            personData: ['first_name' => 'Support', 'surname' => 'Mkhaya MK', 'initial' => 'SM'],
            grantedBy: $masterId,
        );

        $this->reportGeneratedPasswords();

        // Additional team members are provisioned through the app's User
        // Management UI, not seeded here — credentials never live in source.
    }

    /**
     * Resolve an admin password from env, or generate a strong random one so no
     * credential is ever committed to source. A generated password is only
     * meaningful when the account is first created (firstOrCreate), so we only
     * surface it then.
     */
    private function resolvePassword(string $envKey, string $email): string
    {
        $value = env($envKey);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        $value = Str::password(20);
        if (! User::where('email', $email)->exists()) {
            $this->generated[$email] = $value;
        }

        return $value;
    }

    /** Print any generated admin passwords once — they are stored nowhere else. */
    private function reportGeneratedPasswords(): void
    {
        if (empty($this->generated)) {
            return;
        }

        $cmd = $this->command ?? null;
        $cmd?->warn('Generated admin password(s) — no SEED_*_PASSWORD env was set.');
        $cmd?->warn('Record these now (shown only once), then rotate them in-app:');
        foreach ($this->generated as $email => $pw) {
            $cmd?->line("  {$email}  =>  {$pw}");
        }
    }



    private function createAdminUser(
        string $name,
        string $email,
        string $password,
        ?string $phoneNumber,
        array $personData,
        bool $isMaster = false,
        ?int $grantedBy = null,
    ): void {
        $this->createUser(
            name: $name,
            email: $email,
            password: $password,
            phoneNumber: $phoneNumber,
            type: self::USER_TYPE_ADMIN,
            roleName: 'administrator',
            personData: $personData,
            grantFullModuleAccess: true,
            isPlatformAdmin: true,
            isMasterAdmin: $isMaster,
            platformAdminGrantedBy: $grantedBy,
        );
    }

    private function createRegularUser(
        string $name,
        string $email,
        string $password,
        array $personData
    ): void {
        $this->createUser(
            name: $name,
            email: $email,
            password: $password,
            phoneNumber: null,
            type: self::USER_TYPE_REGULAR,
            roleName: 'user',
            personData: $personData,
            grantFullModuleAccess: false
        );
    }

    private function createUser(
        string $name,
        string $email,
        string $password,
        ?string $phoneNumber,
        int $type,
        string $roleName,
        array $personData,
        bool $grantFullModuleAccess = false,
        bool $isPlatformAdmin = false,
        bool $isMasterAdmin = false,
        ?int $platformAdminGrantedBy = null,
    ): void {
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'uuid'                      => (string) Str::uuid(),
                'password'                  => Hash::make($password),
                'type'                      => $type,
                'status'                    => 1,
                'is_platform_admin'         => $isPlatformAdmin,
                'is_master_admin'           => $isMasterAdmin,
                'platform_admin_granted_by' => $platformAdminGrantedBy,
            ]
        );

        // Ensure flags stay current on subsequent runs
        $user->update([
            'is_platform_admin'         => $isPlatformAdmin,
            'is_master_admin'           => $isMasterAdmin,
            'platform_admin_granted_by' => $platformAdminGrantedBy,
        ]);

        if (! $user->person()->exists()) {
            $person = new HRPerson(array_merge($personData, [
                'email'        => $email,
                'phone_number' => $phoneNumber,
                'status'       => 1,
                'uuid'         => Str::uuid(),
            ]));
            $user->addPerson($person);
        }

        PasswordHistory::createPassword($user->id, Hash::make($password));
        PasswordSecurity::addExpiryDate($user->id);

        $user->assignRole($roleName);

        if ($grantFullModuleAccess && $user->wasRecentlyCreated) {

            $categoryIds = DB::table('security_categories')->pluck('id');
            foreach ($categoryIds as $categoryId) {
                CategoryAccess::updateOrInsert(
                    ['user_id' => $user->id, 'category_id' => $categoryId],
                    [
                        'access_level' => self::MODULE_ACCESS_LEVEL_FULL,
                        'active'       => true,
                    ]
                );
            }

            $modules = DB::table('security_modules')->pluck('id');
            foreach ($modules as $moduleId) {
                // updateOrInsert (not firstOrCreate) so a lower-level grant left by
                // an earlier module/nav seeder is UPGRADED to full for the admin.
                ModuleAccess::updateOrInsert(
                    ['user_id' => $user->id, 'module_id' => $moduleId],
                    [
                        'access_level' => self::MODULE_ACCESS_LEVEL_FULL,
                        'active'       => 1,
                    ]
                );
            }

            $ribbons = DB::table('security_module_ribbons')->pluck('id');
            foreach ($ribbons as $ribbonId) {
                $exists =  RibbonAccess::where('user_id', $user->id)
                    ->where('ribbon_id', $ribbonId)
                    ->exists();

                if (!$exists) {
                    RibbonAccess::insert([
                        'ribbon_id'    => $ribbonId,
                        'user_id'      => $user->id,
                        'access_level' => self::MODULE_ACCESS_LEVEL_FULL,
                        'active'       => 1,
                        'created_at'   => now(),
                        'updated_at'   => now(),
                    ]);
                } else {
                    // Upgrade a lower-level grant from an earlier seeder to full.
                    RibbonAccess::where('user_id', $user->id)
                        ->where('ribbon_id', $ribbonId)
                        ->update(['access_level' => self::MODULE_ACCESS_LEVEL_FULL, 'active' => 1]);
                }
            }
        }
    }
}
