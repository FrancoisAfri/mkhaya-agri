<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Modules\Agri\Api\ApiAbilities;
use App\Modules\Agri\Api\ApiSpec;
use App\Modules\Agri\Api\ApiTokens;
use App\Modules\Agri\Api\Webhooks;
use App\Modules\Agri\Models\ApiCall;
use App\Modules\Agri\Models\ApiClient;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\IntegrationEvent;
use App\Modules\Agri\Models\WebhookSubscription;
use App\Modules\Agri\Support\RoleCatalog as R;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Farm Operations → API access: the organisation's API tokens (issued with scoped abilities, an
 * expiry and the farms they reach; shown once; revocable), webhook subscriptions with their
 * deliveries, the log of every API call, and the API documentation.
 */
class ApiAccessController extends AgriController
{
    public const TABS = ['tokens', 'webhooks', 'calls'];

    public function __construct(private readonly ApiTokens $tokens, private readonly Webhooks $webhooks) {}

    public function index(Request $request): Response
    {
        $this->allow($request, R::MANAGE_API);
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'tokens';
        $companyId = (int) $request->user()->company_id;
        $farms = Farm::query()->orderBy('name')->get(['id', 'uuid', 'name', 'code']);
        $farmNames = Farm::withoutGlobalScope('farm_access')->pluck('name', 'id');
        $clients = ApiClient::query()->with('serviceUser:id,email')->orderByDesc('id')->get();
        $clientNames = $clients->pluck('name', 'id');

        $data = match ($tab) {
            'tokens' => ['clients' => $clients->map(fn (ApiClient $c) => ['id' => $c->uuid, 'name' => $c->name, 'description' => $c->description, 'state' => $c->state(),
                'abilities' => $c->abilities, 'farms' => $c->farm_ids === null ? null : collect($c->farm_ids)->map(fn ($id) => $farmNames[$id] ?? '?')->values()->all(),
                'token_hint' => $c->token_hint, 'service_user' => $c->serviceUser?->email, 'rate_per_minute' => (int) $c->rate_per_minute,
                'expires_at' => $c->expires_at?->format('Y-m-d'), 'last_used_at' => $c->last_used_at?->format('Y-m-d H:i'), 'revoked_at' => $c->revoked_at?->format('Y-m-d H:i'),
                'calls_30d' => ApiCall::query()->where('client_id', $c->id)->where('created_at', '>=', now()->subDays(30))->count(),
                'created_at' => $c->created_at?->format('Y-m-d')])->values()->all()],
            'webhooks' => ['subscriptions' => WebhookSubscription::query()->orderByDesc('id')->get()->map(fn ($s) => $this->webhooks->row($s) + ['client' => $s->client_id ? ($clientNames[$s->client_id] ?? null) : null])->values()->all(),
                'deliveries' => IntegrationEvent::query()->where('integration', 'webhook')->orderByDesc('id')->limit(100)->get()
                    ->map(fn ($e) => ['id' => $e->uuid, 'event' => $e->payload['event'] ?? null, 'subscription' => $e->payload['subscription_id'] ?? null, 'status' => $e->status,
                        'attempts' => (int) $e->attempts, 'error' => $e->error, 'at' => $e->created_at?->format('Y-m-d H:i'), 'next_attempt_at' => $e->next_attempt_at?->format('Y-m-d H:i')])->values()->all()],
            'calls' => ['calls' => $this->tokens->calls($companyId, $request->query('client') ? ApiClient::query()->where('uuid', $request->query('client'))->value('id') : null)
                ->map(fn (ApiCall $c) => ['at' => $c->created_at?->format('Y-m-d H:i:s'), 'client' => $clientNames[$c->client_id] ?? null, 'method' => $c->method, 'path' => $c->path,
                    'route' => $c->route, 'status' => $c->status, 'ms' => $c->duration_ms, 'ip' => $c->ip, 'replayed' => $c->replayed, 'error' => $c->error])->values()->all(),
                'summary' => ApiCall::query()->where('created_at', '>=', now()->subDay())->selectRaw('COUNT(*) as calls, SUM(CASE WHEN status >= 400 THEN 1 ELSE 0 END) as errors')->first()?->only(['calls', 'errors'])],
        };

        return Inertia::render('Modules/Agri/Api/Index', [
            'tab' => $tab, 'data' => $data,
            'options' => ['abilities' => collect(ApiAbilities::MAP)->map(fn ($perms, $a) => ['value' => $a, 'permissions' => $perms, 'write' => in_array($a, ApiAbilities::WRITES, true)])->values()->all(),
                'farms' => $farms->map(fn ($f) => ['value' => $f->uuid, 'title' => "{$f->name} ({$f->code})"])->values()->all(),
                'events' => collect(Webhooks::EVENTS)->map(fn ($e, $k) => ['value' => $k, 'description' => $e[0]])->values()->all(),
                'clients' => $clients->filter(fn ($c) => $c->state() === 'active')->map(fn ($c) => ['value' => $c->uuid, 'title' => $c->name])->values()->all(),
                'max_days' => (int) config('agri.api.token_days', 365), 'per_minute' => (int) config('agri.api.per_minute', 120),
                'base_url' => url('/api/agri/v1'), 'spec_url' => route('api.agri.v1.openapi')],
            // Shown once, on the page right after it was made.
            'revealed' => $request->session()->get('agri_api_reveal'),
        ]);
    }

