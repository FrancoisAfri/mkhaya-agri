<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Services\StatutoryFormService;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reviewing and submitting statutory forms (SHEQ-REG-6).
 *
 * The screen is a review surface, not a data-entry form: the system has already filled what it can
 * from the incident, and the practitioner's job is to check it, fill the gaps and take
 * responsibility for what goes to the regulator. Everything they change is audited.
 */
class StatutoryFormController extends Controller
{
    use ResolvesCompany;

    public function __construct(private readonly StatutoryFormService $forms) {}

    /** The queue: incidents that owe a form, and what state each form is in. */
    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Modules/Sheq/Forms/Index', [
            'noCompany' => $companyId === null,
            'rows' => $companyId === null ? [] : DB::table('sheq_incidents as i')
                ->leftJoin('sheq_sites as s', 's.id', '=', 'i.site_id')
                ->where('i.company_id', $companyId)->whereNull('i.deleted_at')
                ->where('i.is_reportable', true)
                ->orderByDesc('i.occurred_at')->limit(100)
                ->get(['i.id', 'i.uuid', 'i.reference', 'i.occurred_at', 'i.statutory_category',
                    'i.status', 's.name as site_name'])
                ->map(fn ($i) => (array) $i + [
                    'forms' => $this->forms->requiredFor($companyId, (int) $i->id),
                ])->values(),
        ]);
    }

    public function show(Request $request, string $uuid): Response
    {
        $companyId = $this->requireCompany($request);

        return Inertia::render('Modules/Sheq/Forms/Show', [
            'form' => $this->forms->view($companyId, $uuid),
            'canManage' => (bool) $request->user()?->can('sheq.form.manage'),
        ]);
    }

    public function generate(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        abort_unless($request->user()?->can('sheq.form.manage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.form.manage');

        $validated = $request->validate([
            'incident_uuid' => ['required', 'uuid'],
            'form_code' => ['required', 'string', 'max:40'],
        ]);

        $incidentId = DB::table('sheq_incidents')
            ->where('company_id', $companyId)->where('uuid', $validated['incident_uuid'])
            ->value('id');

        abort_if($incidentId === null, 404);

        $form = $this->forms->generate($companyId, (int) $incidentId,
            $validated['form_code'], $request->user()?->id);

        return redirect("/sheq/forms/{$form['uuid']}")
            ->with('success', $form['gap_count'] > 0
                ? "Form generated with {$form['gap_count']} field(s) the records could not fill."
                : 'Form generated and complete.');
    }

    public function override(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        abort_unless($request->user()?->can('sheq.form.manage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.form.manage');

        $validated = $request->validate([
            'field_key' => ['required', 'string', 'max:60'],
            'value' => ['nullable', 'string', 'max:5000'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->forms->override($companyId, $uuid, $validated['field_key'],
            $validated['value'] ?? null, $validated['reason'] ?? null, $request->user()?->id);

        return back()->with('success', 'Field updated. The original value is kept on the record.');
    }

    public function finalise(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        abort_unless($request->user()?->can('sheq.form.manage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.form.manage');

        $this->forms->finalise($companyId, $uuid, $request->user()?->id);

        return back()->with('success', 'Form finalised. This is now the record of what was submitted.');
    }

    /**
     * The printable form.
     *
     * A separate PDF rather than the browser's print of the Vue page: what goes to the Compensation
     * Commissioner must not vary with whoever's browser rendered it, and the file is what gets
     * attached to the obligation as evidence.
     */
    public function pdf(Request $request, string $uuid)
    {
        $companyId = $this->requireCompany($request);
        $form = $this->forms->view($companyId, $uuid);

        /*
         * The view this loads did not exist until 2026-10-07, so every download of a statutory
         * form threw "View [sheq.statutory-form] not found" — the route, the controller and all
         * the field binding were in place and the one artefact the module exists to produce could
         * not be produced.
         */
        $pdf = Pdf::loadView('sheq.statutory-form', [
            'form' => $form,
            'content' => $form['content'] ?? [],
            'company' => DB::table('companies')->where('id', $companyId)->value('name'),
            'incidentReference' => $this->incidentReferenceFor($companyId, $uuid),
            'generatedAt' => $this->renderedAt($form),
        ])->setPaper('a4');

        // The reference is on the filename as well as in the form, so a folder of these is
        // navigable without opening each one.
        $reference = $this->incidentReferenceFor($companyId, $uuid);
        $name = trim($form['form_code'].'-'.($reference ?: substr($uuid, 0, 8)));

        return $pdf->download(str_replace([' ', '/'], '-', $name).'.pdf');
    }

    /** The incident this form was generated for, for the footer and the filename. */
    private function incidentReferenceFor(int $companyId, string $formUuid): ?string
    {
        return DB::table('sheq_statutory_form_instances as f')
            ->join('sheq_incidents as i', 'i.id', '=', 'f.subject_id')
            ->where('f.company_id', $companyId)
            ->where('f.uuid', $formUuid)
            ->where('f.subject_type', 'INCIDENT')
            ->value('i.reference');
    }

    /**
     * When the form was finalised, or generated if it has not been.
     *
     * Rendered in the company's own timezone rather than UTC: the reader of the printed page is
     * standing in South Africa, and a date on a statutory form that is a day out matters.
     */
    private function renderedAt(array $form): string
    {
        $stamp = $form['finalised_at'] ?? $form['generated_at'] ?? null;

        return $stamp === null
            ? now()->timezone(config('app.timezone'))->format('d M Y H:i')
            : \Illuminate\Support\Carbon::parse($stamp)->timezone(config('app.timezone'))->format('d M Y H:i');
    }
}
