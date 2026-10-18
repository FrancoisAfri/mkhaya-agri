<?php

namespace App\Modules\HR\Onboarding\Http\Controllers;

use App\Modules\HR\Onboarding\Actions\CaptureProbationReviewAction;
use App\Modules\HR\Onboarding\Actions\CreateProbationPeriodAction;
use App\Modules\HR\Onboarding\Http\Resources\ProbationPeriodResource;
use App\Modules\HR\Onboarding\Models\ProbationPeriod;
use App\Modules\HR\PreBoarding\Models\HrPerson;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProbationController
{
    public function show(HrPerson $person)
    {
        $probation = $person->probationPeriod()->with('reviews')->first();

        return response()->json([
            'probation' => $probation ? new ProbationPeriodResource($probation) : null,
        ]);
    }

    public function store(Request $request, HrPerson $person, CreateProbationPeriodAction $action)
    {
        $validated = $request->validate([
            'start_date'      => ['required', 'date'],
            'duration_months' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        $probation = $action->execute($person, $validated, $request->user()->id);

        return response()->json([
            'probation' => (new ProbationPeriodResource($probation))->resolve(),
        ], 201);
    }

    public function review(
        Request $request,
        HrPerson $person,
        ProbationPeriod $probation,
        CaptureProbationReviewAction $action,
    ) {
        abort_unless($probation->hr_person_id === $person->id, 404);

        $validated = $request->validate([
            'review_date'          => ['required', 'date'],
            'review_type'          => ['required', 'string', 'in:mid,final,extension'],
            'performance_score'    => ['nullable', 'integer', 'min:1', 'max:5'],
            'manager_feedback'     => ['nullable', 'string'],
            'employee_feedback'    => ['nullable', 'string'],
            'areas_of_improvement' => ['nullable', 'string'],
            'recommendation'       => ['nullable', 'string', 'in:confirm,extend,dismiss'],
            'extension_months'     => ['nullable', 'integer', 'min:1', 'max:6'],
        ]);

        $review = $action->execute($probation, $validated, $request->user()->id);

        $probation->load('reviews');

        return response()->json([
            'review'    => (new \App\Modules\HR\Onboarding\Http\Resources\ProbationReviewResource($review))->resolve(),
            'probation' => (new ProbationPeriodResource($probation))->resolve(),
        ]);
    }

    public function close(Request $request, HrPerson $person, ProbationPeriod $probation)
    {
        abort_unless($probation->hr_person_id === $person->id, 404);
        abort_unless(in_array($probation->status->value, ['active', 'extended']), 422);

        $validated = $request->validate([
            'outcome'          => ['required', 'string', 'in:confirmed,extended,dismissed'],
            'outcome_notes'    => ['nullable', 'string'],
            'extension_months' => ['required_if:outcome,extended', 'nullable', 'integer', 'min:1', 'max:6'],
        ]);

        $status = match($validated['outcome']) {
            'confirmed' => 'completed',
            'dismissed' => 'failed',
            'extended'  => 'extended',
        };

        $updates = [
            'status'           => $status,
            'outcome'          => $validated['outcome'],
            'outcome_notes'    => $validated['outcome_notes'] ?? null,
            'next_review_date' => null,
        ];

        if ($validated['outcome'] === 'extended' && !empty($validated['extension_months'])) {
            $baseDate = $probation->extended_end_date ?? $probation->end_date ?? now();
            $newEnd   = $baseDate->copy()->addMonths((int) $validated['extension_months']);
            $updates['extended_end_date'] = $newEnd->toDateString();
            $updates['next_review_date']  = $newEnd->copy()->subWeeks(2)->toDateString();
        }

        $probation->update($updates);

        return response()->json([
            'probation' => (new ProbationPeriodResource($probation->fresh()))->resolve(),
        ]);
    }
}
