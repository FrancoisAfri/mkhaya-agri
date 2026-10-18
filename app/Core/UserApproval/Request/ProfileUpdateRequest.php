<?php

namespace App\Core\UserApproval\Request;

use App\Core\User\Models\User;
use App\Modules\HR\Models\HRPerson;
use Illuminate\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Route uses {uuid} not {id}, so resolve the real PK for unique() exclusion.
        $hrperson=HRPerson::where('uuid', $this->route('uuid'))->firstOrFail();
        $userId = User::findOrFail($hrperson->user_id)->id;

        return [
            // ── Security ──
            'password'        => 'nullable|string|min:8',

            // ── Personal ──
            'first_name'      => 'required|string|max:255',
            'surname'         => 'required|string|max:255',
            'middle_name'     => 'nullable|string|max:255',
            'maiden_name'     => 'nullable|string|max:255',
            'aka'             => 'nullable|string|max:255',
            'initial'         => 'nullable|string|max:10',
            'known_as'        => 'nullable|string|max:255',
            'title'           => 'nullable|string|max:20',
            'gender'          => 'nullable|integer',
            'date_of_birth'   => 'nullable|date',
            'id_number'       => 'nullable|string|max:50',
            'passport_number' => 'nullable|string|max:50',
            'marital_status'  => 'nullable|integer',
            'ethnicity'       => 'nullable|integer',
            'citizenship'     => 'nullable|integer',
            'other'           => 'nullable|string|max:500',

            // ── Contact ──
            'cell_number'      => 'nullable|string|max:20',
            'phone_number'     => 'nullable|string|max:20',
            'alternate_number' => 'nullable|string|max:20',
            'email_person'     => 'nullable|email|max:255',

            // ── Adresse ──
            'res_address'     => 'nullable|string|max:255',
            'res_suburb'      => 'nullable|string|max:255',
            'res_city'        => 'nullable|string|max:100',
            'res_postal_code' => 'nullable|string',
            'res_province_id' => 'nullable|integer',
            'res_country_id'  => 'nullable|integer',

            // ── Urgence ──
            'next_of_kin'             => 'nullable|string|max:255',
            'next_of_kin_number'      => 'nullable|string|max:50',
            'next_of_kin_work_number' => 'nullable|string|max:50',

            // ── Tax ──
            'income_tax_number' => 'nullable|string|max:50',
            'tax_office'        => 'nullable|string|max:255',

            // ── Banque ──
            'account_type'        => 'nullable|string|max:50',
            'account_holder_name' => 'nullable|string|max:255',
            'bank_name'           => 'nullable|string|max:255',
            'branch_name'         => 'nullable|string|max:255',
            'account_number'      => 'nullable|string|max:50',

            // ── Medical ──
            'med_start_date'  => 'nullable|date',
            'med_split'       => 'nullable|string|max:50',
            'med_plan_name'   => 'nullable|string|max:255',
            'med_dep_spouse'  => 'nullable|string|max:50',
            'med_dep_adult'   => 'nullable|string|max:50',
            'med_dep_kids'    => 'nullable|string|max:50',
            'med_amount'      => 'nullable|numeric',
            'package_med_aid' => 'nullable|numeric',

            // ── Provident ──
            'provident_start_date'   => 'nullable|date',
            'provident_name'         => 'nullable|string|max:255',
            'provident_amount'       => 'nullable|numeric',
            'provident_fund_product' => 'nullable|string|max:255',

            // ── Travail ──
            'employee_number'      => 'nullable|string|max:50',
            'position_id'          => 'nullable|exists:hr_positions,id',
            'date_joined'          => 'nullable|date',
            'date_left'            => 'nullable|date',
            'start_time'           => 'nullable|date_format:H:i',
            'end_time'             => 'nullable|date_format:H:i',
            'leave_profile'        => 'nullable|exists:leave_profile,id',
            'role_id'              => 'nullable|exists:roles,id',
            'manager_id'           => 'nullable|exists:users,id',
            'second_manager_id'    => 'nullable|exists:users,id',
            'employment_type'      => 'nullable|integer',
            'occupational_level'   => 'nullable|integer',
            'job_function'         => 'nullable|integer',
            'disabled'             => 'nullable|integer',
            'nature_of_disability' => 'nullable|string|max:255',

            // ── Conduite ──
            'can_drive'                   => 'nullable|integer',
            'drivers_licence_number'      => 'nullable|string|max:50',
            'drivers_licence_code'        => 'nullable|string|max:50',
            'drivers_licence_exp_date'    => 'nullable|date',
            'proof_drive_permit' => [
                'nullable',
                function ($attribute, $value, $fail) {

                    if ($value instanceof \Illuminate\Http\UploadedFile) {

                        if (!$value->isValid()) {
                            $fail("The {$attribute} file is invalid.");
                            return;
                        }

                        // 50 MB = 51200 KB
                        if ($value->getSize() > 51200 * 1024) {
                            $fail("The {$attribute} must not exceed 50MB.");
                            return;
                        }

                        return;
                    }

                    // Si ce n'est pas un fichier (ex: JSON string / null), on ignore
                },
            ],
            // 'proof_drive_permit_exp_date' => 'nullable|date',
            'own_transport'               => 'nullable|integer',

            // ── Package ──
            'package_components' => 'nullable|string|max:255',
            'monthly_package'    => 'nullable|numeric',
            'package_cash'       => 'nullable|numeric',
            'package_travel'     => 'nullable|numeric',
            'package_incentive'  => 'nullable|numeric',
            'package_cell_phone' => 'nullable|numeric',

            // ── Autre ──
            'complex_name' => 'nullable|string|max:255',
            'unit_number'  => 'nullable|string|max:50',

            // ── Compte (Admin) ──
            'role'   => 'nullable|string|exists:roles,name',
            'status' => 'nullable|integer|in:0,1',
            'email'  => 'nullable|email|max:255|unique:users,email,' . $userId,

            // ── Fichiers ──
            'profile_pic'        => 'nullable|image|mimes:jpeg,jpg,png,webp,gif|max:10240',

            // ── Company Units ──
            'company_ids'   => 'nullable|array',
            'company_ids.*' => 'integer|exists:company_units,id',


        ];
    }

    public function prepareForValidation(): void
    {
        $nullableFields = [
            'drivers_licence_exp_date',
            'proof_drive_permit_exp_date',
            'date_of_birth',
            'date_joined',
            'date_left',
            'med_start_date',
            'provident_start_date',
            'middle_name',
            'maiden_name',
            'aka',
            'initial',
            'known_as',
            'title',
            'gender',
            'marital_status',
            'ethnicity',
            'citizenship',
            'cell_number',
            'phone_number',
            'alternate_number',
            'email_person',
            'res_address',
            'res_suburb',
            'res_city',
            'res_postal_code',
            'res_province_id',
            'res_country_id',
            'next_of_kin',
            'next_of_kin_number',
            'next_of_kin_work_number',
            'income_tax_number',
            'tax_office',
            'account_type',
            'account_holder_name',
            'bank_name',
            'branch_name',
            'account_number',
            'med_split',
            'med_plan_name',
            'med_dep_spouse',
            'med_dep_adult',
            'med_dep_kids',
            'med_amount',
            'package_med_aid',
            'provident_name',
            'provident_amount',
            'provident_fund_product',
            'employee_number',
            'position_id',
            'start_time',
            'end_time',
            'leave_profile',
            'role_id',
            'manager_id',
            'second_manager_id',
            'employment_type',
            'occupational_level',
            'job_function',
            'disabled',
            'nature_of_disability',
            'can_drive',
            'drivers_licence_number',
            'drivers_licence_code',
            'own_transport',
            'package_components',
            'monthly_package',
            'package_cash',
            'package_travel',
            'package_incentive',
            'package_cell_phone',
            'complex_name',
            'unit_number',
            'role',
            'status',
            'password',
        ];

        $merged = [];
        foreach ($nullableFields as $field) {
            $val = $this->input($field);
            if ($val === 'null' || $val === '') {
                $merged[$field] = null;
            }
        }

        if (!empty($merged)) {
            $this->merge($merged);
        }
    }
}
