<?php

namespace App\Modules\HR\Recruitment\Http\Controllers;

use App\Modules\HR\Recruitment\Actions\Requisition\CancelRequisitionAction;
use App\Modules\HR\Recruitment\Actions\Requisition\ConvertRequisitionToVacancyAction;
use App\Modules\HR\Recruitment\Actions\Requisition\CreateRequisitionAction;
use App\Modules\HR\Recruitment\Actions\Requisition\SubmitRequisitionForApprovalAction;
use App\Modules\HR\Recruitment\Enums\RequisitionStatus;
use App\Modules\HR\Recruitment\Enums\VacancyType;
use App\Modules\HR\Recruitment\Http\Requests\Requisition\CreateRequisitionRequest;
use App\Modules\HR\Recruitment\Http\Requests\Requisition\UpdateRequisitionRequest;
use App\Modules\HR\Recruitment\Http\Resources\RequisitionListResource;
use App\Modules\HR\Recruitment\Http\Resources\RequisitionResource;
use App\Modules\HR\Recruitment\Models\Requisition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RequisitionController
{
    public function index(Request $request): Response
    {
        $requisitions = Requisition::with(['requestedBy'])
            ->when(
                $request->filled('status'),
                fn ($q) => $q->where('status', $request->input('status'))
            )
            ->when(
                $request->filled('search'),
                fn ($q) => $q->where(function ($sub) use ($request) {
                    $term = "%{$request->input('search')}%";
                    $sub->where('title', 'ilike', $term)
                        ->orWhere('department', 'ilike', $term)
                        ->orWhere('requisition_number', 'ilike', $term);
                })
            )
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'total'    => Requisition::count(),
            'draft'    => Requisition::where('status', RequisitionStatus::Draft->value)->count(),
            'pending'  => Requisition::where('status', RequisitionStatus::PendingApproval->value)->count(),
            'approved' => Requisition::where('status', RequisitionStatus::Approved->value)->count(),
        ];

        $statusOptions = collect(RequisitionStatus::cases())->map(fn ($s) => [
            'value' => $s->value,
            'label' => $s->label(),
            'color' => $s->color(),
        ]);

        return Inertia::render('Modules/Recruitment/Requisitions/Index', [
            'requisitions'  => RequisitionListResource::collection($requisitions),
            'filters'       => $request->only('status', 'search'),
            'stats'         => $stats,
            'statusOptions' => $statusOptions,
        ]);
    }

    public function create(): Response
    {
        $jobTypes = collect(VacancyType::cases())->map(fn ($t) => [
            'value' => $t->value,
            'label' => $t->label(),
        ]);

        return Inertia::render('Modules/Recruitment/Requisitions/Create', [
            'jobTypes' => $jobTypes,
        ]);
    }

    public function store(CreateRequisitionRequest $request, CreateRequisitionAction $action): RedirectResponse
    {
        $requisition = $action->execute($request->validated(), $request->user()->id);

        return redirect()
            ->route('recruitment.requisitions.show', $requisition)
            ->with('success', 'Requisition created.');
    }

    public function show(Requisition $requisition): Response
    {
        $requisition->load(['requestedBy', 'approvedBy', 'rejectedBy', 'vacancy']);

        $jobTypes = collect(VacancyType::cases())->map(fn ($t) => [
            'value' => $t->value,
            'label' => $t->label(),
        ]);

        return Inertia::render('Modules/Recruitment/Requisitions/Show', [
            'requisition' => (new RequisitionResource($requisition))->resolve(),
            'jobTypes'    => $jobTypes,
        ]);
    }

    public function edit(Requisition $requisition): Response
    {
        $requisition->load(['requestedBy']);

        $jobTypes = collect(VacancyType::cases())->map(fn ($t) => [
            'value' => $t->value,
            'label' => $t->label(),
        ]);

        return Inertia::render('Modules/Recruitment/Requisitions/Edit', [
            'requisition' => (new RequisitionResource($requisition))->resolve(),
            'jobTypes'    => $jobTypes,
        ]);
    }

    public function update(
        UpdateRequisitionRequest $request,
        Requisition $requisition,
    ): RedirectResponse {
        if ($requisition->status !== RequisitionStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => 'Only draft requisitions can be updated.',
            ]);
        }

        $requisition->update($request->validated());

        return back()->with('success', 'Requisition updated.');
    }

    public function destroy(Requisition $requisition): RedirectResponse
    {
        if (!in_array($requisition->status, [RequisitionStatus::Draft, RequisitionStatus::Cancelled])) {
            throw ValidationException::withMessages([
                'status' => 'Only draft or cancelled requisitions can be deleted.',
            ]);
        }

        $requisition->delete();

        return redirect()
            ->route('recruitment.requisitions.index')
            ->with('success', 'Requisition deleted.');
    }

    public function submit(
        Requisition $requisition,
        SubmitRequisitionForApprovalAction $action,
        Request $request,
    ): RedirectResponse {
        $action->execute($requisition, $request->user()->id);

        return back()->with('success', 'Requisition submitted for approval.');
    }

    public function cancel(
        Requisition $requisition,
        CancelRequisitionAction $action,
        Request $request,
    ): RedirectResponse {
        $action->execute($requisition, $request->user()->id);

        return back()->with('success', 'Requisition cancelled.');
    }

    public function convert(
        Requisition $requisition,
        ConvertRequisitionToVacancyAction $action,
        Request $request,
    ): RedirectResponse {
        $vacancy = $action->execute($requisition, $request->user()->id);

        return redirect()
            ->route('recruitment.vacancies.show', $vacancy)
            ->with('success', 'Requisition converted to vacancy.');
    }
}
