<?php

namespace App\Modules\HR\Models;


use App\Core\User\Models\User;

use Illuminate\Database\Eloquent\Model;

// use App\Shared\Traits\Uuids;

class HrPeopleChange extends Model
{
	 /**
     * @var string[]
     */


    //Specify the table name
    public $table = 'hr_people_changes';
	 // Mass assignable fields
    protected $fillable = [
        'uuid',
        'first_name', 'surname', 'middle_name', 'maiden_name', 'aka', 'initial', 'email', 'cell_number',
        'phone_number', 'id_number', 'date_of_birth', 'passport_number', 'drivers_licence_number', 'drivers_licence_code',
        'proof_drive_permit', 'proof_drive_permit_exp_date', 'drivers_licence_exp_date', 'gender', 'own_transport', 'marital_status',
        'ethnicity', 'profile_pic', 'status', 'division_level_1', 'division_level_2', 'division_level_3', 'employee_number',
        'division_level_4', 'division_level_5', 'leave_profile', 'manager_id', 'second_manager_id', 'date_joined', 'date_left', 'role_id',
		'position','title','known_as','alternate_number','next_of_kin','next_of_kin_number'
		,'complex_name','unit_number','income_tax_number','tax_office','account_type','account_holder_name',
		'bank_name','branch_name','account_number','package_components','monthly_package'
		,'package_cash','package_travel','package_incentive','package_cell_phone','package_med_aid',
		'med_start_date','med_split','med_plan_name','med_dep_spouse','med_dep_adult','med_dep_kids',
		'med_amount','provident_start_date','provident_name','provident_amount','disabled',
		'nature_of_disability','citizenship','other','employment_type','occupational_level','job_function',
        'res_address', 'res_suburb', 'res_city', 'res_postal_code', 'res_province_id', 'hr_main_id', 'provident_fund_product'
		, 'start_time', 'end_time','is_approved'];



    public function hrPerson()
    {
        return $this->belongsTo(HRPerson::class);
    }
}
