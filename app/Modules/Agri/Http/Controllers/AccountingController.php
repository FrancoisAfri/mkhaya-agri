<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Modules\Agri\Accounting\AccountingConnections;
use App\Modules\Agri\Accounting\AccountingSync;
use App\Modules\Agri\Models\AccountingConnection;
use App\Modules\Agri\Models\AccountingLink;
use App\Modules\Agri\Models\AccountingMapping;
use App\Modules\Agri\Models\AgriOrgSetting;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\IntegrationEvent;
use App\Modules\Agri\Models\LivestockGroup;
use App\Modules\Agri\Services\Integrations;
use App\Modules\Agri\Support\RoleCatalog as R;
use App\Modules\Finance\Accounting\Models\FinParty;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Farm Operations → Accounting connectors: the book of record, connecting Xero, QuickBooks Online
 * or Sage (OAuth; Sage South Africa by username and password), the mapping of our accounts, tax codes, farm dimensions, contacts and items onto
 * theirs, and the per-record sync log with retry.
 */
class AccountingController extends AgriController
{
    public const TABS = ['connections', 'mapping', 'log'];

    public function __construct(private readonly AccountingConnections $connections, private readonly AccountingSync $sync) {}

    public function index(Request $request): Response
    {
        $this->allow($request, R::MANAGE_ACCOUNTING);
        $companyId = (int) $request->user()->company_id;
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'connections';
        $settings = AgriOrgSetting::for($companyId);
        $book = $settings->book_of_record ?: 'internal';
        $conns = collect(AccountingConnection::PROVIDERS)->mapWithKeys(fn ($p) => [$p => AccountingConnection::for($companyId, $p)]);
        $provider = in_array($request->query('provider'), AccountingConnection::PROVIDERS, true) ? $request->query('provider')
            : ($book !== 'internal' ? $book : ($conns->first(fn ($c) => $c->isConnected())?->provider ?? 'xero'));
        $selected = $conns[$provider];

        $counts = AccountingLink::query()->selectRaw('connection_id, status, COUNT(*) as n')->groupBy('connection_id', 'status')->get()->groupBy('connection_id');
        $providers = $conns->map(fn (AccountingConnection $c) => ['provider' => $c->provider, 'name' => $c->provider === AccountingConnection::SAGE && $c->region === AccountingConnections::SAGE_ZA
                ? config('agri.accounting.providers.sage.regions.za.product') : config("agri.accounting.providers.{$c->provider}.name"),
            'registered' => AccountingConnection::registered($c->provider), 'status' => $c->status, 'tenant' => $c->tenant_name, 'tenant_id' => $c->tenant_id,
            'tenants' => $c->tenants ?? [], 'region' => $c->region, 'last_sync_at' => $c->last_sync_at?->format('Y-m-d H:i'), 'connected_at' => $c->connected_at?->format('Y-m-d H:i'),
            'last_error' => $c->last_error, 'settings' => $c->options(), 'redirect_uri' => AccountingConnections::redirectUri($c->provider),
            'catalog_pulled_at' => $c->catalog['pulled_at'] ?? null,
            'counts' => collect($counts[$c->id] ?? [])->mapWithKeys(fn ($r) => [$r->status => (int) $r->n])->all(),
            'mappings' => AccountingMapping::query()->where('connection_id', $c->id)->count()] + $this->signInFacts($c))->values()->all();

        $data = match ($tab) {
            'connections' => [],
            'mapping' => $this->mappingData($selected),
            'log' => ['links' => $this->links($selected, $request->query('status'))->map(fn ($l) => ['id' => $l->uuid, 'type' => $l->record_type, 'direction' => $l->direction, 'ref' => $l->local_ref, 'status' => $l->status, 'external_id' => $l->external_id,
                    'external_ref' => $l->external_ref, 'attempts' => (int) $l->attempts, 'error' => $l->last_error, 'synced_at' => $l->synced_at?->format('Y-m-d H:i'),
                    'event' => $l->event?->uuid, 'event_status' => $l->event?->status])->values()->all()],
        };

        return Inertia::render('Modules/Agri/Accounting/Index', [
            'tab' => $tab, 'provider' => $provider, 'book_of_record' => $book,
            'book_changed_at' => $settings->book_of_record_changed_at?->format('Y-m-d H:i'),
            'providers' => $providers, 'data' => $data,
            'regions' => collect(config('agri.accounting.providers.sage.regions', []))->map(fn ($r, $k) => ['value' => $k, 'title' => $r['name'], 'supported' => (bool) $r['supported'],
                'sign_in' => $r['sign_in'] ?? 'oauth'])->values()->all(),
        ]);
    }

