<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Core\User\Trait\FileStorage;
use App\Modules\Agri\Models\CarbonDocument;
use App\Modules\Agri\Models\CarbonExport;
use App\Modules\Agri\Models\CarbonIssuance;
use App\Modules\Agri\Models\CarbonMonitoring;
use App\Modules\Agri\Models\CarbonPeriod;
use App\Modules\Agri\Models\CarbonRegistration;
use App\Modules\Agri\Models\CarbonTransaction;
use App\Modules\Agri\Models\EnvironmentRecord;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Services\CarbonRegistry;
use App\Modules\Agri\Support\RoleCatalog as R;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Carbon projects with a registry: listing, monitoring periods and data, issuances, retirements, documents and export packages. */
class CarbonController extends AgriController
{
    use FileStorage;

    public function __construct(private readonly CarbonRegistry $registry) {}

    public function index(Request $request): Response
    {
        $this->allow($request, R::VIEW_REPORTS);
        $farm = $this->farmFilter($request);
        $projects = EnvironmentRecord::query()->where('kind', 'carbon_project')->when($farm, fn ($q) => $q->where('farm_id', $farm->id))->orderByDesc('recorded_on')->get();
        $regs = CarbonRegistration::query()->whereIn('environment_record_id', $projects->pluck('id'))->get()->keyBy('environment_record_id');
        $periods = CarbonPeriod::query()->whereIn('registration_id', $regs->pluck('id'))->orderBy('starts_on')->get()->groupBy('registration_id');
        $counts = CarbonMonitoring::query()->whereIn('period_id', $periods->flatten()->pluck('id'))->selectRaw('period_id, count(*) as n')->groupBy('period_id')->pluck('n', 'period_id');
        $issuances = CarbonIssuance::query()->whereIn('registration_id', $regs->pluck('id'))->orderBy('issued_on')->get()->groupBy('registration_id');
        $tx = CarbonTransaction::query()->whereIn('issuance_id', $issuances->flatten()->pluck('id'))->orderBy('transacted_on')->get()->groupBy('issuance_id');
        $docs = CarbonDocument::query()->whereIn('registration_id', $regs->pluck('id'))->orderByDesc('id')->get(['id', 'uuid', 'registration_id', 'period_id', 'kind', 'name', 'size', 'created_at'])->groupBy('registration_id');
        $exports = CarbonExport::query()->whereIn('registration_id', $regs->pluck('id'))->orderByDesc('id')->get(['id', 'uuid', 'registration_id', 'period_id', 'file_name', 'size', 'sha256', 'contents', 'created_at'])->groupBy('registration_id');
        $farms = \App\Modules\Agri\Models\Farm::query()->whereIn('id', $projects->pluck('farm_id'))->pluck('name', 'id');

        return Inertia::render('Modules/Agri/Carbon/Index', [
            'filters' => ['farm' => $farm?->uuid],
            'farms' => collect($this->farmOptions())->map(fn ($f) => ['id' => $f['id'], 'name' => $f['name']])->values(),
            'projects' => $projects->map(function (EnvironmentRecord $p) use ($regs, $periods, $counts, $issuances, $tx, $docs, $exports, $farms) {
                $r = $regs[$p->id] ?? null;
                $periodRows = $r ? ($periods[$r->id] ?? collect()) : collect();

                return ['id' => $p->uuid, 'description' => $p->description, 'farm' => $farms[$p->farm_id] ?? null, 'recorded_on' => $p->recorded_on?->toDateString(),
                    'quantity' => $p->quantity, 'unit' => $p->unit, 'methodology' => $p->methodology, 'verifier' => $p->verifier, 'verification' => $p->verification,
                    'evidence' => $p->evidence_name,
                    'registration' => $r ? $r->only(['registry', 'registry_name', 'registry_project_id', 'status', 'methodology', 'proponent', 'buffer_pct', 'notes']) + [
                        'id' => $r->uuid, 'status_on' => $r->status_on?->toDateString(), 'crediting_starts_on' => $r->crediting_starts_on?->toDateString(),
                        'crediting_ends_on' => $r->crediting_ends_on?->toDateString(), 'balance' => $this->registry->balance($r),
                        'periods' => $periodRows->map(fn ($x) => ['id' => $x->uuid, 'starts_on' => $x->starts_on->toDateString(), 'ends_on' => $x->ends_on->toDateString(),
                            'status' => $x->status, 'claimed_t' => $x->claimed_t, 'verified_t' => $x->verified_t, 'verifier' => $x->verifier, 'verified_on' => $x->verified_on?->toDateString(),
                            'notes' => $x->notes, 'values' => (int) ($counts[$x->id] ?? 0)])->values(),
                        'issuances' => ($issuances[$r->id] ?? collect())->map(fn ($i) => ['id' => $i->uuid, 'vintage' => $i->vintage, 'quantity_t' => $i->quantity_t, 'buffer_t' => $i->buffer_t,
                            'serial_start' => $i->serial_start, 'serial_end' => $i->serial_end, 'issued_on' => $i->issued_on->toDateString(), 'held' => $this->registry->available($i),
                            'period' => ($pp = $periodRows->firstWhere('id', $i->period_id)) ? $pp->starts_on->toDateString().' – '.$pp->ends_on->toDateString() : null,
                            'transactions' => ($tx[$i->id] ?? collect())->map(fn ($t) => ['kind' => $t->kind, 'quantity_t' => $t->quantity_t, 'on' => $t->transacted_on->toDateString(),
                                'counterparty' => $t->counterparty, 'beneficiary' => $t->beneficiary, 'serials' => trim($t->serial_start.' – '.$t->serial_end, ' –')])->values()])->values(),
                        'documents' => ($docs[$r->id] ?? collect())->map(fn ($d) => ['id' => $d->uuid, 'kind' => $d->kind, 'name' => $d->name, 'size' => $d->size,
                            'period' => ($pp = $periodRows->firstWhere('id', $d->period_id)) ? $pp->starts_on->toDateString() : null])->values(),
                        'exports' => ($exports[$r->id] ?? collect())->map(fn ($e) => ['id' => $e->uuid, 'file_name' => $e->file_name, 'size' => $e->size, 'sha256' => $e->sha256,
                            'files' => count($e->contents ?? []), 'at' => $e->created_at?->format('Y-m-d H:i')])->values(),
                    ] : null];
            })->values(),
            'options' => ['registries' => CarbonRegistry::REGISTRIES, 'statuses' => CarbonRegistry::STATUSES, 'period_statuses' => CarbonRegistry::PERIOD_STATUSES,
                'document_kinds' => CarbonRegistry::DOCUMENT_KINDS],
            'can' => ['manage' => $request->user()->can(R::MANAGE_COMPLIANCE)],
        ]);
    }

