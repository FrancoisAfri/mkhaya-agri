<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\SelfAssessment;
use App\Modules\Agri\Services\CertificationSchemes;
use App\Modules\Agri\Support\CertificationTemplates;
use App\Modules\Agri\Support\RoleCatalog as R;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Certification checklists: install a ready-made scheme (GLOBALG.A.P. IFA, SIZA, organic) as the
 * organisation's own SHEQ scheme, adopt it at a farm, and prepare for the audit with the farm's
 * self-assessment — every control point with its evidence, % compliant by level, and a PDF.
 */
class CertificationController extends AgriController
{
    public function __construct(private readonly CertificationSchemes $schemes) {}

    public function index(Request $request): Response
    {
        $this->allow($request, R::VIEW_DOCUMENTS);
        $companyId = (int) $request->user()->company_id;
        $farms = Farm::query()->orderBy('name')->get();
        $farm = $this->farmFilter($request) ?? $farms->first();
        $schemes = $this->schemes->schemesFor($companyId);
        $scheme = $request->integer('scheme') ? $schemes->firstWhere('id', $request->integer('scheme')) : $schemes->first();

        return Inertia::render('Modules/Agri/Certification/Index', [
            'filters' => ['farm' => $farm?->uuid, 'scheme' => $scheme?->id],
            'farms' => $farms->map(fn ($f) => ['id' => $f->uuid, 'name' => $f->name])->values(),
            'templates' => $this->schemes->templates($companyId),
            'schemes' => $schemes->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'version' => (int) $s->version])->values(),
            'assessment' => $farm && $scheme ? $this->schemes->assessment($farm, $scheme) : null,
            'levels' => CertificationTemplates::LEVELS,
            'can' => ['manage' => $request->user()->can(R::MANAGE_COMPLIANCE)],
            'sheq_url' => '/sheq/compliance-schemes',
        ]);
    }

    public function install(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_COMPLIANCE);
        $data = $request->validate(['template' => ['required', 'string', Rule::in(array_keys(CertificationTemplates::all()))]]);
        $scheme = $this->schemes->install((int) $request->user()->company_id, $data['template'], $request->user());

        return redirect()->route('agri.certification.index', ['scheme' => $scheme->id])->with('success', "{$scheme->name} installed as your own scheme; edit it in SHEQ › Compliance schemes.");
    }

    public function adopt(Request $request, Farm $farm): RedirectResponse
    {
        $this->allow($request, R::MANAGE_COMPLIANCE);
        $this->assertWritable($request, $farm->id);
        $data = $request->validate(['scheme_id' => ['required', 'integer']]);
        $scheme = $this->schemes->scheme((int) $request->user()->company_id, $data['scheme_id']);
        $this->schemes->adopt($farm, $scheme, $request->user());

        return back()->with('success', "{$scheme->name} adopted at {$farm->name}: its control points are in the farm's legal register.");
    }

    public function answer(Request $request, Farm $farm): RedirectResponse
    {
        $this->allow($request, R::MANAGE_COMPLIANCE);
        $this->assertWritable($request, $farm->id);
        $data = $request->validate(['scheme_id' => ['required', 'integer'], 'clause' => ['required', 'string', 'max:60'],
            'answer' => ['required', Rule::in(SelfAssessment::ANSWERS)], 'comment' => ['nullable', 'string', 'max:2000']]);
        $scheme = $this->schemes->scheme((int) $request->user()->company_id, $data['scheme_id']);
        $this->schemes->answer($farm, $scheme, $data['clause'], $data['answer'], $data['comment'] ?? null, $request->user());

        return back()->with('success', "{$data['clause']} answered.");
    }

    public function pdf(Request $request, Farm $farm)
    {
        $this->allow($request, R::VIEW_DOCUMENTS);
        $scheme = $this->schemes->scheme((int) $request->user()->company_id, $request->integer('scheme'));
        $pdf = $this->schemes->pdf($farm, $scheme, $request->user());

        return response($pdf, 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.Str::slug("{$farm->code} {$scheme->name} self-assessment").'.pdf"']);
    }
}
