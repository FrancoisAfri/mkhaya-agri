<?php

namespace App\Modules\HR\Hr\Models;

use App\Modules\Operations\DocumentManagement\Concerns\HasDocuments;
use App\Core\DocumentType\Model\EmployeeDocuments;
use App\Core\Position\Models\HrPosition;
use App\Core\User\Models\Country;
use App\Core\User\Models\Province;
use App\Core\User\Models\User;
use App\Modules\HR\Leave\Models\LeaveProfile;
use App\Shared\Casts\EncryptedPhi;
use App\Shared\Traits\HasPhiAudit;
use App\Shared\Traits\IsDataSubject;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use App\Core\Role\Models\Role;

class HRPerson extends Model
{
    use HasDocuments;

    use HasFactory, LogsActivity, HasPhiAudit, IsDataSubject;

    // HPCSA Rule 14 — 5 years post last consultation / date_left
    protected int    $retentionYears = 5;
    protected string $retentionFrom  = 'updated_at';

    // PHI fields for HasPhiAudit::getPhiFieldNames()
    protected array $phiFields = ['id_number', 'passport_number', 'income_tax_number'];

    protected $table = 'hr_people';

    /**
     * A new employee starts on whatever cycle their company pays on.
     *
     * The column's default is monthly, which is right for almost everybody and wrong in the one
     * case that matters: a company that pays weekly hires somebody, the row defaults to monthly,
     * and that person is then filtered out of every weekly run — never paid, with nothing on screen
     * to say why. The company setting is the best available answer at the moment of creation, and
     * anything explicitly passed still wins.
     */
    protected static function booted(): void
    {
        static::creating(function (self $person) {
            if (filled($person->pay_frequency)) {
                return;
            }

            $person->pay_frequency = app(\App\Modules\HR\Payroll\Services\PayrollService::class)->payFrequency();
        });
    }


    // Never expose the encrypted payslip lock key in serialization.
    protected $hidden = ['payslip_key'];

    public $timestamps = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    protected $fillable = [
        'pay_frequency',
        'user_id',
        'title',
        'first_name',
        'surname',
        'employee_number',
        'middle_name',
        'maiden_name',
        'aka',
        'initial',
        'position',
        'email',
        'cell_number',
        'phone_number',
        'id_number',
        'res_address',
        'res_suburb',
        'res_city',
        'res_postal_code',
        'res_province_id',
        'res_country_id',
        'date_of_birth',
        'passport_number',
        'drivers_licence_number',
        'drivers_licence_code',
        'proof_drive_permit',
        'proof_drive_permit_exp_date',
        'drivers_licence_exp_date',
        'gender',
        'own_transport',
        'marital_status',
        'ethnicity',
        'profile_pic',
        'profile_pic_path',
        'status',
        'division_level_1',
        'division_level_2',
        'division_level_3',
        'division_level_4',
        'division_level_5',
        'leave_profile',
        'manager_id',
        'date_joined',
        'date_left',
        'role_id',
        'second_manager_id',
        'uuid',
        'known_as',
        'alternate_number',
        'next_of_kin',
        'next_of_kin_number',
        'next_of_kin_work_number',
        'complex_name',
        'unit_number',
        'income_tax_number',
        'tax_office',
        'account_type',
        'account_holder_name',
        'bank_name',
        'branch_name',
        'account_number',
        'package_components',
        'monthly_package',
        'package_cash',
        'package_travel',
        'package_incentive',
        'package_cell_phone',
        'package_med_aid',
        'med_start_date',
        'med_split',
        'med_plan_name',
        'med_dep_spouse',
        'med_dep_adult',
        'med_dep_kids',
        'med_amount',
        'provident_start_date',
        'provident_name',
        'provident_amount',
        'disabled',
        'nature_of_disability',
        'citizenship',
        'other',
        'employment_type',
        'employment_category',
        'eti_opt_in',
        'eti_start_date',
        'occupational_level',
        'job_function',
        'start_time',
        'end_time',
        'provident_fund_product',
        'can_drive',
        'position_id',
    ];