    public function register(Request $request, EnvironmentRecord $record): RedirectResponse
    {
        $this->allow($request, R::MANAGE_COMPLIANCE);
        $this->assertWritable($request, $record->farm_id);
        $this->registry->register($record, $request->validate($this->registrationRules()), $request->user());

        return back()->with('success', 'Registry listing recorded.');
    }

    public function update(Request $request, CarbonRegistration $registration): RedirectResponse
    {
        $this->allow($request, R::MANAGE_COMPLIANCE);
        $this->assertWritable($request, $registration->farm_id);
        $this->registry->update($registration, $request->validate($this->registrationRules()));

        return back()->with('success', 'Registry listing saved.');
    }

    public function period(Request $request, CarbonRegistration $registration): RedirectResponse
    {
        $this->allow($request, R::MANAGE_COMPLIANCE);
        $this->assertWritable($request, $registration->farm_id);
        $this->registry->addPeriod($registration, $request->validate(['starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date'],
            'claimed_t' => ['nullable', 'numeric', 'gte:0'], 'notes' => ['nullable', 'string', 'max:1000']]));

        return back()->with('success', 'Monitoring period added.');
    }

    public function updatePeriod(Request $request, CarbonPeriod $period): RedirectResponse
    {
        $this->allow($request, R::MANAGE_COMPLIANCE);
        $this->assertWritable($request, $period->farm_id);
        $this->registry->updatePeriod($period, $request->validate(['status' => ['required', Rule::in(CarbonRegistry::PERIOD_STATUSES)], 'claimed_t' => ['nullable', 'numeric', 'gte:0'],
            'verified_t' => ['nullable', 'numeric', 'gte:0'], 'verifier' => ['nullable', 'string', 'max:160'], 'verified_on' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:1000']]));

        return back()->with('success', 'Monitoring period saved.');
    }

    public function monitoring(Request $request, CarbonPeriod $period): RedirectResponse
    {
        $this->allow($request, R::MANAGE_COMPLIANCE);
        $this->assertWritable($request, $period->farm_id);
        $data = $request->validate(['measured_on' => ['required', 'date'], 'parameter' => ['required', 'string', 'max:120'], 'value' => ['required', 'numeric'],
            'unit' => ['required', 'string', 'max:30'], 'field_id' => ['nullable', 'string'], 'method' => ['nullable', 'string', 'max:160'], 'source' => ['nullable', 'string', 'max:160']]);
        $data['field_id'] = ! empty($data['field_id']) ? FarmField::query()->where('uuid', $data['field_id'])->where('farm_id', $period->farm_id)->firstOrFail()->id : null;
        $this->registry->addMonitoring($period, [$data], $request->user());

        return back()->with('success', 'Monitoring value recorded.');
    }

    public function issuance(Request $request, CarbonRegistration $registration): RedirectResponse
    {
        $this->allow($request, R::MANAGE_COMPLIANCE);
        $this->assertWritable($request, $registration->farm_id);
        $data = $request->validate(['period_id' => ['nullable', 'string'], 'vintage' => ['required', 'integer', 'between:1990,2100'], 'quantity_t' => ['required', 'numeric', 'gt:0'],
            'buffer_t' => ['nullable', 'numeric', 'gte:0'], 'serial_start' => ['nullable', 'string', 'max:160'], 'serial_end' => ['nullable', 'string', 'max:160'], 'issued_on' => ['required', 'date']]);
        $data['period_id'] = ! empty($data['period_id']) ? CarbonPeriod::query()->where('uuid', $data['period_id'])->where('registration_id', $registration->id)->firstOrFail()->id : null;
        $this->registry->addIssuance($registration, $data);

        return back()->with('success', 'Issuance recorded.');
    }

    public function transaction(Request $request, CarbonIssuance $issuance): RedirectResponse
    {
        $this->allow($request, R::MANAGE_COMPLIANCE);
        $this->assertWritable($request, $issuance->farm_id);
        $this->registry->addTransaction($issuance, $request->validate(['kind' => ['required', Rule::in(['retirement', 'transfer'])], 'quantity_t' => ['required', 'numeric', 'gt:0'],
            'transacted_on' => ['required', 'date'], 'counterparty' => ['nullable', 'string', 'max:200'], 'beneficiary' => ['nullable', 'string', 'max:200'],
            'serial_start' => ['nullable', 'string', 'max:160'], 'serial_end' => ['nullable', 'string', 'max:160'], 'reason' => ['nullable', 'string', 'max:300']]), $request->user());

        return back()->with('success', 'Recorded.');
    }

    public function document(Request $request, CarbonRegistration $registration): RedirectResponse
    {
        $this->allow($request, R::MANAGE_COMPLIANCE);
        $this->assertWritable($request, $registration->farm_id);
        $data = $request->validate(['kind' => ['required', Rule::in(CarbonRegistry::DOCUMENT_KINDS)], 'period_id' => ['nullable', 'string'], 'file' => ['required', 'file', 'max:20480']]);
        $period = ! empty($data['period_id']) ? CarbonPeriod::query()->where('uuid', $data['period_id'])->where('registration_id', $registration->id)->firstOrFail() : null;
        $this->registry->addDocument($registration, $data['kind'], $this->saveFileToDatabase($request->file('file')) + ['size' => $request->file('file')->getSize()], $period?->id, $request->user());

        return back()->with('success', 'Document attached.');
    }

    public function downloadDocument(Request $request, CarbonDocument $document)
    {
        $this->allow($request, R::VIEW_REPORTS);
        $document->makeVisible('data');

        return response(base64_decode($document->data), 200, ['Content-Type' => $document->mime,
            'Content-Disposition' => 'attachment; filename="'.addslashes($document->name).'"', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function export(Request $request, CarbonRegistration $registration): RedirectResponse
    {
        $this->allow($request, R::MANAGE_COMPLIANCE);
        $period = $request->input('period_id') ? CarbonPeriod::query()->where('uuid', $request->input('period_id'))->where('registration_id', $registration->id)->firstOrFail() : null;
        $e = $this->registry->export($registration, $period, $request->user());

        return back()->with('success', "Export package {$e->file_name} built (".count($e->contents).' files).');
    }

    public function downloadExport(Request $request, CarbonExport $export)
    {
        $this->allow($request, R::VIEW_REPORTS);
        $export->makeVisible('data');

        return response(base64_decode($export->data), 200, ['Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$export->file_name.'"']);
    }

    private function registrationRules(): array
    {
        return ['registry' => ['required', Rule::in(array_keys(CarbonRegistry::REGISTRIES))], 'registry_name' => ['nullable', 'string', 'max:120'],
            'registry_project_id' => ['nullable', 'string', 'max:60'], 'status' => ['nullable', Rule::in(CarbonRegistry::STATUSES)], 'status_on' => ['nullable', 'date'],
            'methodology' => ['nullable', 'string', 'max:200'], 'proponent' => ['nullable', 'string', 'max:200'], 'crediting_starts_on' => ['nullable', 'date'],
            'crediting_ends_on' => ['nullable', 'date', 'after:crediting_starts_on'], 'buffer_pct' => ['nullable', 'numeric', 'between:0,60'], 'notes' => ['nullable', 'string', 'max:2000']];
    }
}
