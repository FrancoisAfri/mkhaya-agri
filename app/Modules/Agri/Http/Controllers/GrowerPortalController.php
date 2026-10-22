<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Agri\Models\Grower;
use App\Modules\Agri\Models\GrowerAccount;
use App\Modules\Agri\Services\GrowerPortal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The grower's own pages. The grower is always the signed-in account's (EnsureGrowerPortal), and
 * every read goes through GrowerPortal filtered on it: an id belonging to another grower is a 404.
 */
class GrowerPortalController extends Controller
{
    public function __construct(private readonly GrowerPortal $portal) {}

    private function account(Request $request): GrowerAccount
    {
        return $request->attributes->get('grower_account');
    }

    private function page(Request $request, string $component, array $props): Response
    {
        $a = $this->account($request);
        $g = $this->portal->grower($a);

        return Inertia::render($component, $props + [
            'brand' => GrowerPortalAuthController::brand((int) $a->company_id),
            'me' => ['name' => $a->name, 'code' => $g->code, 'grower' => $g->party?->name, 'has_password' => (bool) $a->password],
            'locale' => $a->locale,
        ]);
    }

    public function dashboard(Request $request): Response
    {
        $g = $this->portal->grower($this->account($request));

        return $this->page($request, 'Modules/Agri/GrowerPortal/Dashboard', ['summary' => $this->portal->summary($g), 'visits' => array_slice($this->portal->visits($g)['upcoming'], 0, 3),
            'notices' => $this->portal->notices($g, 3), 'deliveries' => $this->portal->deliveries($g, null, 5), 'contracts' => $this->portal->contracts($g)->where('status', 'active')->values()]);
    }

    public function contracts(Request $request): Response
    {
        return $this->page($request, 'Modules/Agri/GrowerPortal/Contracts', ['contracts' => $this->portal->contracts($this->portal->grower($this->account($request)))]);
    }

    public function contract(Request $request, string $contract): Response
    {
        $g = $this->portal->grower($this->account($request));
        $c = $this->portal->contract($g, $contract);

        return $this->page($request, 'Modules/Agri/GrowerPortal/Deliveries', ['contract' => $this->portal->contracts($g)->firstWhere('id', $c->uuid), 'deliveries' => $this->portal->deliveries($g, $c)]);
    }

    public function deliveries(Request $request): Response
    {
        return $this->page($request, 'Modules/Agri/GrowerPortal/Deliveries', ['contract' => null, 'deliveries' => $this->portal->deliveries($this->portal->grower($this->account($request)))]);
    }

    public function advances(Request $request): Response
    {
        return $this->page($request, 'Modules/Agri/GrowerPortal/Advances', ['advances' => $this->portal->advances($this->portal->grower($this->account($request)))]);
    }

    public function statements(Request $request): Response
    {
        $g = $this->portal->grower($this->account($request));

        return $this->page($request, 'Modules/Agri/GrowerPortal/Statements', ['summary' => $this->portal->summary($g), 'settlements' => $this->portal->settlements($g)]);
    }

    /** The statement as a PDF: the full account, or one settlement and the deliveries it paid. */
    public function statementPdf(Request $request, ?string $settlement = null)
    {
        $a = $this->account($request);
        $g = $this->portal->grower($a);
        $s = $settlement ? $this->portal->settlement($g, $settlement) : null;
        $this->portal->event($a, 'statement', 'web', $request->ip(), $request->userAgent(), ['settlement' => $s?->reference]);
        $name = 'statement-'.$g->code.($s ? '-'.$s->reference : '').'.pdf';

        return response($this->portal->statementPdf($g, $s), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store']);
    }

    public function visits(Request $request): Response
    {
        return $this->page($request, 'Modules/Agri/GrowerPortal/Visits', $this->portal->visits($this->portal->grower($this->account($request))));
    }

    public function messages(Request $request): Response
    {
        $g = $this->portal->grower($this->account($request));

        return $this->page($request, 'Modules/Agri/GrowerPortal/Messages', ['notices' => $this->portal->notices($g), 'reports' => $this->portal->reports($g),
            'contracts' => $this->portal->contracts($g)->map(fn ($c) => ['value' => $c['id'], 'title' => $c['reference'].' · '.$c['product']])->values()]);
    }

    public function storeReport(Request $request): RedirectResponse
    {
        $data = $request->validate(['kind' => ['required', Rule::in(['planting', 'issue'])], 'contract_id' => ['nullable', 'string', 'max:64'],
            'planted_on' => ['nullable', 'date', 'before_or_equal:today'], 'hectares' => ['nullable', 'numeric', 'gt:0', 'max:100000'],
            'details' => ['required', 'string', 'max:2000'], 'photo' => ['nullable', 'image', 'max:8192']]);
        $this->portal->submitReport($this->account($request), $data, $request->file('photo'));

        return back()->with('success', 'Sent to the company. You will see their answer here.');
    }

    public function password(Request $request): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'min:10', 'max:200', 'confirmed']]);
        $this->portal->setPassword($this->account($request), $data['password']);

        return back()->with('success', 'Password saved. You can sign in with it or with a code.');
    }
}
