<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmWebhook;
use App\Modules\CRM\Models\CrmWebhookDelivery;
use App\Modules\CRM\Services\CrmWebhookDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Webhook + API-token management page (spec section 5).
 */
class WebhookController extends Controller
{
    public function index(Request $request): Response
    {
        $webhooks = CrmWebhook::withCount('deliveries')->latest()->get()
            ->map(fn ($w) => [
                'id'                => $w->id,
                'uuid'              => $w->uuid,
                'name'              => $w->name,
                'url'               => $w->url,
                'events'            => $w->events ?? [],
                'is_active'         => $w->is_active,
                'deliveries'        => $w->deliveries_count,
                'last_triggered_at' => $w->last_triggered_at?->diffForHumans(),
            ]);

        $deliveries = CrmWebhookDelivery::with('webhook:id,name')->latest()->limit(30)->get()
            ->map(fn ($d) => [
                'id'      => $d->id,
                'webhook' => $d->webhook?->name,
                'event'   => $d->event,
                'status'  => $d->status_code,
                'success' => $d->success,
                'error'   => $d->error,
                'at'      => $d->created_at?->diffForHumans(),
            ]);

        // Current user's Sanctum API tokens.
        $tokens = $request->user()->tokens()->latest()->get()
            ->map(fn ($t) => [
                'id'           => $t->id,
                'name'         => $t->name,
                'last_used_at' => $t->last_used_at?->diffForHumans(),
                'created_at'   => $t->created_at?->toDateString(),
            ]);

        return Inertia::render('Modules/CRM/ApiWebhooks', [
            'webhooks'   => $webhooks,
            'deliveries' => $deliveries,
            'tokens'     => $tokens,
            'events'     => CrmWebhook::EVENTS,
            'apiBase'    => url('/api/crm'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'     => 'required|string|max:160',
            'url'      => 'required|url|max:1024',
            'events'   => 'required|array|min:1',
            'events.*' => 'in:' . implode(',', array_keys(CrmWebhook::EVENTS)),
        ]);

        $secret = CrmWebhookDispatcher::newSecret();

        CrmWebhook::create([
            'uuid'       => Str::uuid(),
            'name'       => $data['name'],
            'url'        => $data['url'],
            'events'     => $data['events'],
            'secret'     => $secret,
            'is_active'  => true,
            'created_by' => Auth::id(),
        ]);

        // Surface the signing secret once so the receiver can be configured.
        return back()->with('webhookSecret', $secret)->with('success', 'Webhook created. Copy the signing secret now — it is not shown again.');
    }

    public function update(Request $request, CrmWebhook $webhook): RedirectResponse
    {
        $data = $request->validate([
            'name'     => 'required|string|max:160',
            'url'      => 'required|url|max:1024',
            'events'   => 'required|array|min:1',
            'events.*' => 'in:' . implode(',', array_keys(CrmWebhook::EVENTS)),
        ]);

        $webhook->update($data);

        return back()->with('success', 'Webhook updated.');
    }

    public function toggle(CrmWebhook $webhook): RedirectResponse
    {
        $webhook->update(['is_active' => ! $webhook->is_active]);

        return back()->with('success', $webhook->is_active ? 'Webhook enabled.' : 'Webhook paused.');
    }

    public function regenerateSecret(CrmWebhook $webhook): RedirectResponse
    {
        $secret = CrmWebhookDispatcher::newSecret();
        $webhook->update(['secret' => $secret]);

        return back()->with('webhookSecret', $secret)->with('success', 'New signing secret generated.');
    }

    public function destroy(CrmWebhook $webhook): RedirectResponse
    {
        $webhook->delete();

        return back()->with('success', 'Webhook deleted.');
    }
}