    protected $casts = [
        'id_number'         => EncryptedPhi::class,
        'passport_number'   => EncryptedPhi::class,
        'income_tax_number' => EncryptedPhi::class,
        'user_id' => 'integer',
        'position' => 'integer',
        'res_postal_code' => 'integer',
        'res_province_id' => 'integer',
        'res_country_id' => 'integer',
        'date_of_birth' => 'integer',
        'proof_drive_permit_exp_date' => 'integer',
        'drivers_licence_exp_date' => 'integer',
        'gender' => 'integer',
        'own_transport' => 'integer',
        'marital_status' => 'integer',
        'ethnicity' => 'integer',
        'status' => 'integer',
        'res_address' => 'string',
        'res_postal_code' => 'string',
        'division_level_1' => 'integer',
        'division_level_2' => 'integer',
        'division_level_3' => 'integer',
        'division_level_4' => 'integer',
        'division_level_5' => 'integer',
        'leave_profile' => 'integer',
        'manager_id' => 'integer',
        'date_joined' => 'integer',
        'date_left' => 'integer',
        'role_id' => 'integer',
        'second_manager_id' => 'integer',
        'uuid' => 'string',
        'monthly_package' => 'float',
        'package_cash' => 'float',
        'package_travel' => 'float',
        'package_incentive' => 'float',
        'package_cell_phone' => 'float',
        'package_med_aid' => 'float',
        'med_amount' => 'float',
        'provident_amount' => 'float',
        'disabled' => 'integer',
        'citizenship' => 'integer',
        'employment_type' => 'integer',
        'occupational_level' => 'integer',
        'job_function' => 'integer',
        'can_drive' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    public function companyUnitHeritage(): string
{
    if ($this->user_id) {
        $units = DB::table('company_unit_user_pivots as p')
            ->join('company_units as u', 'u.id', '=', 'p.company_id')
            ->where('p.user_id', $this->user_id)
            ->orderBy('u.sort_order')
            ->pluck('u.name', 'u.type');

        if ($units->isNotEmpty()) {
            return $units->map(fn($name, $type) => "{$name} ({$type})")->implode(', ');
        }
    }

    return '—';
}

    // Accessors for Unix timestamps (optional)
    public function getDateOfBirthAttribute($value)
    {
        return $value ? \Carbon\Carbon::createFromTimestamp($value) : null;
    }

    public function setDateOfBirthAttribute($value)
    {
        $this->attributes['date_of_birth'] = $value instanceof \Carbon\Carbon ? $value->timestamp : $value;
    }

    public function getDateJoinedAttribute($value)
    {
        return $value ? \Carbon\Carbon::createFromTimestamp($value) : null;
    }

    public function setDateJoinedAttribute($value)
    {
        $this->attributes['date_joined'] = $value instanceof \Carbon\Carbon ? $value->timestamp : $value;
    }

    public function getDateLeftAttribute($value)
    {
        return $value ? \Carbon\Carbon::createFromTimestamp($value) : null;
    }

    public function setDateLeftAttribute($value)
    {
        $this->attributes['date_left'] = $value instanceof \Carbon\Carbon ? $value->timestamp : $value;
    }

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // ── Payslip lock key (encrypted at rest) — the PDF open-password ─────────────

    public function setPayslipKeyAttribute($value): void
    {
        $this->attributes['payslip_key'] = $value ? \Illuminate\Support\Facades\Crypt::encryptString($value) : null;
    }

    public function payslipKey(): ?string
    {
        return ! empty($this->attributes['payslip_key'])
            ? \Illuminate\Support\Facades\Crypt::decryptString($this->attributes['payslip_key'])
            : null;
    }

    public function hasPayslipKey(): bool
    {
        return ! empty($this->attributes['payslip_key']);
    }

    /** Ensure the employee has a payslip key, generating one if needed. */
    public function ensurePayslipKey(): string
    {
        if (! $this->hasPayslipKey()) {
            $this->payslip_key = app(\App\Modules\HR\Payroll\Services\SecurePayslipService::class)->generateKey();
            $this->save();
        }

        return $this->payslipKey();
    }

    public function manager()
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    public function secondManager()
    {
        return $this->belongsTo(self::class, 'second_manager_id');
    }

    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }
    public function hrPosition()
    {

        return $this->belongsTo(HrPosition::class, 'position_id', 'id');
    }

