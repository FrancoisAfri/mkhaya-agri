<?php

namespace App\Modules\HR\Recruitment\Actions\Requisition;

use App\Modules\HR\Recruitment\Enums\RequisitionStatus;
use App\Modules\HR\Recruitment\Enums\VacancyStatus;
use App\Modules\HR\Recruitment\Models\Requisition;
use App\Modules\HR\Recruitment\Models\Vacancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConvertRequisitionToVacancyAction
{
    public function execute(Requisition $requisition, int $createdBy): Vacancy
    {
        if (!$requisition->status->canConvert()) {
            throw ValidationException::withMessages([
                'status' => "Only approved requisitions can be converted to a vacancy (current: {$requisition->status->label()}).",
            ]);
        }

        return DB::transaction(function () use ($requisition, $createdBy) {
            $vacancy = Vacancy::create([
                'vacancy_number'          => Vacancy::generateVacancyNumber(),
                'requisition_id'          => $requisition->id,
                'title'                   => $requisition->title,
                'department'              => $requisition->department,
                'position_id'             => $requisition->position_id,
                'job_type'                => $requisition->job_type->value,
                'headcount'               => $requisition->headcount,
                'job_description'         => $requisition->job_description ?? '',
                'required_qualifications' => $requisition->required_qualifications,
                'required_skills'         => $requisition->required_skills,
                'salary_band_min'         => $requisition->salary_band_min,
                'salary_band_max'         => $requisition->salary_band_max,
                'status'                  => VacancyStatus::Draft->value,
                'created_by'              => $createdBy,
            ]);

            $requisition->update([
                'status'       => RequisitionStatus::Converted->value,
                'converted_at' => now(),
            ]);

            return $vacancy;
        });
    }
}
