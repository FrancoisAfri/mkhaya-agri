<?php

namespace App\Modules\PerformanceAppraisal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PerformanceAppraisal\Models\PaRatingScale;
use App\Modules\PerformanceAppraisal\Models\PaRatingValue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PaRatingScaleController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Modules/PerformanceAppraisal/RatingScales/Index', [
            'scales' => PaRatingScale::with('values')
                ->orderBy('name')
                ->get()
                ->map(fn($s) => [
                    'id'          => $s->id,
                    'name'        => $s->name,
                    'description' => $s->description,
                    'min_score'   => $s->min_score,
                    'max_score'   => $s->max_score,
                    'is_default'  => $s->is_default,
                    'is_active'   => $s->is_active,
                    'values'      => $s->values->map(fn($v) => [
                        'id'          => $v->id,
                        'label'       => $v->label,
                        'value'       => $v->value,
                        'description' => $v->description,
                        'color'       => $v->color,
                        'sort_order'  => $v->sort_order,
                    ]),
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'min_score'   => ['required', 'integer', 'min:0'],
            'max_score'   => ['required', 'integer', 'gt:min_score'],
            'is_default'  => ['boolean'],
            'is_active'   => ['boolean'],
        ]);

        if (!empty($data['is_default'])) {
            PaRatingScale::where('is_default', true)->update(['is_default' => false]);
        }

        PaRatingScale::create($data);

        return back()->with('success', 'Rating scale created.');
    }

    public function update(Request $request, PaRatingScale $scale): RedirectResponse
    {
        $data = $request->validate([
            'name'        => ['sometimes', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'min_score'   => ['integer', 'min:0'],
            'max_score'   => ['integer'],
            'is_default'  => ['boolean'],
            'is_active'   => ['boolean'],
        ]);

        if (!empty($data['is_default'])) {
            PaRatingScale::where('is_default', true)->where('id', '!=', $scale->id)->update(['is_default' => false]);
        }

        $scale->update($data);

        return back()->with('success', 'Rating scale updated.');
    }

    public function destroy(PaRatingScale $scale): RedirectResponse
    {
        $scale->delete();

        return back()->with('success', 'Rating scale deleted.');
    }

    // ── Values ────────────────────────────────────────────────────────────────

    public function valueStore(Request $request, PaRatingScale $scale): RedirectResponse
    {
        $data = $request->validate([
            'label'       => ['required', 'string', 'max:100'],
            'value'       => ['required', 'numeric'],
            'description' => ['nullable', 'string'],
            'color'       => ['nullable', 'string', 'max:20'],
            'sort_order'  => ['integer'],
        ]);

        $scale->values()->create($data);

        return back()->with('success', 'Rating value added.');
    }

    public function valueUpdate(Request $request, PaRatingScale $scale, PaRatingValue $value): RedirectResponse
    {
        $data = $request->validate([
            'label'       => ['sometimes', 'string', 'max:100'],
            'value'       => ['numeric'],
            'description' => ['nullable', 'string'],
            'color'       => ['nullable', 'string', 'max:20'],
            'sort_order'  => ['integer'],
        ]);

        $value->update($data);

        return back()->with('success', 'Rating value updated.');
    }

    public function valueDestroy(PaRatingScale $scale, PaRatingValue $value): RedirectResponse
    {
        $value->delete();

        return back()->with('success', 'Rating value deleted.');
    }
}
