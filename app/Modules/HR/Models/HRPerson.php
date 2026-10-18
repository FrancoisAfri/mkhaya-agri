<?php

namespace App\Modules\HR\Models;

use App\Core\Position\Models\HrPosition;
use App\Core\User\Models\Country;
use App\Core\User\Models\Province;
use App\Core\User\Models\User;
use App\Modules\HR\Leave\Models\LeaveProfile;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Core\Role\Models\Role;

class HRPerson extends Model
{
    use HasFactory;

    protected $table = 'hr_people';

    public $timestamps = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    protected $fillable = [
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
        'occupational_level',
        'job_function',
        'start_time',
        'end_time',
        'provident_fund_product',
        'can_drive',
    ];

    protected $casts = [
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

    /**
     * The person's name, as a person would write it.
     *
     * There is no `full_name` column and there was no accessor, yet roughly a hundred places across
     * the platform read `person?->full_name` — so every one of them silently rendered nothing where a
     * name belonged. Eloquent returns null for an attribute it cannot find rather than complaining,
     * which is why this survived: each site looked like it worked and simply showed a blank.
     *
     * Surname alone is returned when there is no first name (and the reverse), because a partial
     * record should still identify someone rather than collapse to an empty string.
     */
    public function getFullNameAttribute(): ?string
    {
        $name = trim(implode(' ', array_filter([
            $this->attributes['first_name'] ?? null,
            $this->attributes['surname'] ?? null,
        ])));

        return $name !== '' ? $name : null;
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
}
