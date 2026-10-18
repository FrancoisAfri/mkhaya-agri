<?php

nameSpace  App\Modules\HR\Hr\Models;
use App\Core\DocumentType\Model\EmployeeDocuments;
use App\Core\Position\Models\HrPosition;
use App\Core\User\Models\Country;
use App\Core\User\Models\Province;
use App\Modules\HR\Leave\Models\LeaveProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use App\Core\Role\Models\Role;

class HrPeopleChange extends Model
{
    use LogsActivity;
    public $table = 'hr_people_changes';

    protected $fillable = [
        'uuid',
        'first_name',
        'surname',
        'middle_name',
        'maiden_name',
        'aka',
        'initial',
        'email',
        'cell_number',
        'phone_number',
        'id_number',
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
        'status',
        'division_level_1',
        'division_level_2',
        'division_level_3',
        'employee_number',
        'division_level_4',
        'division_level_5',
        'leave_profile',
        'manager_id',
        'second_manager_id',
        'date_joined',
        'date_left',
        'role_id',
        'position',
        'title',
        'known_as',
        'alternate_number',
        'next_of_kin',
        'next_of_kin_number',
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
        'res_address',
        'res_suburb',
        'res_city',
        'res_postal_code',
        'res_province_id',
        'hr_main_id',
        'provident_fund_product',
        'start_time',
        'end_time',
        'is_approved',
        'res_country_id'
    ];

    protected $casts = [
        'date_of_birth' => 'integer',
        'date_joined' => 'integer',
        'date_left' => 'integer',
        'drivers_licence_exp_date' => 'integer',
        'proof_drive_permit_exp_date' => 'integer',
        'med_start_date' => 'integer',
        'provident_start_date' => 'integer',
        'is_approved' => 'boolean',
    ];

    public function getDateOfBirthAttribute($value)
    {
        return $value ? \Carbon\Carbon::createFromTimestamp($value)->format('Y-m-d') : null;
    }

    public function getDateJoinedAttribute($value)
    {
        return $value ? \Carbon\Carbon::createFromTimestamp($value)->format('Y-m-d') : null;
    }

    public function getDateLeftAttribute($value)
    {
        return $value ? \Carbon\Carbon::createFromTimestamp($value)->format('Y-m-d') : null;
    }

    public function getMedStartDateAttribute($value)
    {
        return $value ? \Carbon\Carbon::createFromTimestamp($value)->format('Y-m-d') : null;
    }

    public function getProvidentStartDateAttribute($value)
    {
        return $value ? \Carbon\Carbon::createFromTimestamp($value)->format('Y-m-d') : null;
    }

    // Relations
    public function hrPerson()
    {
        return $this->belongsTo(HRPerson::class, 'hr_main_id');
    }

    public function position()
    {
        return $this->belongsTo(HrPosition::class, 'position');
    }

    public function manager()
    {
        return $this->belongsTo(HRPerson::class, 'manager_id');
    }

    public function secondManager()
    {
        return $this->belongsTo(HRPerson::class, 'second_manager_id');
    }

    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id');
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

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('HrPeopleChange')
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

        $name = trim(($this->first_name ?? '') . ' ' . ($this->surname ?? ''));

        return match ($eventName) {
            'created' => "HR change record for '{$name}' created by " . ($user?->name ?? 'System'),
            'updated' => "HR change record for '{$name}' updated by " . ($user?->name ?? 'System'),
            'deleted' => "HR change record for '{$name}' deleted by " . ($user?->name ?? 'System'),
            default => "HR change {$eventName} event",
        };
    }

    public function getProfileCompletionAttribute(): int
    {
        $fields = [
            'first_name',
            'surname',
            'middle_name',
            'maiden_name',
            'aka',
            'initial',
            'email',
            'cell_number',
            'phone_number',
            'id_number',
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
            'status',
            'division_level_1',
            'division_level_2',
            'division_level_3',
            'employee_number',
            'division_level_4',
            'division_level_5',
            'leave_profile',
            'manager_id',
            'second_manager_id',
            'date_joined',
            'date_left',
            'role_id',
            'position',
            'title',
            'known_as',
            'alternate_number',
            'next_of_kin',
            'next_of_kin_number',
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
            'res_address',
            'res_suburb',
            'res_city',
            'res_postal_code',
            'res_province_id',
            'hr_main_id',
            'provident_fund_product',
            'start_time',
            'end_time',
            'res_country_id'
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
        return $this->hasMany(EmployeeDocuments::class,'hr_change_id');
    }
}