    public function leaveProfileData()
    {
        return $this->belongsTo(LeaveProfile::class, 'leave_profile', 'id');
    }

    public function province()
    {
        return $this->belongsTo(Province::class, 'res_province_id');
    }

    public function country()
    {
        return $this->belongsTo(Country::class, 'res_country_id');
    }

    // Accessor: department name from company_unit_user_pivots → company_units, falls back to division_level_1
    public function getDepartmentAttribute(): string
    {
        if ($this->relationLoaded('user') && $this->user?->relationLoaded('companyUnits')) {
            $unit = $this->user->companyUnits->firstWhere('type', 'department');
            if ($unit) return $unit->name;
        }

        if ($this->user_id) {
            $name = DB::table('company_unit_user_pivots as p')
                ->join('company_units as u', 'u.id', '=', 'p.company_id')
                ->where('p.user_id', $this->user_id)
                ->where('u.type', 'department')
                ->value('u.name');
            if ($name) return $name;
        }

        return $this->division_level_1 ?? '—';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('HRPerson')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->dontLogIfAttributesChangedOnly(['updated_at'])
            ->setDescriptionForEvent(
                fn(string $eventName) =>
                $this->getDescriptionForEvent($eventName)
            );
    }
    protected function getDescriptionForEvent(string $eventName): string
    {
        $user = Auth::user();

        $name = $this->first_name . ' ' . $this->surname;

        return match ($eventName) {
            'created' => "HR Person '{$name}' created by " . ($user?->name ?? 'System'),
            'updated' => "HR Person '{$name}' updated by " . ($user?->name ?? 'System'),
            'deleted' => "HR Person '{$name}' deleted by " . ($user?->name ?? 'System'),
            default => "HR Person {$eventName} event",
        };
    }

    public function getProfileCompletionAttribute(): int
    {
        $fields = [
            'title',
            'first_name',
            'surname',
            'employee_number',
            'middle_name',
            'maiden_name',
            'aka',
            'initial',
            'email',
            'cell_number',
            'phone_number',
            'id_number',
            'res_address',
            'res_suburb',
            'res_city',
            'res_postal_code',
            'res_province_id',
            'res_country_id',
            'date_of_birth',
            'passport_number',
            'drivers_licence_number',
            'drivers_licence_code',
            'proof_drive_permit',
            'proof_drive_permit_exp_date',
            'drivers_licence_exp_date',
            'gender',
            'own_transport',
            'marital_status',
            'ethnicity',
            'profile_pic',
            'profile_pic_path',
            'status',
            'leave_profile',
            'manager_id',
            'date_joined',
            'date_left',
            'role_id',
            'second_manager_id',
            'uuid',
            'known_as',
            'alternate_number',
            'next_of_kin',
            'next_of_kin_number',
            'next_of_kin_work_number',
            'complex_name',
            'unit_number',
            'income_tax_number',
            'tax_office',
            'account_type',
            'account_holder_name',
            'bank_name',
            'branch_name',
            'account_number',
            'package_components',
            'monthly_package',
            'package_cash',
            'package_travel',
            'package_incentive',
            'package_cell_phone',
            'package_med_aid',
            'med_start_date',
            'med_split',
            'med_plan_name',
            'med_dep_spouse',
            'med_dep_adult',
            'med_dep_kids',
            'med_amount',
            'provident_start_date',
            'provident_name',
            'provident_amount',
            'disabled',
            'nature_of_disability',
            'citizenship',
            'other',
            'employment_type',
            'occupational_level',
            'job_function',
            'start_time',
            'end_time',
            'provident_fund_product',
            'can_drive',
            'position_id',
        ];

        $filled = 0;
        $total = count($fields);

        foreach ($fields as $field) {
            if (!empty($this->$field)) {
                $filled++;
            }
        }

        $percentage = ($filled / $total) * 100;

        $steps = [5, 10, 20, 30, 40, 50, 60, 70, 80, 90, 100];

        foreach ($steps as $step) {
            if ($percentage <= $step) {
                return $step;
            }
        }

        return 100;
    }

    public function documents()
    {
        return $this->hasMany(EmployeeDocuments::class, 'hr_person_id');
    }
}
