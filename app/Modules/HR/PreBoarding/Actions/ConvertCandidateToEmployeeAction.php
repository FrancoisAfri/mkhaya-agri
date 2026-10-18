<?php

namespace App\Modules\HR\PreBoarding\Actions;

use App\Modules\HR\Contracts\Models\Contract;
use App\Modules\HR\PreBoarding\Enums\PreBoardingTaskType;
use App\Modules\HR\PreBoarding\Models\HrPerson;
use App\Modules\HR\PreBoarding\Models\PreBoardingPlan;
use App\Modules\HR\PreBoarding\Models\PreBoardingTask;
use Illuminate\Support\Facades\DB;

class ConvertCandidateToEmployeeAction
{
    public function execute(Contract $contract, int $createdBy): HrPerson
    {
        // Idempotent: return existing HrPerson if this contract was already
        // converted (withTrashed so a soft-deleted conversion doesn't slip past
        // the guard and explode on the unique contract_id index).
        $existing = HrPerson::withTrashed()->where('contract_id', $contract->id)->first();
        if ($existing !== null) {
            if ($existing->trashed()) {
                $existing->restore();
            }
            return $existing;
        }

        // Retry on employee-number collision: the number is derived from a
        // per-tenant count (RLS-scoped), and two concurrent conversions can
        // compute the same one — the per-tenant unique key is the arbiter.
        for ($attempt = 0; ; $attempt++) {
            try {
                return DB::transaction(function () use ($contract, $createdBy, $attempt): HrPerson {
                    // Per-tenant sequential number (the old max(id) used the GLOBAL
                    // autoincrement and produced numbers like EMP04211 for a
                    // company's very first hire).
                    $sequence       = HrPerson::withTrashed()->count() + 1 + $attempt;
                    $employeeNumber = 'EMP' . str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);

                    $hrPerson = HrPerson::create([
                        'candidate_id'    => $contract->candidate_id,
                        'contract_id'     => $contract->id,
                        'employee_number' => $employeeNumber,
                        'started_on'      => now()->toDateString(),
                        'created_by'      => $createdBy,
                    ]);

                    $plan = PreBoardingPlan::create([
                        'hr_person_id' => $hrPerson->id,
                        'due_date'     => now()->addDays(7)->toDateString(),
                        'created_by'   => $createdBy,
                    ]);

                    $this->seedDefaultTasks($plan);

                    return $hrPerson;
                });
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                if ($attempt >= 5) {
                    throw $e;
                }
                // fall through and retry with the next sequence number
            }
        }
    }

    private function seedDefaultTasks(PreBoardingPlan $plan): void
    {
        // [type, title, owner_role]
        $defaults = [
            [PreBoardingTaskType::DocumentSubmission, 'Submit ID / Passport copy',           'hr'],
            [PreBoardingTaskType::DocumentSubmission, 'Submit proof of qualifications',       'hr'],
            [PreBoardingTaskType::BackgroundCheck,    'Background / credit check consent',    'hr'],
            [PreBoardingTaskType::FormCompletion,     'Complete payroll details form',         'finance'],
            [PreBoardingTaskType::SystemAccess,       'IT account provisioning request',       'it'],
            [PreBoardingTaskType::WelcomePack,        'Review welcome pack & handbook',        'hr'],
        ];

        foreach ($defaults as $order => [$type, $title, $ownerRole]) {
            PreBoardingTask::create([
                'pre_boarding_plan_id' => $plan->id,
                'type'                 => $type->value,
                'title'                => $title,
                'owner_role'           => $ownerRole,
                'sort_order'           => $order,
            ]);
        }
    }
}
