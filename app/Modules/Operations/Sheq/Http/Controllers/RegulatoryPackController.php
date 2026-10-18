<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Regulatory\PackAssurance;
use App\Modules\Operations\Sheq\Regulatory\PackManifest;
use App\Modules\Operations\Sheq\Services\LegalRegisterService;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Regulatory packs and the legal register they seed (§3, SHEQ-LEG-1).
 *
 * Both views read the stored manifest rather than the files on disk: a tenant is governed by the
 * version they activated, and the directory may already hold a newer one (SHEQ-REG-2).
 */
class RegulatoryPackController extends Controller
{
    use ResolvesCompany;

    public function __construct(
        private readonly LegalRegisterService $legalRegisterService,
        private readonly PackAssurance $assurance,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Modules/Sheq/Regulatory/Packs', [
            // Scoped to this company: which rulebook another tenant activated is not this
            // tenant's business, and an unscoped read shows a platform admin all of them.
            'activeVersionIds' => $companyId === null ? [] : DB::table('sheq_regulatory_pack_activations')
                ->where('company_id', $companyId)
                ->whereNull('deactivated_at')->where('status', 'ACCEPTED')
                ->whereNull('site_id')
                ->pluck('pack_version_id')->all(),

            /*
             * Which rulebook governs which scope, not merely which are on somewhere.
             *
             * Activation has always been per site in the engine — that is the whole of REG-1, so a
             * group can run za-mhsa on a mine and za-ohsa on a factory at once — but the screen
             * only ever posted a company-wide activation. The two packs were therefore mutually
             * exclusive in practice, and activating one appeared to switch the other off.
             */
            'activations' => $companyId === null ? [] : $this->activations($companyId),
            /*
             * REG-FIX-4/REG-FIX-5 on the face of the screen. The question "which of our rulebooks
             * has a lawyer actually read" had no answer anywhere, and a compliance product whose
             * statutory content is unreviewed should say so to the person operating it rather
             * than only in a requirements document.
             */
            'assurance' => $this->assurance->all(),
            'sites' => $companyId === null ? [] : $this->sites($companyId),
            'noCompany' => $companyId === null,
            'canManage' => $companyId !== null && (bool) $request->user()?->can('sheq.pack.manage'),
            'packs' => $this->versions()->map(fn (array $row) => [
                'version_id' => $row['version_id'],
                'pack_id' => $row['pack_id'],
                'name' => $row['name'],
                'jurisdiction' => $row['jurisdiction'],
                'version' => $row['version'],
                'effective_from' => $row['effective_from'],
                'effective_to' => $row['effective_to'],
                'signed' => $row['signed'],
                'checksum' => substr($row['checksum'], 0, 16),
                // Which site types this rulebook is written for, so the activation dialog can say
                // when somebody is about to put the mining Act on an office.
                'applies_to_site_types' => $row['manifest']->appliesToSiteTypes(),
                'rules' => array_map(fn (array $rule) => [
                    'id' => $rule['id'] ?? null,
                    'category' => $rule['then']['statutory_category'] ?? null,
                    'reportable' => (bool) ($rule['then']['reportable'] ?? false),
                    'obligations' => array_values((array) ($rule['then']['obligations'] ?? [])),
                ], $row['manifest']->classificationRules()),
                'obligations' => array_map(fn (string $id, array $definition) => [
                    'id' => $id,
                    'label' => $definition['label'] ?? $id,
                    'due_in' => $definition['due_in'] ?? null,
                    'responsible_role' => $definition['responsible_role'] ?? null,
                    'evidence_required' => (bool) ($definition['evidence_required'] ?? false),
                ], array_keys($row['manifest']->obligations()), array_values($row['manifest']->obligations())),
                'metrics' => array_values((array) ($row['manifest']->metrics()['definitions'] ?? [])),
            ])->values(),
        ]);
    }

    /**
     * Activate a pack version for the company, or for one site (SHEQ-REG-1).
     *
     * Activation is per site so a group can run za-mhsa on a mine and za-ohsa on a factory at once.
     * Superseding an existing activation deactivates it rather than deleting it: which rulebook was
     * in force, and when, is itself evidence.
     */
    public function activate(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.pack.manage'), 403, 'ENTITLEMENT_REQUIRED: sheq.pack.manage');

        // Resolved before validation so a platform admin gets the "view as company" explanation
        // rather than a foreign key violation on company_id = 0.
        $companyId = $this->requireCompany($request);

        $validated = $request->validate([
            'pack_version_id' => ['required', 'integer', Rule::exists('sheq_regulatory_pack_versions', 'id')],
            // Scoped to this company. Unscoped, a site id belonging to another tenant validated
            // and produced an activation row pointing at a site this company cannot see — which
            // then matches nothing, so the pack silently never applies to the site somebody
            // thought they had just configured.
            'site_id' => ['nullable', 'integer', Rule::exists('sheq_sites', 'id')
                ->where(fn ($q) => $q->where('company_id', $companyId)->whereNull('deleted_at'))],
            'status' => ['nullable', Rule::in(['ACCEPTED', 'PREVIEW'])],
            'assurance_acknowledgement' => ['nullable', 'string', 'max:2000'],
        ]);

        $status = $validated['status'] ?? 'ACCEPTED';

        /*
         * REG-FIX-4 and REG-FIX-5: what is wrong with this rulebook, and whether that stops it
         * becoming the live authority.
         *
         * A PREVIEW is a sandbox and is deliberately not gated — the whole point of previewing an
         * unreviewed pack is to find out what it would do. An ACCEPTED activation is the thing
         * that classifies real incidents and sets real statutory deadlines.
         */
        $assurance = $this->assurance->assess((int) $validated['pack_version_id']);

        if ($status === 'ACCEPTED' && ! $assurance['may_activate_accepted']) {
            // Tampering. No acknowledgement covers this, because a checksum is not an opinion.
            return back()->withErrors(['pack_version_id' => implode(' ', array_map(
                fn (array $gap) => $gap['label'].': '.$gap['detail'],
                array_filter($assurance['gaps'], fn (array $g) => $g['blocking']),
            ))]);
        }

        $acknowledgement = trim((string) ($validated['assurance_acknowledgement'] ?? ''));

        if ($status === 'ACCEPTED' && $assurance['requires_acknowledgement']
            && $acknowledgement === '') {
            /*
             * Not a block and not silent. An unsigned or unreviewed pack can be run — a customer
             * who authored their own rulebook has every right to — and somebody has to put their
             * name to that, because the alternative is a system quietly presenting its own
             * reading of the regulations as statutory compliance.
             */
            return back()->withErrors([
                'assurance_acknowledgement' => 'This rulebook is not ready to be the live '
                    .'authority without somebody accepting that: '
                    .implode(' ', array_map(fn (array $gap) => $gap['label'].'.',
                        array_filter($assurance['gaps'], fn (array $g) => ! $g['blocking'])))
                    .' Activate it as a PREVIEW to try it out, or record why it is acceptable to '
                    .'run as the authority.',
            ]);
        }

        DB::transaction(function () use ($validated, $companyId, $request, $status,
            $assurance, $acknowledgement) {
            DB::table('sheq_regulatory_pack_activations')
                ->where('company_id', $companyId)
                ->where(fn ($q) => $validated['site_id'] ?? null
                    ? $q->where('site_id', $validated['site_id'])
                    : $q->whereNull('site_id'))
                ->whereNull('deactivated_at')
                ->update(['deactivated_at' => now(), 'updated_at' => now()]);

            DB::table('sheq_regulatory_pack_activations')->insert([
                'company_id' => $companyId,
                'uuid' => (string) Str::uuid(),
                'site_id' => $validated['site_id'] ?? null,
                'pack_version_id' => $validated['pack_version_id'],
                'status' => $status,
                'activated_at' => now(),
                'activated_by' => $request->user()?->id,
                // Kept against the activation, and the gaps are frozen as they were at this
                // moment — so the record still reads correctly after somebody later signs or
                // reviews the pack.
                'assurance_acknowledged_at' => $acknowledgement === '' ? null : now(),
                'assurance_acknowledged_by' => $acknowledgement === '' ? null
                    : $request->user()?->id,
                'assurance_acknowledgement' => $acknowledgement === '' ? null : $acknowledgement,
                'assurance_gaps' => $assurance['gaps'] === [] ? null
                    : json_encode(array_column($assurance['gaps'], 'code')),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            /**
             * SHEQ-LEG-1: the register follows the pack into force. Seeding here rather than on a
             * later button press closes the window in which a pack is authoritative but the clauses
             * it imposes appear nowhere — which is the state most legal registers are found in.
             *
             * Only for a company-wide ACCEPTED activation: a PREVIEW is a sandbox (SHEQ-REG-8) and
             * must not write real compliance obligations.
             */
            if (($validated['status'] ?? 'ACCEPTED') === 'ACCEPTED') {
                $manifest = $this->manifestFor((int) $validated['pack_version_id']);

                if ($manifest !== null && $manifest->legalRegister() !== []) {
                    $this->legalRegisterService->seedFromPack(
                        $companyId, $manifest, $validated['site_id'] ?? null
                    );
                }
            }

            DB::table('sheq_audit_log')->insert([
                'company_id' => $companyId,
                'actor_id' => $request->user()?->id,
                'actor_type' => 'USER',
                'action' => 'regulatory_pack.activated',
                'subject_type' => 'RegulatoryPackVersion',
                'subject_id' => $validated['pack_version_id'],
                'after' => json_encode([
                    'site_id' => $validated['site_id'] ?? null,
                    'status' => $validated['status'] ?? 'ACCEPTED',
                ]),
            ]);
        });

        return back()->with('success', 'Regulatory pack activated. Incidents will now be classified against it.');
    }

    /**
     * The legal register as an inspector reads it (SHEQ-LEG-1..4).
     *
     * Every status here is computed from linked evidence. Recomputed on view because a register
     * that was accurate last night is not an answer to "are you compliant now" — the whole register
     * is tens of lines, so the cost is trivial next to being wrong.
     */
    public function legalRegister(Request $request): Response
    {
        $companyId = $this->companyId($request);

        if ($companyId === null) {
            return Inertia::render('Modules/Sheq/Regulatory/LegalRegister', [
                'lines' => [], 'summary' => ['lines' => 0, 'by_status' => [], 'compliance_percent' => 0.0],
                'needsCompany' => true,
            ]);
        }

        $this->legalRegisterService->recomputeAll($companyId);

        return Inertia::render('Modules/Sheq/Regulatory/LegalRegister', [
            'lines' => $this->legalRegisterService->register($companyId),
            'summary' => $this->legalRegisterService->summary($companyId),
            'needsCompany' => false,
        ]);
    }

    /** The manifest behind a stored pack version, or null if the row has vanished. */
    private function manifestFor(int $packVersionId): ?PackManifest
    {
        $row = DB::table('sheq_regulatory_pack_versions as v')
            ->join('sheq_regulatory_packs as p', 'p.id', '=', 'v.pack_id')
            ->where('v.id', $packVersionId)
            ->first(['p.pack_id', 'v.version', 'v.manifest']);

        if ($row === null) {
            return null;
        }

        return new PackManifest($row->pack_id, $row->version, json_decode($row->manifest, true) ?: []);
    }

    /** @return Collection<int,array<string,mixed>> */
    /**
     * Every live activation for this company, company-wide and per site.
     *
     * @return list<array<string,mixed>>
     */
    private function activations(int $companyId): array
    {
        return DB::table('sheq_regulatory_pack_activations as a')
            ->leftJoin('sheq_sites as s', 's.id', '=', 'a.site_id')
            ->where('a.company_id', $companyId)
            ->whereNull('a.deactivated_at')
            ->orderBy('a.site_id')
            ->get(['a.pack_version_id', 'a.site_id', 'a.status', 'a.activated_at', 's.name as site_name'])
            ->map(fn ($row) => [
                'pack_version_id' => (int) $row->pack_version_id,
                'site_id' => $row->site_id === null ? null : (int) $row->site_id,
                'site_name' => $row->site_name,
                'status' => $row->status,
                'activated_at' => $row->activated_at,
            ])
            ->all();
    }

    /**
     * The sites a pack may be activated against.
     *
     * Inactive sites are left out: activating a rulebook on a site nobody works at is a way to
     * believe you are covered when you are not.
     *
     * @return list<array<string,mixed>>
     */
    private function sites(int $companyId): array
    {
        return DB::table('sheq_sites')
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name', 'site_type'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'site_type' => $row->site_type === null ? null : strtoupper((string) $row->site_type),
            ])
            ->all();
    }

    private function versions()
    {
        return DB::table('sheq_regulatory_pack_versions as v')
            ->join('sheq_regulatory_packs as p', 'p.id', '=', 'v.pack_id')
            ->where('p.is_active', true)
            ->orderBy('p.pack_id')->orderByDesc('v.effective_from')
            ->get(['v.id as version_id', 'p.pack_id', 'p.name', 'p.jurisdiction', 'v.version',
                'v.effective_from', 'v.effective_to', 'v.signature_verified', 'v.checksum', 'v.manifest'])
            ->map(fn ($row) => [
                'version_id' => $row->version_id,
                'pack_id' => $row->pack_id,
                'name' => $row->name,
                'jurisdiction' => $row->jurisdiction,
                'version' => $row->version,
                'effective_from' => $row->effective_from,
                'effective_to' => $row->effective_to,
                'signed' => (bool) $row->signature_verified,
                'checksum' => (string) $row->checksum,
                'manifest' => PackManifest::fromArray(json_decode($row->manifest, true)),
            ]);
    }
}