    public function docs(Request $request): Response
    {
        $this->allow($request, R::MANAGE_API);

        return Inertia::render('Modules/Agri/Api/Docs', ['spec' => app(ApiSpec::class)->document(), 'spec_url' => route('api.agri.v1.openapi')]);
    }

    public function storeToken(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_API);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:500'],
            'abilities' => ['required', 'array', 'min:1'], 'abilities.*' => ['string'], 'farm_ids' => ['nullable', 'array'], 'farm_ids.*' => ['uuid'],
            'expires_in_days' => ['required', 'integer', 'min:1'], 'rate_per_minute' => ['nullable', 'integer', 'min:10', 'max:1000']]);
        ['client' => $client, 'token' => $token] = $this->tokens->issue((int) $request->user()->company_id, $data, $request->user());

        return to_route('agri.api.index', ['tab' => 'tokens'])->with('agri_api_reveal', ['kind' => 'token', 'name' => $client->name, 'value' => $token])
            ->with('success', "Token for {$client->name} created. Copy it now — it is not shown again.");
    }

    public function revoke(Request $request, ApiClient $client): RedirectResponse
    {
        $this->allow($request, R::MANAGE_API);
        abort_if($client->revoked_at !== null, 422, 'That token is already revoked.');
        $this->tokens->revoke($client, $request->user());

        return back()->with('success', "{$client->name}'s token is revoked; it stops working at once.");
    }

    public function storeWebhook(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_API);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'url' => ['required', 'url', 'max:500'], 'events' => ['required', 'array', 'min:1'], 'events.*' => ['string']]);
        ['subscription' => $s, 'secret' => $secret] = $this->webhooks->subscribe((int) $request->user()->company_id, $data, $request->user());

        return to_route('agri.api.index', ['tab' => 'webhooks'])->with('agri_api_reveal', ['kind' => 'secret', 'name' => $s->name, 'value' => $secret])
            ->with('success', "Subscription {$s->name} created. Copy its signing secret now — it is not shown again.");
    }

    public function toggleWebhook(Request $request, WebhookSubscription $subscription): RedirectResponse
    {
        $this->allow($request, R::MANAGE_API);
        $subscription->update(['is_active' => ! $subscription->is_active]);

        return back()->with('success', $subscription->is_active ? 'Subscription switched on.' : 'Subscription paused.');
    }

    public function destroyWebhook(Request $request, WebhookSubscription $subscription): RedirectResponse
    {
        $this->allow($request, R::MANAGE_API);
        $subscription->delete();

        return back()->with('success', 'Subscription removed.');
    }

    public function testWebhook(Request $request, WebhookSubscription $subscription): RedirectResponse
    {
        $this->allow($request, R::MANAGE_API);
        $e = $this->webhooks->test($subscription);

        return back()->with($e->status === 'processed' ? 'success' : 'error', $e->status === 'processed' ? 'Test delivered.' : "Test failed: {$e->error} It will be retried.");
    }
}
