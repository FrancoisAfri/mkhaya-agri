<?php

namespace App\Core\User\Models;

use App\Core\Storage\StoredImage;
use App\Core\CompanyUnits\Models\CompanyUnit;
use App\Core\Permission\Model\Module;
use App\Core\Permission\Model\UserModuleAccess;
use App\Core\Ribbon\Models\Ribbon;
use App\Modules\Admin\Setup\Models\CompanyIdentity;
use App\Core\UserManagement\Models\PasswordSecurity;
use App\Modules\Communication\ContentManagement\Models\Reaction;
use App\Modules\ContactPerson\Models\ContactPerson;
use App\Modules\HR\Hr\Models\HRPerson;
use App\Shared\Traits\Uuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, Notifiable, LogsActivity, Uuids, HasApiTokens, SoftDeletes;

    /*
     * Spatie's check is aliased rather than overridden: `hasPermissionTo` arrives through a trait,
     * so `parent::` does not reach it — it falls through to the query builder and fatals. The
     * wrapper below calls the aliased original.
     */
    use HasRoles {
        hasPermissionTo as protected spatieHasPermissionTo;
    }

    protected static function newFactory(): \Database\Factories\UserFactory
    {
        return \Database\Factories\UserFactory::new();
    }

    /**
     * A permission nobody has ever defined is one nobody holds.
     *
     * Spatie *throws* PermissionDoesNotExist when the name is not in the permissions table, so a
     * policy asking about a permission the seeders never created answered 500 rather than 403 —
     * and a 500 on a create screen reads as "the system is broken", not "you may not do this".
     * Three of these were live at once: `offers.create` and `contracts.send` existed in no seeder
     * at all, and AssetPolicy asked for `assets.update` where the seeder made `assets.edit`. The
     * Offers module could not be opened.
     *
     * `EnforceCrudPermissions` already caught this for route-derived checks; every policy and form
     * request calling the method directly did not. Catching it here covers all of them at once.
     *
     * The name is logged rather than swallowed. A missing permission is still a configuration bug
     * — it is simply not one worth taking the screen down over, and the log says which to add.
     */
    public function hasPermissionTo($permission, ?string $guardName = null): bool
    {
        try {
            return $this->spatieHasPermissionTo($permission, $guardName);
        } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist) {
            \Illuminate\Support\Facades\Log::warning(
                'permission checked but never defined — denying, and it should be seeded',
                [
                    'permission' => is_string($permission) ? $permission : (string) ($permission->name ?? '?'),
                    'guard' => $guardName ?? 'web',
                ],
            );

            return false;
        }
    }

    protected static function boot(): void
    {
        parent::boot();

        // Tenant scoping for user listings (manage-users, roles, reports, dropdowns)
        // so a tenant only sees its own users. Deliberately NOT the generic
        // BelongsToCompany scope: platform/master admins must NOT be restricted to a
        // single company — they manage across tenants and impersonate, and scoping
        // would make `User::findOrFail(self)` 404 while a tenant company is active.
        static::addGlobalScope('company', function ($builder): void {
            $current = app(\App\Modules\Tenancy\Services\CurrentCompany::class);
            if (! $current->isSet()) {
                return; // login / console / platform admin not impersonating → all users
            }

            // hasUser() avoids resolving (and recursing on) the auth user mid-query.
            if (\Illuminate\Support\Facades\Auth::hasUser()) {
                $authUser = \Illuminate\Support\Facades\Auth::user();
                if ($authUser->is_platform_admin || ($authUser->is_master_admin ?? false)) {
                    return;
                }
            }

            $builder->where($builder->getModel()->getTable() . '.company_id', $current->id());
        });

        static::creating(function (self $user): void {
            if (empty($user->uuid)) {
                $user->uuid = (string) Str::uuid();
            }

            // Auto-stamp the active company on new users (so tenant-created users
            // land in that tenant instead of NULL). Skipped when no company is set.
            if (empty($user->company_id)) {
                $current = app(\App\Modules\Tenancy\Services\CurrentCompany::class);
                if ($current->isSet()) {
                    $user->company_id = $current->id();
                }
            }
        });
    }

    protected $guard_name = 'web';

    protected $fillable = [
        'uuid',
        'email',
        'password',
        'password_changed_at',
        'status',
        'name',
        'type',
        'phone_number',
        'two_factor_secret',
        'is_platform_admin',
        'is_master_admin',
        'is_information_officer',
        'company_id',
        'platform_admin_granted_by',
        'locale',
    ];

    protected $casts = [
        'is_platform_admin'      => 'boolean',
        'is_master_admin'        => 'boolean',
        'is_information_officer' => 'boolean',
        'password_changed_at'    => 'datetime',
    ];

    /**
     * Store e-mail addresses normalised — lower-cased and trimmed — so logins are
     * case-insensitive and whitespace-tolerant (like Gmail). Applies to every save
     * (registration, manage-users, imports). Login input is normalised the same way.
     */
    public function setEmailAttribute($value): void
    {
        $this->attributes['email'] = $value === null ? null : strtolower(trim($value));
    }

    protected $hidden = [
        'password',
        'remember_token',
        'password_changed_at',
        'two_factor_secret',
    ];

    protected $appends = [
        'name',
    ];

    /*
    |--------------------------------------------------------------------------
    | SCOPES
    |--------------------------------------------------------------------------
    */

    public function scopeActive($query)
    {
        return $query->where('status', 1);
    }

    /*
    |--------------------------------------------------------------------------
    | SECURITY HELPERS
    |--------------------------------------------------------------------------
    */

    public function isLocked(): bool
    {
        return (int) $this->status === 0;
    }

    public function isPlatformAdmin(): bool
    {
        return (bool) $this->is_platform_admin;
    }

    public function isMasterAdmin(): bool
    {
        return (bool) $this->is_master_admin;
    }

    public function isInformationOfficer(): bool
    {
        return (bool) $this->is_information_officer;
    }

    /*
    |--------------------------------------------------------------------------
    | RELATIONS
    |--------------------------------------------------------------------------
    */

    public function person()
    {
        return $this->hasOne(HRPerson::class, 'user_id');
    }

    public function contactPerson()
    {
        return $this->hasOne(ContactPerson::class, 'user_id');
    }

    public function passwordSecurity(): HasOne
    {
        return $this->hasOne(PasswordSecurity::class, 'user_id');
    }

    public function fleetDriver(): HasOne
    {
        return $this->hasOne(\App\Modules\Fleet\Models\FleetDriver::class, 'user_id');
    }

    public function platformAdminGrantedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(self::class, 'platform_admin_granted_by');
    }

    public function addPerson($person)
    {
        return $this->person()->save($person);
    }

    public function updateUser($person)
    {
        return $this->person()->update($person);
    }

    public function moduleAccesses(): HasMany
    {
        return $this->hasMany(UserModuleAccess::class);
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'user_module_access')
            ->withPivot('access_level')
            ->withTimestamps();
    }

    public function companyUnits(): BelongsToMany
    {
        return $this->belongsToMany(
            CompanyUnit::class,
            'company_unit_user_pivots',
            'user_id',
            'company_id'
        )->withTimestamps();
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(
            CompanyIdentity::class,
            'company_user_pivots',
            'user_id',
            'company_id'
        )->withPivot('type')
            ->withTimestamps();
    }
