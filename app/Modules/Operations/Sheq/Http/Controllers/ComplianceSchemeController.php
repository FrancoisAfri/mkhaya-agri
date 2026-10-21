<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Services\ComplianceSchemes;
use App\Modules\Operations\Sheq\Services\InspectionGates;
use App\Modules\Operations\Sheq\Services\LegalRegisterService;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Compliance schemes the organisation writes (GLOBALG.A.P., SIZA, organic, a customer's code), and
 * which inspections gate which kinds of work. Writing either needs sheq.pack.manage.
 */
class ComplianceSchemeController extends Controller
{
    use ResolvesCompany;

    public function __construct(private readonly ComplianceSchemes $schemes, private readonly InspectionGates $gates) {}

    public function index(Request $request): Response
    {
        $companyId = $this->requireCompany($request);
        $schemes = DB::table('sheq_compliance_schemes')->where('company_id', $companyId)->orderBy('name')->get();

        return Inertia::render('Modules/Sheq/Regulatory/Schemes', [
            'schemes' => $schemes->map(fn ($s) => [
                'uuid' => $s->uuid, 'code' => $s->code, 'name' => $s->name, 'scheme' => $s->scheme, 'jurisdiction' => $s->jurisdiction, 'version' => (int) $s->version, 'status' => $s->status,
                'clauses' => DB::table('sheq_compliance_scheme_clauses')->where('scheme_id', $s->id)->orderBy('clause')->get()
                    ->map(fn ($c) => ['clause' => $c->clause, 'instrument' => $c->instrument, 'requirement' => $c->requirement, 'category' => $c->category,
                        'evidence_types' => json_decode($c->evidence_types, true) ?: [], 'review_frequency' => $c->review_frequency, 'responsible_role' => $c->responsible_role])->values(),
                'sites' => DB::table('sheq_compliance_scheme_adoptions as a')->leftJoin('sheq_sites as st', 'st.id', '=', 'a.site_id')->where('a.scheme_id', $s->id)
                    ->get(['st.name', 'a.version'])->map(fn ($a) => ['name' => $a->name ?? 'Whole organisation', 'version' => (int) $a->version])->values(),
            ])->values(),
            'sites' => DB::table('sheq_sites')->where('company_id', $companyId)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name'])
                ->map(fn ($s) => ['value' => $s->id, 'title' => $s->name])->values(),
            'evidenceTypes' => collect(LegalRegisterService::evidenceTypes())->map(fn ($label, $value) => ['value' => $value, 'title' => $label])->values(),
            'gates' => DB::table('sheq_inspection_gates')->where('company_id', $companyId)->orderBy('subject_type')->orderBy('category')->get(['template_code', 'subject_type', 'category']),
            'templates' => DB::table('sheq_inspection_templates')->where('company_id', $companyId)->where('status', 'PUBLISHED')->whereNull('deleted_at')
                ->orderBy('title')->get(['code', 'title'])->map(fn ($t) => ['value' => $t->code, 'title' => $t->title])->values(),
            'gateable' => collect(InspectionGates::gateables())->map(fn ($g, $type) => ['subject_type' => $type, 'label' => $g['label'],
                'categories' => collect($g['categories'])->map(fn ($l, $v) => ['value' => $v, 'title' => $l])->values()])->values(),
            'canManage' => (bool) $request->user()?->can('sheq.pack.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = $this->manage($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'code' => ['nullable', 'string', 'max:40'], 'scheme' => ['nullable', 'string', 'max:80'],
            'jurisdiction' => ['nullable', 'string', 'max:60']]);

        return $this->attempt(fn () => $this->schemes->create($companyId, $data, $request->user()?->id), 'Scheme drafted; add its clauses, then publish it.');
    }

    public function clause(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->manage($request);
        $data = $request->validate(['clause' => ['required', 'string', 'max:60'], 'instrument' => ['nullable', 'string', 'max:255'], 'requirement' => ['required', 'string', 'max:4000'],
            'category' => ['nullable', 'string', 'max:40'], 'evidence_types' => ['required', 'array', 'min:1'], 'evidence_types.*' => ['string', 'max:40'],
            'review_frequency' => ['nullable', 'string', 'max:20'], 'responsible_role' => ['nullable', 'string', 'max:120']]);

        return $this->attempt(fn () => $this->schemes->clause($companyId, $this->scheme($companyId, $uuid), $data), 'Clause saved. Publish the scheme to bring it into the register.');
    }

    public function publish(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->manage($request);

        return $this->attempt(fn () => $this->schemes->publish($companyId, $this->scheme($companyId, $uuid), $request->user()?->id), 'Published; every site that adopted it is updated.');
    }

    public function adopt(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->manage($request);
        $data = $request->validate(['site_id' => ['nullable', 'integer']]);
        if (! empty($data['site_id']) && ! DB::table('sheq_sites')->where('company_id', $companyId)->where('id', $data['site_id'])->exists()) {
            throw ValidationException::withMessages(['site_id' => 'That site is not this organisation\'s.']);
        }

        return $this->attempt(fn () => $this->schemes->adopt($companyId, $this->scheme($companyId, $uuid), $data['site_id'] ?? null, $request->user()?->id),
            'Adopted: its clauses are now in the legal register for the site.');
    }

    public function gate(Request $request): RedirectResponse
    {
        $companyId = $this->manage($request);
        $data = $request->validate(['template_code' => ['required', 'string', 'max:60'], 'subject_type' => ['required', 'string', 'max:40'], 'category' => ['required', 'string', 'max:60'],
            'remove' => ['boolean']]);
        if (! isset(InspectionGates::gateables()[$data['subject_type']]['categories'][$data['category']])) {
            throw ValidationException::withMessages(['category' => 'That kind of work cannot be gated.']);
        }
        if (! empty($data['remove'])) {
            $this->gates->ungate($companyId, $data['template_code'], $data['subject_type'], $data['category']);

            return back()->with('success', 'Gate removed.');
        }

        return $this->attempt(fn () => $this->gates->gate($companyId, $data['template_code'], $data['subject_type'], $data['category'], $request->user()?->id),
            'That work now waits for a passed inspection before it starts.');
    }

    private function manage(Request $request): int
    {
        $companyId = $this->requireCompany($request);
        abort_unless($request->user()?->can('sheq.pack.manage'), 403, 'ENTITLEMENT_REQUIRED: sheq.pack.manage');

        return $companyId;
    }

    private function scheme(int $companyId, string $uuid): object
    {
        return DB::table('sheq_compliance_schemes')->where('company_id', $companyId)->where('uuid', $uuid)->first() ?? abort(404);
    }

    private function attempt(callable $fn, string $ok): RedirectResponse
    {
        try {
            $fn();
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['scheme' => $e->getMessage()]);
        }

        return back()->with('success', $ok);
    }
}
