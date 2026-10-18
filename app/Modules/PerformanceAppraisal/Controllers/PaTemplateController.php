<?php

namespace App\Modules\PerformanceAppraisal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PerformanceAppraisal\Models\PaTemplate;
use App\Modules\PerformanceAppraisal\Models\PaTemplateSection;
use App\Modules\PerformanceAppraisal\Models\PaTemplateQuestion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PaTemplateController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Modules/PerformanceAppraisal/Templates/Index', [
            'templates' => PaTemplate::withCount('sections')
                ->orderBy('name')
                ->get()
                ->map(fn($t) => [
                    'id'             => $t->id,
                    'name'           => $t->name,
                    'description'    => $t->description,
                    'is_default'     => $t->is_default,
                    'is_active'      => $t->is_active,
                    'sections_count' => $t->sections_count,
                ]),
        ]);
    }

    public function show(PaTemplate $template): Response
    {
        return Inertia::render('Modules/PerformanceAppraisal/Templates/Show', [
            'template' => $template->load('sections.questions'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'is_active'   => ['boolean'],
        ]);
        $data['created_by'] = $request->user()->id;

        if (!empty($data['is_default'])) {
            PaTemplate::where('is_default', true)->update(['is_default' => false]);
        }

        PaTemplate::create($data);

        return back()->with('success', 'Template created.');
    }

    public function update(Request $request, PaTemplate $template): RedirectResponse
    {
        $data = $request->validate([
            'name'        => ['sometimes', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'is_default'  => ['boolean'],
            'is_active'   => ['boolean'],
        ]);

        if (!empty($data['is_default'])) {
            PaTemplate::where('is_default', true)->where('id', '!=', $template->id)->update(['is_default' => false]);
        }

        $template->update($data);

        return back()->with('success', 'Template updated.');
    }

    public function destroy(PaTemplate $template): RedirectResponse
    {
        $template->delete();

        return back()->with('success', 'Template deleted.');
    }

    // ── Sections ─────────────────────────────────────────────────────────────

    public function sectionStore(Request $request, PaTemplate $template): RedirectResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'weight'      => ['numeric', 'min:0', 'max:100'],
            'sort_order'  => ['integer'],
        ]);

        $used = $template->sections()->sum('weight');
        $adding = $data['weight'] ?? 0;
        if ($used + $adding > 100) {
            return back()->withErrors(['weight' => "Total section weight cannot exceed 100%. You have " . (100 - $used) . "% remaining."]);
        }

        $template->sections()->create($data);

        return back()->with('success', 'Section added.');
    }

    public function sectionUpdate(Request $request, PaTemplate $template, PaTemplateSection $section): RedirectResponse
    {
        $data = $request->validate([
            'name'        => ['sometimes', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'weight'      => ['numeric', 'min:0', 'max:100'],
            'sort_order'  => ['integer'],
        ]);

        if (isset($data['weight'])) {
            $used = $template->sections()->where('id', '!=', $section->id)->sum('weight');
            if ($used + $data['weight'] > 100) {
                return back()->withErrors(['weight' => "Total section weight cannot exceed 100%. You have " . (100 - $used) . "% remaining."]);
            }
        }

        $section->update($data);

        return back()->with('success', 'Section updated.');
    }

    public function sectionDestroy(PaTemplate $template, PaTemplateSection $section): RedirectResponse
    {
        $section->delete();

        return back()->with('success', 'Section deleted.');
    }

    // ── Questions ─────────────────────────────────────────────────────────────

    public function questionStore(Request $request, PaTemplate $template, PaTemplateSection $section): RedirectResponse
    {
        $data = $request->validate([
            'question'      => ['required', 'string'],
            'description'   => ['nullable', 'string'],
            'question_type' => ['required', 'in:rating,text,yes_no,multiple_choice'],
            'options'       => ['nullable', 'array'],
            'options.*'     => ['string'],
            'is_required'   => ['boolean'],
            'weight'        => ['numeric', 'min:0', 'max:100'],
            'sort_order'    => ['integer'],
        ]);

        $used = $section->questions()->sum('weight');
        $adding = $data['weight'] ?? 0;
        if ($used + $adding > 100) {
            return back()->withErrors(['weight' => "Total question weight cannot exceed 100%. You have " . (100 - $used) . "% remaining."]);
        }

        $section->questions()->create($data);

        return back()->with('success', 'Question added.');
    }

    public function questionUpdate(Request $request, PaTemplate $template, PaTemplateSection $section, PaTemplateQuestion $question): RedirectResponse
    {
        $data = $request->validate([
            'question'      => ['sometimes', 'string'],
            'description'   => ['nullable', 'string'],
            'question_type' => ['in:rating,text,yes_no,multiple_choice'],
            'options'       => ['nullable', 'array'],
            'options.*'     => ['string'],
            'is_required'   => ['boolean'],
            'weight'        => ['numeric', 'min:0', 'max:100'],
            'sort_order'    => ['integer'],
        ]);

        if (isset($data['weight'])) {
            $used = $section->questions()->where('id', '!=', $question->id)->sum('weight');
            if ($used + $data['weight'] > 100) {
                return back()->withErrors(['weight' => "Total question weight cannot exceed 100%. You have " . (100 - $used) . "% remaining."]);
            }
        }

        $question->update($data);

        return back()->with('success', 'Question updated.');
    }

    public function questionDestroy(PaTemplate $template, PaTemplateSection $section, PaTemplateQuestion $question): RedirectResponse
    {
        $question->delete();

        return back()->with('success', 'Question deleted.');
    }
}
