<?php

namespace App\Modules\HR\Recruitment\Http\Controllers;

use App\Modules\HR\Recruitment\Actions\Vacancy\CloseVacancyAction;
use App\Modules\HR\Recruitment\Actions\Vacancy\CreateVacancyAction;
use App\Modules\HR\Recruitment\Actions\Vacancy\PublishAction;
use App\Modules\HR\Recruitment\Actions\Vacancy\TransitionToExternalAction;
use App\Modules\HR\Recruitment\Enums\RequisitionStatus;
use App\Modules\HR\Recruitment\Enums\VacancyStatus;
use App\Modules\HR\Recruitment\Enums\VacancyType;
use App\Modules\HR\Recruitment\Enums\VacancyVisibility;
use App\Modules\HR\Recruitment\Http\Requests\Vacancy\StoreVacancyRequest;
use App\Modules\HR\Recruitment\Http\Resources\VacancyListResource;
use App\Modules\HR\Recruitment\Http\Resources\VacancyResource;
use App\Modules\HR\Recruitment\Models\Requisition;
use App\Modules\HR\Recruitment\Models\Vacancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VacancyController
{
    public function index(Request $request): Response
    {
        $vacancies = Vacancy::with(['createdBy'])
            ->when(
                $request->filled('status'),
                fn ($q) => $q->where('status', $request->input('status'))
            )
            ->when(
                $request->filled('visibility'),
                fn ($q) => $q->where('visibility', $request->input('visibility'))
            )
            ->when(
                $request->filled('search'),
                fn ($q) => $q->where(function ($sub) use ($request) {
                    $term = "%{$request->input('search')}%";
                    $sub->where('title', 'ilike', $term)
                        ->orWhere('department', 'ilike', $term)
                        ->orWhere('vacancy_number', 'ilike', $term);
                })
            )
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'total'    => Vacancy::count(),
            'draft'    => Vacancy::where('status', VacancyStatus::Draft->value)->count(),
            'internal' => Vacancy::where('status', VacancyStatus::InternalOnly->value)->count(),
            'external' => Vacancy::where('status', VacancyStatus::External->value)->count(),
            'filled'   => Vacancy::where('status', VacancyStatus::Filled->value)->count(),
        ];

        $statusOptions = collect(VacancyStatus::cases())->map(fn ($s) => [
            'value' => $s->value,
            'label' => $s->label(),
            'color' => $s->color(),
        ]);

        $visibilityOptions = collect(VacancyVisibility::cases())->map(fn ($v) => [
            'value'       => $v->value,
            'label'       => $v->label(),
            'color'       => $v->color(),
            'description' => $v->description(),
        ]);

        return Inertia::render('Modules/Recruitment/Vacancies/Index', [
            'vacancies'         => VacancyListResource::collection($vacancies),
            'filters'           => $request->only('status', 'visibility', 'search'),
            'stats'             => $stats,
            'statusOptions'     => $statusOptions,
            'visibilityOptions' => $visibilityOptions,
        ]);
    }

    public function create(): Response
    {
        $jobTypes = collect(VacancyType::cases())->map(fn ($t) => [
            'value' => $t->value,
            'label' => $t->label(),
        ]);

        $visibilityOptions = collect(VacancyVisibility::cases())->map(fn ($v) => [
            'value'       => $v->value,
            'label'       => $v->label(),
            'color'       => $v->color(),
            'description' => $v->description(),
        ]);

        $requisitions = Requisition::where('status', RequisitionStatus::Approved->value)
            ->orderBy('title')
            ->get(['id', 'title', 'requisition_number', 'department'])
            ->map(fn ($r) => [
                'id'                 => $r->id,
                'title'              => $r->title,
                'requisition_number' => $r->requisition_number,
                'department'         => $r->department,
            ]);

        return Inertia::render('Modules/Recruitment/Vacancies/Create', [
            'jobTypes'          => $jobTypes,
            'visibilityOptions' => $visibilityOptions,
            'requisitions'      => $requisitions,
        ]);
    }

    public function store(StoreVacancyRequest $request, CreateVacancyAction $action): RedirectResponse
    {
        $vacancy = $action->execute($request->validated(), $request->user()->id);

        return redirect()
            ->route('recruitment.vacancies.show', $vacancy)
            ->with('success', 'Vacancy created successfully.');
    }

    public function show(Vacancy $vacancy): Response
    {
        $vacancy->load(['requisition', 'createdBy']);

        $canPublish = in_array($vacancy->status, [VacancyStatus::Draft, VacancyStatus::OnHold]);

        return Inertia::render('Modules/Recruitment/Vacancies/Show', [
            'vacancy'    => (new VacancyResource($vacancy))->resolve(),
            'canPublish' => $canPublish,
        ]);
    }

    public function publish(
        Vacancy $vacancy,
        PublishAction $action,
        Request $request,
    ): RedirectResponse {
        $request->validate([
            'internal_deadline' => [
                'required_if:visibility,internal_first_then_external',
                'nullable',
                'date',
                'after:today',
            ],
        ]);

        $action->execute($vacancy, $request->user()->id, $request->only('internal_deadline'));

        return back()->with('success', 'Vacancy published successfully.');
    }

    public function close(
        Vacancy $vacancy,
        CloseVacancyAction $action,
        Request $request,
    ): RedirectResponse {
        $request->validate([
            'reason' => ['nullable', 'string', 'in:filled,cancelled,withdrawn'],
        ]);

        $action->execute($vacancy, $request->user()->id, $request->input('reason', 'filled'));

        return back()->with('success', 'Vacancy closed.');
    }

    public function transition(Vacancy $vacancy, TransitionToExternalAction $action): RedirectResponse
    {
        $action->execute($vacancy);

        return back()->with('success', 'Vacancy transitioned to external successfully.');
    }
}
