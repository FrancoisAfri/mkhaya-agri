<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmAccount;
use App\Modules\CRM\Models\CrmContact;
use App\Modules\CRM\Models\CrmDeal;
use App\Modules\CRM\Models\CrmLead;
use App\Modules\CRM\Services\CrmAiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Data hygiene — surfaces incomplete and stale records and offers AI enrichment
 * to fill missing account fields (industry, size, description).
 */
class CrmDataHygieneController extends Controller
{
    public function __construct(private CrmAiService $ai)
    {
    }

    public function index(): Response
    {
        // Accounts missing key fields.
        $incompleteAccounts = CrmAccount::where(function ($q) {
            $q->whereNull('industry')->orWhere('industry', '')
              ->orWhereNull('website')->orWhere('website', '');
        })->orderBy('name')->limit(200)->get()
            ->map(fn ($a) => [
                'id' => $a->id, 'uuid' => $a->uuid, 'name' => $a->name,
                'missing' => collect(['industry' => $a->industry, 'website' => $a->website, 'email' => $a->email])
                    ->filter(fn ($v) => empty($v))->keys()->all(),
            ]);

        // Contacts missing email or phone.
        $contactsNoEmail = CrmContact::where(fn ($q) => $q->whereNull('email')->orWhere('email', ''))->count();

        // Stale: open deals with no update in 60+ days; leads untouched 30+ days.
        $staleDeals = CrmDeal::where('status', 'open')->where('updated_at', '<', now()->subDays(60))->count();
        $staleLeads = CrmLead::whereIn('status', ['new', 'contacted'])->where('updated_at', '<', now()->subDays(30))->count();

        // Possible duplicate accounts (same lowercased name).
        $dupeAccounts = DB::table('crm_accounts')->whereNull('deleted_at')
            ->select(DB::raw('lower(name) as n'), DB::raw('count(*) as c'))
            ->groupBy('n')->havingRaw('count(*) > 1')->count();

        return Inertia::render('Modules/CRM/DataHygiene', [
            'aiEnabled' => $this->ai->isConfigured(),
            'stats'     => [
                'incomplete_accounts' => $incompleteAccounts->count(),
                'contacts_no_email'   => $contactsNoEmail,
                'stale_deals'         => $staleDeals,
                'stale_leads'         => $staleLeads,
                'duplicate_accounts'  => $dupeAccounts,
            ],
            'accounts' => $incompleteAccounts,
        ]);
    }

    public function enrich(CrmAccount $account): RedirectResponse
    {
        $result = $this->ai->enrichAccount([
            'name' => $account->name, 'website' => $account->website, 'email' => $account->email,
            'city' => $account->city, 'country' => $account->country,
        ]);
        if (! ($result['available'] ?? false) || isset($result['error'])) {
            return back()->with('error', $result['error'] ?? 'Enrichment failed.');
        }

        $updates = [];
        if (empty($account->industry) && ! empty($result['industry'])) $updates['industry'] = $result['industry'];
        if (empty($account->notes) && ! empty($result['description'])) $updates['notes'] = $result['description'];
        if ($updates) $account->update($updates);

        return back()->with('success', $updates
            ? 'Enriched: '.implode(', ', array_keys($updates)).'.'
            : 'AI had no confident suggestions for this account.');
    }
}
