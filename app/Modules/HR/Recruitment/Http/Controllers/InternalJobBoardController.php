<?php

namespace App\Modules\HR\Recruitment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Recruitment\Enums\VacancyStatus;
use App\Modules\HR\Recruitment\Enums\VacancyVisibility;
use App\Modules\HR\Recruitment\Http\Resources\InternalVacancyResource;
use App\Modules\HR\Recruitment\Models\Vacancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InternalJobBoardController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Vacancy::query()
            ->where(function ($q) {
                $q->where('status', VacancyStatus::InternalOnly)
                  ->orWhere(function ($q2) {
                      $q2->where('status', VacancyStatus::Published)
                         ->where('visibility', VacancyVisibility::InternalOnly);
                  });
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->search;
                $q->where(function ($inner) use ($search) {
                    $inner->whereRaw('LOWER(title) LIKE ?', ['%' . strtolower($search) . '%'])
                          ->orWhereRaw('LOWER(department) LIKE ?', ['%' . strtolower($search) . '%']);
                });
            })
            ->when($request->filled('department'), fn ($q) => $q->where('department', $request->department))
            ->orderByDesc('published_internally_at')
            ->orderByDesc('created_at');

        $vacancies = $query->paginate(12)->withQueryString();

        return Inertia::render('Modules/Recruitment/Internal/JobBoard', [
            'vacancies' => InternalVacancyResource::collection($vacancies),
            'filters'   => $request->only(['search', 'department']),
        ]);
    }

    public function apply(string $vacancyUuid): RedirectResponse
    {
        $vacancy = Vacancy::where('uuid', $vacancyUuid)->firstOrFail();

        return redirect()->route('recruitment.vacancies.show', $vacancy)
            ->with('info', 'Review the vacancy details and submit your application below.');
    }
}