    public function book(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_ACCOUNTING);
        $data = $request->validate(['book_of_record' => ['required', Rule::in(AccountingSync::BOOKS)]]);
        $this->sync->setBookOfRecord((int) $request->user()->company_id, $data['book_of_record'], $request->user());

        return back()->with('success', $data['book_of_record'] === 'internal' ? 'The books are our own ledger.'
            : 'The books are now '.config("agri.accounting.providers.{$data['book_of_record']}.name").': farm documents and journals are queued for export there.');
    }

    public function connect(Request $request, string $provider): HttpResponse
    {
        $this->allow($request, R::MANAGE_ACCOUNTING);
        $url = $this->connections->start((int) $request->user()->company_id, $this->provider($provider), $request->query('region'), $request->user());

        return Inertia::location($url);
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $this->allow($request, R::MANAGE_ACCOUNTING);
        $provider = $this->provider($provider);
        try {
            $c = $this->connections->callback((int) $request->user()->company_id, $provider, $request->query(), $request->user());
        } catch (ValidationException $e) {
            return to_route('agri.accounting.index', ['provider' => $provider])->with('error', collect($e->errors())->flatten()->implode(' '));
        }

        return to_route('agri.accounting.index', ['provider' => $provider])->with('success', $c->status === 'connected'
            ? "Connected to {$c->tenant_name}." : 'Connected. Choose which organisation to use.');
    }

    /** Sage South Africa: username and password (and optionally the organisation's own API key) checked, kept encrypted, the company listed. */
    public function sageSignIn(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_ACCOUNTING);
        $data = $request->validate(['username' => ['required', 'string', 'max:200'], 'password' => ['nullable', 'string', 'max:200'], 'api_key' => ['nullable', 'string', 'max:200']]);
        $c = $this->connections->signIn((int) $request->user()->company_id, $data, $request->user());

        return back()->with('success', $c->status === 'connected' ? "Signed in to Sage South Africa: using {$c->tenant_name}." : 'Signed in to Sage South Africa. Choose the company to use.');
    }

    public function tenant(Request $request, string $provider): RedirectResponse
    {
        $this->allow($request, R::MANAGE_ACCOUNTING);
        $data = $request->validate(['tenant_id' => ['required', 'string', 'max:120']]);
        $c = $this->connections->chooseTenant($this->connection($request, $provider), $data['tenant_id']);

        return back()->with('success', "Using {$c->tenant_name}.");
    }

    public function disconnect(Request $request, string $provider): RedirectResponse
    {
        $this->allow($request, R::MANAGE_ACCOUNTING);
        $this->connections->disconnect($this->connection($request, $provider), $request->user());

        return back()->with('success', 'Disconnected. If it was the book of record, the books are our own ledger again.');
    }

    public function pull(Request $request, string $provider): RedirectResponse
    {
        $this->allow($request, R::MANAGE_ACCOUNTING);
        $c = $this->connected($request, $provider);
        try {
            $cat = $this->sync->pullCatalog($c);
            $con = $this->sync->matchContacts($c);
        } catch (\Throwable $e) {
            $c->forceFill(['last_error' => mb_strimwidth($e->getMessage(), 0, 500)])->save();

            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Read {$cat['accounts']} accounts, {$cat['tax_rates']} tax rates and {$cat['tracking']} tracking categories ({$cat['auto_mapped']} accounts matched by code); "
            ."{$con['matched']} of {$con['ours']} farm customers and suppliers matched.");
    }

    public function mappings(Request $request, string $provider): RedirectResponse
    {
        $this->allow($request, R::MANAGE_ACCOUNTING);
        $data = $request->validate(['rows' => ['required', 'array'], 'rows.*.kind' => ['required', Rule::in(AccountingMapping::KINDS)], 'rows.*.local_key' => ['required', 'string', 'max:120'],
            'rows.*.local_label' => ['nullable', 'string', 'max:200'], 'rows.*.external_id' => ['nullable', 'string', 'max:120'], 'rows.*.external_code' => ['nullable', 'string', 'max:120'],
            'rows.*.external_name' => ['nullable', 'string', 'max:200'], 'rows.*.extra' => ['nullable', 'array']]);
        $n = $this->sync->saveMappings($this->connection($request, $provider), $data['rows']);

        return back()->with('success', "{$n} mapping(s) saved.");
    }

    public function settings(Request $request, string $provider): RedirectResponse
    {
        $this->allow($request, R::MANAGE_ACCOUNTING);
        $data = $request->validate(['push_invoices' => ['boolean'], 'push_bills' => ['boolean'], 'push_journals' => ['boolean'], 'pull_payments' => ['boolean'],
            'default_item' => ['nullable', 'string', 'max:120'], 'sync_from' => ['nullable', 'date_format:Y-m-d']]);
        $c = $this->connection($request, $provider);
        $c->update(['settings' => array_merge($c->settings ?? [], $data)]);

        return back()->with('success', 'Sync settings saved.');
    }

    public function syncNow(Request $request, string $provider): RedirectResponse
    {
        $this->allow($request, R::MANAGE_ACCOUNTING);
        $c = $this->connected($request, $provider);
        abort_unless($this->sync->bookOfRecord((int) $c->company_id) === $c->provider, 422, 'Make '.config("agri.accounting.providers.{$c->provider}.name").' the book of record first.');
        $r = $this->sync->run((int) $c->company_id);

        return back()->with($r['failed'] || $r['error'] ? 'warning' : 'success', "{$r['queued']} record(s) sent: {$r['pushed']} accepted, {$r['failed']} failed (see the sync log). {$r['payments']} payment(s) read back."
            .($r['error'] ? ' '.$r['error'] : ''));
    }

    public function retry(Request $request, AccountingLink $link): RedirectResponse
    {
        $this->allow($request, R::MANAGE_ACCOUNTING);
        $e = $link->event_id ? IntegrationEvent::query()->find($link->event_id) : null;
        if (! $e) {
            $c = AccountingConnection::query()->findOrFail($link->connection_id);
            $e = $this->sync->queue($c, $link->record_type, (int) $link->local_id, (string) $link->local_ref);
        } elseif (in_array($e->status, ['failed', 'dead'], true)) {
            $e = app(Integrations::class)->retry($e, $request->user());
        }

        return back()->with($e->status === 'processed' ? 'success' : 'error', $e->status === 'processed' ? "{$link->local_ref} sent." : "Still failing: {$e->error}");
    }

    /** The sync log, failures first, each with the integration message that carries it. */
    private function links(AccountingConnection $c, ?string $status)
    {
        $links = AccountingLink::query()->where('connection_id', $c->id)->when($status, fn ($q, $s) => $q->where('status', $s))
            ->orderByRaw("CASE status WHEN 'failed' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END")->orderByDesc('id')->limit(300)->get();
        $events = IntegrationEvent::query()->whereIn('id', $links->pluck('event_id')->filter()->all())->get(['id', 'uuid', 'status'])->keyBy('id');

        return $links->each(fn ($l) => $l->setRelation('event', $events[$l->event_id] ?? null));
    }

    private function mappingData(AccountingConnection $c): array
    {
        $companyId = (int) $c->company_id;
        $maps = AccountingMapping::query()->where('connection_id', $c->id)->get()->groupBy('kind')->map(fn ($g) => $g->keyBy('local_key')->map(fn ($m) => $m->only(['external_id', 'external_code', 'external_name', 'extra'])));
        // The accounts farm postings use: on farm journals and on the farm's invoices (with the control accounts they hit).
        $codes = DB::table('fin_journal_lines as l')->join('fin_accounts as a', 'a.id', '=', 'l.account_id')->where('l.company_id', $companyId)
            ->whereIn('l.journal_id', DB::table('fin_journal_lines')->where('company_id', $companyId)->whereRaw("dimensions->>'agri_farm' IS NOT NULL")->select('journal_id'))
            ->distinct()->pluck('a.name', 'a.code');
        foreach (array_filter([config('agri.accounts.produce_sales'), config('agri.accounts.livestock_sales'), config('agri.accounts.direct_expense'), config('agri.accounts.inputs'), config('finance.ar_control'), config('finance.ap_control'), config('finance.default_bank')]) as $code) {
            if (! isset($codes[$code])) {
                $codes[$code] = DB::table('fin_accounts')->where('code', $code)->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))->value('name') ?? $code;
            }
        }
        $codes = collect($codes)->sortKeys();
        $taxes = DB::table('fin_tax_codes')->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))->where('is_active', true)->orderBy('code')->get(['code', 'name', 'rate']);
        $parties = FinParty::query()->whereIn('id', $this->sync->farmInvoices($companyId, null)->pluck('party_id')->unique())->orderBy('name')->get(['id', 'name', 'vat_number', 'kind']);
        $dims = [
            ...Farm::query()->orderBy('name')->get(['id', 'name', 'code'])->map(fn ($f) => ['key' => "farm:{$f->id}", 'dimension' => 'farm', 'label' => "{$f->name} ({$f->code})"]),
            ...FarmField::query()->where('status', '!=', 'archived')->orderBy('code')->limit(300)->get(['id', 'code', 'name'])->map(fn ($f) => ['key' => "field:{$f->id}", 'dimension' => 'field', 'label' => "{$f->code} {$f->name}"]),
            ...CropPlan::query()->whereNotIn('status', ['cancelled'])->orderByDesc('id')->limit(200)->get(['id', 'code'])->map(fn ($p) => ['key' => "enterprise:crop_plan:{$p->id}", 'dimension' => 'enterprise', 'label' => "Crop plan {$p->code}"]),
            ...LivestockGroup::query()->orderBy('code')->limit(100)->get(['id', 'code', 'name'])->map(fn ($g) => ['key' => "enterprise:livestock_group:{$g->id}", 'dimension' => 'enterprise', 'label' => "{$g->code} {$g->name}"]),
        ];

        return [
            'catalog' => $c->catalog ?? ['accounts' => [], 'tax_rates' => [], 'tracking' => [], 'items' => []],
            'accounts' => $codes->map(fn ($name, $code) => ['key' => (string) $code, 'label' => "{$code} {$name}", 'map' => $maps['account'][$code] ?? null, 'item' => $maps['item'][$code] ?? null])->values()->all(),
            'taxes' => collect([['key' => '_none', 'label' => 'No tax code on the line']])->concat($taxes->map(fn ($t) => ['key' => $t->code, 'label' => "{$t->code} {$t->name} ({$t->rate}%)"]))
                ->map(fn ($t) => $t + ['map' => $maps['tax'][$t['key']] ?? null])->values()->all(),
            'dimensions' => collect($dims)->map(fn ($d) => $d + ['map' => $maps['tracking'][$d['key']] ?? null])->values()->all(),
            'contacts' => $parties->map(fn ($p) => ['key' => (string) $p->id, 'label' => $p->name, 'vat_number' => $p->vat_number, 'kind' => $p->kind, 'map' => $maps['contact'][(string) $p->id] ?? null])->values()->all(),
            'needs_items' => $c->provider === AccountingConnection::QUICKBOOKS,
        ];
    }

    /** How the connection signs in, and for Sage South Africa what is stored — never the password or the key themselves. */
    private function signInFacts(AccountingConnection $c): array
    {
        $za = $c->provider === AccountingConnection::SAGE && $c->region === AccountingConnections::SAGE_ZA;
        $cred = $za ? $c->tokens() : [];

        return ['sign_in' => $za ? 'password' : 'oauth', 'username' => $cred['username'] ?? null, 'has_password' => filled($cred['password'] ?? null),
            'own_api_key' => filled($cred['api_key'] ?? null),
            'platform_api_key' => $c->provider === AccountingConnection::SAGE ? filled(config('agri.accounting.providers.sage.regions.za.api_key')) : null];
    }

    private function provider(string $provider): string
    {
        abort_unless(in_array($provider, AccountingConnection::PROVIDERS, true), 404);

        return $provider;
    }

    private function connection(Request $request, string $provider): AccountingConnection
    {
        return AccountingConnection::for((int) $request->user()->company_id, $this->provider($provider));
    }

    private function connected(Request $request, string $provider): AccountingConnection
    {
        $c = $this->connection($request, $provider);
        abort_unless($c->isConnected(), 422, 'Connect '.config("agri.accounting.providers.{$provider}.name").' and choose its organisation first.');

        return $c;
    }
}
