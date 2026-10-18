<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmAccount;
use App\Modules\CRM\Services\CrmAccountHealthService;
use App\Modules\CRM\Services\CrmAiService;
use App\Modules\CRM\Services\CrmVisibilityService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Account health dashboard — churn risk and expansion opportunities across the
 * accounts the user can see, with on-demand AI assessment.
 */
class CrmAccountHealthController extends Controller
{
    public function __construct(
        private CrmAccountHealthService $health,
        private CrmVisibilityService $visibility,
        private CrmAiService $ai,
    ) {
    }

    public function index(): Response
    {
        $owners = $this->visibility->visibleOwnerIds();

        $accounts = CrmAccount::withCount(['deals', 'contacts'])
            ->when($owners !== null, fn ($q) => $q->whereIn('owner_id', $owners))
            ->orderByRaw('health_assessed_at is null, health_score asc')
            ->limit(500)->get()
            ->map(fn ($a) => [
                'id'            => $a->id,
                'uuid'          => $a->uuid,
                'name'          => $a->name,
                'industry'      => $a->industry,
                'health_score'  => $a->health_score,
                'health_status' => $a->health_status,
                'churn_risk'    => $a->churn_risk,
                'summary'       => $a->health_summary,
                'assessed_at'   => optional($a->health_assessed_at)->diffForHumans(),
                'open_value'    => (float) $a->deals()->where('status', 'open')->sum('amount'),
                'deals'         => $a->deals_count,
            ]);

        return Inertia::render('Modules/CRM/AccountHealth', [
            'accounts'  => $accounts,
            'aiEnabled' => $this->ai->isConfigured(),
        ]);
    }

    public function assess(CrmAccount $account): RedirectResponse
    {
        $result = $this->health->assess($account);
        if (! ($result['available'] ?? false) || isset($result['error'])) {
            return back()->with('error', $result['error'] ?? 'Assessment failed.');
        }
        return back()->with('success', 'Health assessed: '.($result['status'] ?? '').'.');
    }
}
