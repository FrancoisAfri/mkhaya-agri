<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Modules\Agri\Models\ProduceLot;
use App\Modules\Agri\Models\TraceToken;
use App\Modules\Agri\Services\PublicTrace;
use App\Modules\Agri\Support\RoleCatalog as R;
use App\Modules\Agri\Support\UiLabels;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Consumer trace codes: issue, choose what each shows, revoke, and print QR label sheets. */
class TraceController extends AgriController
{
    public function __construct(private readonly PublicTrace $trace) {}

    public function index(Request $request): Response
    {
        $this->allow($request, R::VIEW_STOCK);
        $farm = $this->farmFilter($request);
        $companyId = (int) $request->user()->company_id;
        $lots = ProduceLot::query()->when($farm, fn ($q) => $q->where('farm_id', $farm->id))->whereIn('kind', PublicTrace::TRACEABLE)
            ->where('status', '!=', 'written_off')->orderByDesc('produced_on')->orderByDesc('id')->limit(200)->get();
        $tokens = TraceToken::query()->whereIn('produce_lot_id', $lots->pluck('id'))->orderByDesc('id')->get()->groupBy('produce_lot_id');
        $site = DB::table('farms')->whereIn('id', $lots->pluck('farm_id')->unique())->whereNotNull('sheq_site_id')->pluck('sheq_site_id');

        return Inertia::render('Modules/Agri/Trace/Index', [
            'filters' => ['farm' => $farm?->uuid],
            'farms' => collect($this->farmOptions())->map(fn ($f) => ['id' => $f['id'], 'name' => $f['name']])->values(),
            'lots' => $lots->map(function (ProduceLot $l) use ($tokens) {
                $t = ($tokens[$l->id] ?? collect())->firstWhere('status', TraceToken::ACTIVE);

                return ['id' => $l->uuid, 'code' => $l->code, 'kind' => $l->kind, 'product' => $l->product, 'grade' => $l->grade, 'status' => $l->status,
                    'produced_on' => $l->produced_on?->toDateString(),
                    'token' => $t ? ['id' => $t->uuid, 'url' => $this->trace->url($t), 'visibility' => $t->visibility, 'views' => $t->views,
                        'last_viewed_at' => $t->last_viewed_at?->format('Y-m-d H:i'), 'issued' => $t->created_at?->format('Y-m-d')] : null,
                    'revoked' => ($tokens[$l->id] ?? collect())->where('status', TraceToken::REVOKED)->count()];
            })->values(),
            'settings' => ['defaults' => $this->trace->defaults($companyId), 'certifications' => \App\Modules\Agri\Models\AgriOrgSetting::for($companyId)->certifications ?? []],
            // Schemes adopted in SHEQ at these farms — a hint only: a scheme adopted is not a certificate held.
            'adopted_schemes' => $site->isEmpty() ? [] : DB::table('sheq_compliance_scheme_adoptions as a')->join('sheq_compliance_schemes as s', 's.id', '=', 'a.scheme_id')
                ->where('a.company_id', $companyId)->whereIn('a.site_id', $site)->distinct()->pluck('s.name')->values(),
            'parts' => PublicTrace::PARTS,
            'can' => ['manage' => $request->user()->can(R::MANAGE_PACKHOUSE)],
        ]);
    }

    public function settings(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_PACKHOUSE);
        $data = $request->validate(['defaults' => ['required', 'array'], 'defaults.*' => ['boolean'], 'certifications' => ['present', 'array', 'max:20'],
            'certifications.*' => ['nullable', 'string', 'max:120']]);
        $this->trace->saveSettings((int) $request->user()->company_id, $data['defaults'], $data['certifications']);

        return back()->with('success', 'Trace page settings saved.');
    }

    public function issue(Request $request, ProduceLot $lot): RedirectResponse
    {
        $this->allow($request, R::MANAGE_PACKHOUSE);
        $this->assertWritable($request, $lot->farm_id);
        $data = $request->validate(['visibility' => ['nullable', 'array'], 'visibility.*' => ['boolean']]);
        $this->trace->issue($lot, $request->user(), $data['visibility'] ?? null);

        return back()->with('success', "Public trace code ready for lot {$lot->code}.");
    }

    public function update(Request $request, TraceToken $token): RedirectResponse
    {
        $this->allow($request, R::MANAGE_PACKHOUSE);
        $this->assertWritable($request, $token->farm_id);
        $this->trace->update($token, $request->validate(['visibility' => ['required', 'array'], 'visibility.*' => ['boolean']])['visibility']);

        return back()->with('success', 'What the trace page shows was saved.');
    }

    public function revoke(Request $request, TraceToken $token): RedirectResponse
    {
        $this->allow($request, R::MANAGE_PACKHOUSE);
        $this->assertWritable($request, $token->farm_id);
        $this->trace->revoke($token, $request->user(), $request->validate(['reason' => ['nullable', 'string', 'max:300']])['reason'] ?? null);

        return back()->with('success', 'Trace code revoked: the page is gone and the printed code no longer opens it.');
    }

    /** A printable A4 sheet of QR labels for the chosen lots (their active codes). */
    public function labels(Request $request)
    {
        $this->allow($request, R::VIEW_STOCK);
        $ids = $request->validate(['lots' => ['required', 'array', 'min:1', 'max:200'], 'lots.*' => ['string'], 'copies' => ['nullable', 'integer', 'between:1,40']]);
        $lots = ProduceLot::query()->whereIn('uuid', $ids['lots'])->get()->keyBy('id');
        $tokens = TraceToken::query()->whereIn('produce_lot_id', $lots->keys())->where('status', TraceToken::ACTIVE)->get();
        abort_if($tokens->isEmpty(), 422, 'None of those lots has an active trace code.');
        $labels = [];
        foreach ($tokens as $t) {
            $l = $lots[$t->produce_lot_id];
            for ($i = 0; $i < (int) ($ids['copies'] ?? 1); $i++) {
                $labels[] = ['product' => $l->product, 'lot' => $l->code, 'grade' => $l->grade, 'url' => $this->trace->url($t),
                    'qr' => 'data:image/svg+xml;base64,'.base64_encode($this->trace->qrSvg($t, 220))];
            }
        }
        $html = view('agri.trace-labels', ['labels' => $labels, 'scan' => UiLabels::get('agri.trace_public.scan_label')])->render();

        return Pdf::loadHTML($html)->setPaper('a4')->stream('trace-labels.pdf');
    }
}
