<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use App\Modules\Operations\Sheq\Rules\ReferenceCode;
use App\Modules\Operations\Sheq\Services\ContractorService;
use Illuminate\Validation\Rule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SHEQ configuration, including the employer particulars statutory forms need.
 *
 * The COIDA registration number is the one that matters most: without it a W.Cl.2 carries a
 * permanent gap and can never be finalised, so the form work is unusable until somebody can type it
 * in. That is what this screen exists for — it was the missing half of SHEQ-REG-6.
 *
 * The reference prefixes and SLAs were already settings with no screen; they are here too rather
 * than leaving a second gap behind.
 */
class SheqSettingsController extends Controller
{
    use ResolvesCompany;

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        $settings = $companyId === null ? null
            : DB::table('sheq_settings')->where('company_id', $companyId)->first();

        return Inertia::render('Modules/Sheq/Settings/Index', [
            'noCompany' => $companyId === null,
            'canManage' => (bool) $request->user()?->can('sheq.configure'),
            'settings' => [
                // Employer particulars — what the statutory forms read.
                'coida_registration_number' => $settings->coida_registration_number ?? null,
                'registered_employer_name' => $settings->registered_employer_name ?? null,
                'employer_postal_address' => $settings->employer_postal_address ?? null,
                'nature_of_business' => $settings->nature_of_business ?? null,
                'provincial_director_office' => $settings->provincial_director_office ?? null,

                // Operating settings that already existed with nowhere to set them.
                'incident_prefix' => $settings->incident_prefix ?? 'INC',
                'hazard_prefix' => $settings->hazard_prefix ?? 'HAZ',
                'action_prefix' => $settings->action_prefix ?? 'ACT',
                'permit_prefix' => $settings->permit_prefix ?? 'PTW',
                'assessment_prefix' => $settings->assessment_prefix ?? 'RA',
                'inspection_prefix' => $settings->inspection_prefix ?? 'INS',
                'hazard_triage_sla_hours' => (int) ($settings->hazard_triage_sla_hours ?? 24),
                'allow_anonymous_reporting' => (bool) ($settings->allow_anonymous_reporting ?? true),
                'capa_effectiveness_review_days' => (int) ($settings->capa_effectiveness_review_days ?? 90),

                /*
                 * What a contractor must produce before anybody works. This has decided whether
                 * people are let on site since the module shipped and had no screen at all — the
                 * column was seeded and then unreachable, so a host could neither see the bar nor
                 * move it.
                 */
                'contractor_required_documents' => $companyId === null
                    ? ContractorService::defaultRequiredDocuments()
                    : app(ContractorService::class)->configuredRequiredTypes($companyId),
            ],

            // The dropdown is filled from the vocabulary the compliance gate itself reads, so the
            // two cannot drift: a type offered here is a type a document can actually be filed as.
            'documentTypes' => collect(ContractorService::documentTypes())
                ->map(fn (string $label, string $code) => ['code' => $code, 'label' => $label])
                ->values()->all(),
            // Shown so the page can say why the COIDA number matters rather than just asking for it.
            'formReadiness' => $companyId === null ? null : [
                'coida_missing' => empty($settings->coida_registration_number),
                'reportable_incidents' => DB::table('sheq_incidents')
                    ->where('company_id', $companyId)->whereNull('deleted_at')
                    ->where('is_reportable', true)->count(),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        $validated = $request->validate([
            'coida_registration_number' => ['nullable', 'string', 'max:60'],
            'registered_employer_name' => ['nullable', 'string', 'max:255'],
            'employer_postal_address' => ['nullable', 'string', 'max:1000'],
            'nature_of_business' => ['nullable', 'string', 'max:255'],
            'provincial_director_office' => ['nullable', 'string', 'max:120'],

            // Prefixes appear on every reference ever issued, so they are constrained tightly.
            'incident_prefix' => ['nullable', 'string', 'max:8', new ReferenceCode],
            'hazard_prefix' => ['nullable', 'string', 'max:8', new ReferenceCode],
            'action_prefix' => ['nullable', 'string', 'max:8', new ReferenceCode],
            'permit_prefix' => ['nullable', 'string', 'max:8', new ReferenceCode],
            'assessment_prefix' => ['nullable', 'string', 'max:8', new ReferenceCode],
            'inspection_prefix' => ['nullable', 'string', 'max:8', new ReferenceCode],

            'hazard_triage_sla_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'allow_anonymous_reporting' => ['nullable', 'boolean'],
            'capa_effectiveness_review_days' => ['nullable', 'integer', 'min:1', 'max:365'],

            // An empty list is allowed and means "nothing demanded" — a decision a host may make,
            // and distinguishable from never having been asked, which is a missing settings row.
            'contractor_required_documents' => ['present', 'array'],
            'contractor_required_documents.*' => ['string', Rule::in(array_keys(ContractorService::documentTypes()))],
        ], [], [
            'coida_registration_number' => 'COIDA registration number',
        ]);

        $before = DB::table('sheq_settings')->where('company_id', $companyId)->first();

        $row = $validated;
        $row['contractor_required_documents'] = json_encode(array_values(
            $validated['contractor_required_documents']));

        DB::table('sheq_settings')->updateOrInsert(
            ['company_id' => $companyId],
            $row + ['updated_at' => now(), 'created_at' => now()]
        );

        /**
         * Audited because these values end up on statutory submissions. If a W.Cl.2 goes out with
         * the wrong COIDA number, "who changed it and when" is a question somebody will ask.
         */
        DB::table('sheq_audit_log')->insert([
            'company_id' => $companyId, 'actor_id' => $request->user()?->id, 'actor_type' => 'USER',
            'action' => 'sheq_settings.updated',
            'subject_type' => 'SheqSettings', 'subject_id' => $companyId,
            'before' => json_encode($before === null ? null : array_intersect_key(
                (array) $before, $validated)),
            'after' => json_encode($validated),
        ]);

        return back()->with('success', 'Settings saved.');
    }
}
