<?php

namespace App\Modules\HR\Offboarding\Http\Controllers;

use App\Modules\Assets\Models\Assets;
use App\Modules\HR\Offboarding\Models\OffboardingClearance;
use App\Modules\HR\Offboarding\Models\OffboardingExitInterview;
use App\Modules\HR\Offboarding\Models\OffboardingPlan;
use App\Modules\HR\Offboarding\Models\OffboardingTask;
use App\Modules\HR\Offboarding\Models\OffboardingTemplate;
use App\Modules\HR\Hr\Models\HRPerson;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OffboardingController
{
    private const DEPARTMENTS = ['hr', 'it', 'finance', 'facilities', 'manager'];

    public function index(Request $request): Response
    {
        $plans = OffboardingPlan::with(['hrPerson'])
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('search'), fn ($q, $s) =>
                $q->whereHas('hrPerson', fn ($cq) =>
                    $cq->where('first_name', 'ilike', "%{$s}%")
                       ->orWhere('surname', 'ilike', "%{$s}%")
                       ->orWhere('email', 'ilike', "%{$s}%")
                )
            )
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'total'     => OffboardingPlan::count(),
            'active'    => OffboardingPlan::where('status', 'active')->count(),
            'completed' => OffboardingPlan::where('status', 'completed')->count(),
            'pending'   => OffboardingPlan::where('status', 'pending')->count(),
        ];

        $templates = OffboardingTemplate::select('id', 'title', 'notice_period_days')
            ->orderBy('title')
            ->get();

        $persons = HRPerson::query()
            ->select('id', 'first_name', 'surname', 'email')
            ->orderBy('first_name')
            ->orderBy('surname')
            ->get()
            ->map(fn ($p) => [
                'id'    => $p->id,
                'name'  => trim("{$p->first_name} {$p->surname}") ?: ($p->email ?? 'Unknown'),
                'email' => $p->email ?? '',
            ]);

        return Inertia::render('Modules/Offboarding/Index', [
            'plans'     => $plans->through(fn ($plan) => [
                'id'               => $plan->id,
                'uuid'             => $plan->uuid,
                'status'           => [
                    'value' => $plan->status->value,
                    'label' => $plan->status->label(),
                    'color' => $plan->status->color(),
                ],
                'termination_type' => $plan->termination_type,
                'last_working_day' => $plan->last_working_day?->toDateString(),
                'completion'       => $plan->completionPercentage(),
                'hr_person'        => [
                    'id'        => $plan->hrPerson?->id,
                    'candidate' => $plan->hrPerson
                        ? [
                            'full_name' => trim("{$plan->hrPerson->first_name} {$plan->hrPerson->surname}") ?: ($plan->hrPerson->email ?? 'Unknown'),
                            'email'     => $plan->hrPerson->email ?? '',
                        ]
                        : null,
                ],
            ]),
            'filters'   => $request->only('status', 'search'),
            'stats'     => $stats,
            'templates' => $templates,
            'persons'   => $persons,
        ]);
    }

    public function store(Request $request, HrPerson $person)
    {
        $validated = $request->validate([
            'template_id'      => ['nullable', 'integer', 'exists:offboarding_templates,id'],
            'termination_type' => ['required', 'string', 'in:resignation,retrenchment,dismissal,retirement,contract_end,death'],
            'resignation_date' => ['nullable', 'date'],
            'last_working_day' => ['nullable', 'date'],
            'notes'            => ['nullable', 'string'],
        ]);

        // Atomic: the duplicate-check + plan + clearances + tasks stand or fall
        // together (previously a mid-loop failure left a half-built plan, and two
        // concurrent submits could both pass the exists() check).
        $plan = \Illuminate\Support\Facades\DB::transaction(function () use ($validated, $person, $request) {

        if (OffboardingPlan::where('hr_person_id', $person->id)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->lockForUpdate()
            ->exists()) {
            return null;
        }

        $plan = OffboardingPlan::create([
            'hr_person_id'     => $person->id,
            'template_id'      => $validated['template_id'] ?? null,
            'termination_type' => $validated['termination_type'],
            'resignation_date' => $validated['resignation_date'] ?? null,
            'last_working_day' => $validated['last_working_day'] ?? null,
            'status'           => 'active',
            'notes'            => $validated['notes'] ?? null,
            'created_by'       => $request->user()->id,
        ]);

        // Seed clearances for all departments
        foreach (self::DEPARTMENTS as $dept) {
            OffboardingClearance::create([
                'plan_id'    => $plan->id,
                'department' => $dept,
                'status'     => 'pending',
            ]);
        }

        // Copy template tasks
        if (!empty($validated['template_id'])) {
            $template = OffboardingTemplate::with('templateTasks')->find($validated['template_id']);
            if ($template) {
                foreach ($template->templateTasks as $index => $templateTask) {
                    $dueDate = null;
                    if ($plan->last_working_day) {
                        $dueDate = $plan->last_working_day->copy()
                            ->subDays($templateTask->days_before_last_day)
                            ->toDateString();
                    }
                    OffboardingTask::create([
                        'plan_id'     => $plan->id,
                        'title'       => $templateTask->title,
                        'description' => $templateTask->description,
                        'category'    => $templateTask->category,
                        'due_date'    => $dueDate,
                        'status'      => 'pending',
                        'sort_order'  => $index,
                    ]);
                }
            }
        }

        return $plan;
        });

        if (! $plan) {
            return back()->withErrors(['hr_person_id' => 'An active offboarding plan already exists for this person.']);
        }

        return redirect()->route('offboarding.persons.plans.show', [$person, $plan]);
    }

    public function show(HrPerson $person, OffboardingPlan $plan): Response
    {
        abort_unless($plan->hr_person_id === $person->id, 404);

        $plan->load(['hrPerson', 'tasks', 'exitInterview.conductedBy', 'template', 'clearances.signedOffBy']);

        // Load currently assigned assets via the HR person's linked user account
        $currentAssets = [];
        if ($plan->hrPerson?->user_id) {
            $currentAssets = Assets::where('user_id', $plan->hrPerson->user_id)
                ->where('asset_status', 'active')
                ->with('location', 'category')
                ->get()
                ->map(fn ($a) => [
                    'id'       => $a->id,
                    'name'     => $a->name,
                    'tag'      => $a->asset_tag ?? $a->asset_number,
                    'category' => $a->category?->name,
                    'location' => $a->location?->name,
                ])->values()->all();
        }

        $tasksByCategory = $plan->tasks->groupBy('category')->map(fn ($tasks) => $tasks->map(fn ($task) => [
            'id'           => $task->id,
            'title'        => $task->title,
            'description'  => $task->description,
            'category'     => $task->category,
            'due_date'     => $task->due_date?->toDateString(),
            'completed_at' => $task->completed_at?->toDateString(),
            'notes'        => $task->notes,
            'sort_order'   => $task->sort_order,
            'status'       => [
                'value' => $task->status->value,
                'label' => $task->status->label(),
                'color' => $task->status->color(),
            ],
        ])->values());

        $exitInterview = $plan->exitInterview ? [
            'id'                  => $plan->exitInterview->id,
            'uuid'                => $plan->exitInterview->uuid,
            'interview_date'      => $plan->exitInterview->interview_date?->toDateString(),
            'reason_for_leaving'  => $plan->exitInterview->reason_for_leaving,
            'satisfaction_score'  => $plan->exitInterview->satisfaction_score,
            'would_recommend'     => $plan->exitInterview->would_recommend,
            'strengths'           => $plan->exitInterview->strengths,
            'improvements'        => $plan->exitInterview->improvements,
            'additional_feedback' => $plan->exitInterview->additional_feedback,
            'is_anonymous'        => $plan->exitInterview->is_anonymous,
            'conducted_by'        => $plan->exitInterview->conductedBy?->name,
        ] : null;

        $clearances = $plan->clearances->map(fn ($c) => [
            'id'            => $c->id,
            'department'    => $c->department,
            'status'        => $c->status,
            'signed_off_by' => $c->signedOffBy?->name,
            'signed_off_at' => $c->signed_off_at?->toDateString(),
            'notes'         => $c->notes,
        ])->values();

        return Inertia::render('Modules/Offboarding/Show', [
            'plan' => [
                'id'                   => $plan->id,
                'uuid'                 => $plan->uuid,
                'status'               => [
                    'value' => $plan->status->value,
                    'label' => $plan->status->label(),
                    'color' => $plan->status->color(),
                ],
                'termination_type'     => $plan->termination_type,
                'resignation_date'     => $plan->resignation_date?->toDateString(),
                'last_working_day'     => $plan->last_working_day?->toDateString(),
                'notes'                => $plan->notes,
                'completion'           => $plan->completionPercentage(),
                'template'             => $plan->template ? ['id' => $plan->template->id, 'title' => $plan->template->title] : null,
                'rehire_eligible'      => $plan->rehire_eligible,
                'rehire_notes'         => $plan->rehire_notes,
                'counteroffer_made'    => $plan->counteroffer_made,
                'counteroffer_outcome' => $plan->counteroffer_outcome,
                'hr_person'            => [
                    'id'        => $plan->hrPerson?->id,
                    'candidate' => $plan->hrPerson
                        ? [
                            'full_name' => trim("{$plan->hrPerson->first_name} {$plan->hrPerson->surname}") ?: ($plan->hrPerson->email ?? 'Unknown'),
                            'email'     => $plan->hrPerson->email ?? '',
                        ]
                        : null,
                ],
            ],
            'tasks_by_category' => $tasksByCategory,
            'exit_interview'    => $exitInterview,
            'clearances'        => $clearances,
            'current_assets'    => $currentAssets,
        ]);
    }

    public function completeTask(Request $request, HrPerson $person, OffboardingPlan $plan, OffboardingTask $task)
    {
        abort_unless($plan->hr_person_id === $person->id, 404);
        abort_unless($task->plan_id === $plan->id, 404);
        abort_unless(in_array($plan->status->value, ['active']), 403, 'Tasks cannot be completed on a non-active plan.');

        $skip = $request->boolean('skip');

        $task->update([
            'status'       => $skip ? 'skipped' : 'completed',
            'completed_by' => $request->user()->id,
            'completed_at' => now(),
        ]);

        if ($plan->isFullyComplete()) {
            // Finalize performs the ACTUAL termination (deactivate person, revoke
            // login/roles/sessions, reclaim assets) and marks the plan completed —
            // previously completion only flipped the status and the departed
            // employee kept full access.
            app(\App\Modules\HR\Offboarding\Services\OffboardingFinalizeService::class)
                ->finalize($plan, $request->user()->id);
        }

        return back();
    }

    /**
     * Explicit finalisation for plans that can't auto-complete (e.g. created with
     * no tasks). Runs the same terminal step: deactivate, revoke access, reclaim
     * assets, complete the plan.
     */
    public function finalize(Request $request, HrPerson $person, OffboardingPlan $plan)
    {
        abort_unless($plan->hr_person_id === $person->id, 404);

        $done = app(\App\Modules\HR\Offboarding\Services\OffboardingFinalizeService::class)
            ->finalize($plan, $request->user()->id);

        return $done
            ? back()->with('success', 'Offboarding finalised — access revoked and assets reclaimed.')
            : back()->withErrors(['plan' => 'Only an active plan can be finalised.']);
    }

    public function storeExitInterview(Request $request, HrPerson $person, OffboardingPlan $plan)
    {
        abort_unless($plan->hr_person_id === $person->id, 404);

        $validated = $request->validate([
            'interview_date'      => ['nullable', 'date'],
            'reason_for_leaving'  => ['required', 'string', 'in:better_opportunity,salary,work_environment,management,relocation,personal,retirement,contract_end,other'],
            'satisfaction_score'  => ['nullable', 'integer', 'min:1', 'max:5'],
            'would_recommend'     => ['nullable', 'boolean'],
            'strengths'           => ['nullable', 'string'],
            'improvements'        => ['nullable', 'string'],
            'additional_feedback' => ['nullable', 'string'],
            'is_anonymous'        => ['boolean'],
        ]);

        OffboardingExitInterview::updateOrCreate(
            ['plan_id' => $plan->id],
            array_merge($validated, ['conducted_by' => $request->user()->id])
        );

        return back();
    }

    public function signOffClearance(Request $request, HrPerson $person, OffboardingPlan $plan, OffboardingClearance $clearance)
    {
        abort_unless($plan->hr_person_id === $person->id, 404);
        abort_unless($clearance->plan_id === $plan->id, 404);

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        // Separation of duties: the departing employee cannot sign off their own
        // clearances, and a decided clearance can't be silently overwritten.
        abort_if($request->user()->person?->id === $plan->hr_person_id, 403, 'You cannot sign off your own clearance.');
        abort_if($clearance->status === 'cleared', 422, 'This clearance is already signed off.');

        $clearance->update([
            'status'        => 'cleared',
            'signed_off_by' => $request->user()->id,
            'signed_off_at' => now(),
            'notes'         => $validated['notes'] ?? null,
        ]);

        return back()->with('success', 'Clearance signed off.');
    }

    public function updatePlanDetails(Request $request, HrPerson $person, OffboardingPlan $plan)
    {
        abort_unless($plan->hr_person_id === $person->id, 404);

        $validated = $request->validate([
            'rehire_eligible'      => ['nullable', 'boolean'],
            'rehire_notes'         => ['nullable', 'string', 'max:1000'],
            'counteroffer_made'    => ['nullable', 'boolean'],
            'counteroffer_outcome' => ['nullable', 'string', 'in:accepted,declined'],
        ]);

        $plan->update($validated);

        return back()->with('success', 'Plan details updated.');
    }
}