// Dans User.php
public function reactions()
{
    return $this->belongsToMany(
      Reaction::class,
        'reaction_user',
        'user_id',
        'reaction_id'
    )->withTimestamps();
}

public function ribbons()
{
    return $this->belongsToMany(
        Ribbon::class,
        'quick_access_users',
        'user_id',
        'ribbon_id'
    )->withPivot('name')
      ->withTimestamps();
}

    /*
    |--------------------------------------------------------------------------
    | ACCESSORS
    |--------------------------------------------------------------------------
    */

    public function getPrimaryUnitAttribute(): ?CompanyUnit
    {
        return $this->companyUnits()->orderBy('sort_order')->first();
    }

    public function getDivisionAttribute(): ?CompanyUnit
    {
        return $this->companyUnits()->where('type', 'division')->first();
    }

    public function getDepartmentAttribute(): ?CompanyUnit
    {
        return $this->companyUnits()->where('type', 'department')->first();
    }

    public function getAvatarUrlAttribute(): ?string
    {
        if (! $this->person) {
            return null;
        }

        return StoredImage::url($this->person->profile_pic_path, $this->person->profile_pic);
    }

    /**
     * The person record behind this user, read regardless of which company is being viewed.
     *
     * `hr_people` is under row-level security, and a platform admin looking at one tenant has a
     * person row belonging to another — so the ordinary lookup returns nothing and the name falls
     * back to an email address. Somebody's own name is not tenant data; it should not change
     * depending on whose company they happen to be looking at.
     *
     * Reading untenanted is safe here precisely because the query is pinned to this user's id: it
     * can return exactly one row, and it is theirs. Nothing else is exposed.
     */
    protected function personForDisplay(): ?HRPerson
    {
        $select = fn () => $this->person()->select('id', 'user_id', 'first_name', 'surname')->first();

        $person = $select();

        if ($person !== null) {
            return $person;
        }

        try {
            return app(\App\Modules\Tenancy\Services\TenantContext::class)->run(null, $select);
        } catch (\Throwable) {
            // Tenancy not resolvable (console, boot, tests without the container) — the email
            // fallback below is still correct, and a display name is never worth an exception.
            return null;
        }
    }

    /**
     * Resolve display name even when the users table has no physical `name` column.
     */
    public function getNameAttribute($value): string
    {
        $rawValue = is_string($value) ? trim($value) : '';
        if ($rawValue !== '') {
            return $rawValue;
        }

        $person = $this->relationLoaded('person')
            ? $this->getRelation('person')
            : $this->personForDisplay();

        $fullName = trim(($person->first_name ?? '') . ' ' . ($person->surname ?? ''));
        if ($fullName !== '') {
            return $fullName;
        }

        // A reseller's team member has no company and so no HR record; the name lives on the team row.
        if (empty($this->attributes['company_id']) && ! empty($this->attributes['id'])) {
            $member = \Illuminate\Support\Facades\DB::table('reseller_users')->where('user_id', $this->attributes['id'])->first(['first_name', 'surname']);
            $fullName = trim(($member->first_name ?? '') . ' ' . ($member->surname ?? ''));
            if ($fullName !== '') {
                return $fullName;
            }
        }

        return (string) ($this->attributes['email'] ?? $this->email ?? ('User #' . $this->id));
    }

    /*
    |--------------------------------------------------------------------------
    | NOTIFICATION ROUTING
    |--------------------------------------------------------------------------
    */

    /**
     * Resolves the WhatsApp recipient number from the linked hr_people record.
     * Returns null when no cell number is available, suppressing the send.
     */
    public function routeNotificationForWhatsApp(mixed $notification): ?string
    {
        $person = $this->relationLoaded('person') ? $this->getRelation('person') : $this->person;
        $cell   = $person?->cell_number;

        if (!$cell) return null;

        // Normalise to E.164 for South African numbers (+27…)
        $digits = preg_replace('/\D/', '', $cell);
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            $digits = '27' . substr($digits, 1);
        }

        return '+' . ltrim($digits, '+');
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    protected function getStatusLabel(): string
    {
        return match ((int) $this->status) {
            1       => 'active',
            0       => 'locked',
            2       => 'suspended',
            default => 'unknown status',
        };
    }

    protected function getTypeLabel(): string
    {
        return match ($this->type) {
            'admin'       => 'administrator',
            'super_admin' => 'super administrator',
            'employee'    => 'employee',
            'manager'     => 'manager',
            default       => 'user',
        };
    }

    /*
    |--------------------------------------------------------------------------
    | ACTIVITY LOG
    |--------------------------------------------------------------------------
    */

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('User Management')
            ->logOnly([
                'name',
                'email',
                'phone_number',
                'type',
                'status',
                'password',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->dontLogIfAttributesChangedOnly(['updated_at'])
            ->setDescriptionForEvent(
                fn(string $eventName) => $this->getDescriptionForEvent($eventName)
            );
    }

    protected function getDescriptionForEvent(string $eventName): string
    {
        $actor      = Auth::user()?->name ?? 'System';
        $isSelf     = Auth::id() === $this->id;
        $actorLabel = $isSelf ? "{$actor} (self)" : $actor;
        $name       = $this->name ?? 'Unknown user';
        $typeLabel  = $this->getTypeLabel();
        $status     = $this->getStatusLabel();

        return match ($eventName) {
            'created' => "{$actorLabel} created a new {$typeLabel} account for \"{$name}\" ({$this->email}) — account is {$status}.",

            'updated' => (function () use ($actorLabel, $name, $typeLabel, $status) {
                $dirty = collect($this->getDirty())->except(['updated_at'])->keys();

                $passwordChanged = $dirty->contains('password');

                $changes = $dirty
                    ->reject(fn($f) => $f === 'password')
                    ->map(fn($field) => match ($field) {
                        'name'         => 'the full name',
                        'email'        => "the email address (now {$this->email})",
                        'phone_number' => 'the phone number',
                        'type'         => "the account role (now {$typeLabel})",
                        'status'       => "the account status (now {$status})",
                        default        => $field,
                    })
                    ->values(); // ✅ reset des clés avant push

                if ($passwordChanged) {
                    $changes->push('the password (securely updated)');
                }

                $summary = $changes->isNotEmpty()
                    ? 'Updated ' . $changes->join(', ', ' and ') . ' on'
                    : 'Made changes to';

                return "{$actorLabel} — {$summary} the {$typeLabel} account \"{$name}\".";
            })(),

            'deleted' => "{$actorLabel} permanently deleted the {$typeLabel} account \"{$name}\" ({$this->email}) from the platform.",

            default   => "{$actorLabel} triggered a \"{$eventName}\" event on the {$typeLabel} account \"{$name}\".",
        };
    }
}
