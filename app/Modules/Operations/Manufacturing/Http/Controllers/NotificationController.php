<?php

namespace App\Modules\Operations\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\NotificationLog;
use App\Modules\Operations\Manufacturing\Models\NotificationRule;
use App\Modules\Operations\Manufacturing\Services\Notifier;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Who is told about what (MFG-020): the routing rules, and the log of what was sent. */
class NotificationController extends Controller
{
    use \App\Modules\HR\Support\PicksTenantPeople;
    use \App\Modules\Operations\Manufacturing\Concerns\ScopesValidationToTenant;

    public function __construct(private readonly Notifier $notifier) {}

    public function rules(Request $request): Response
    {
        $this->authorize('viewAny', NotificationRule::class);
        $companyId = $this->tenantId($request);
        foreach (array_keys(Notifier::EVENTS) as $event) {
            $this->notifier->rule($companyId, $event);
        }

        return Inertia::render('Modules/Manufacturing/Notifications/Rules', [
            'rules' => NotificationRule::query()->orderBy('event_type')->orderByRaw('factory_id NULLS FIRST')->get()->map(fn (NotificationRule $r) => [
                'id' => $r->id, 'event_type' => $r->event_type, 'meaning' => Notifier::EVENTS[$r->event_type][4] ?? '', 'is_active' => $r->is_active,
                'factory_id' => $r->factory_id, 'recipient_permissions' => $r->recipient_permissions ?? [], 'recipient_roles' => $r->recipient_roles ?? [],
                'recipient_user_ids' => $r->recipient_user_ids ?? [], 'notify_responsible' => $r->notify_responsible, 'channels' => $r->channels ?? [],
                'lead_hours' => $r->lead_hours, 'suppress_hours' => $r->suppress_hours, 'escalate_after_hours' => $r->escalate_after_hours,
                'escalate_to_permissions' => $r->escalate_to_permissions ?? [],
            ])->values(),
            'options' => [
                'permissions' => RoleCatalog::all(),
                'roles' => DB::table('roles')->orderBy('name')->pluck('name'),
                'people' => $this->tenantPeopleOptions($request),
                'factories' => Factory::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])->map(fn ($f) => ['id' => $f->id, 'name' => "{$f->code} — {$f->name}"])->values(),
                'events' => array_keys(Notifier::EVENTS),
            ],
            'can' => ['manage' => $request->user()->can('manage', NotificationRule::class)],
        ]);
    }

    /** Save a rule, or add one for a single factory that overrides the company-wide one. */
    public function saveRule(Request $request): RedirectResponse
    {
        $this->authorize('manage', NotificationRule::class);
        $d = $request->validate([
            'id' => ['nullable', 'integer', $this->tenantExists('mfg_notification_rules')],
            'event_type' => ['required', Rule::in(array_keys(Notifier::EVENTS))],
            'factory_id' => ['nullable', 'integer', $this->tenantExists('mfg_factories')],
            'is_active' => ['required', 'boolean'],
            'recipient_permissions' => ['present', 'array'], 'recipient_permissions.*' => [Rule::in(RoleCatalog::all())],
            'recipient_roles' => ['nullable', 'array'], 'recipient_roles.*' => ['string', 'exists:roles,name'],
            'recipient_user_ids' => ['nullable', 'array'], 'recipient_user_ids.*' => ['integer', Rule::in(array_column($this->tenantPeople($request), 'id'))],
            'notify_responsible' => ['required', 'boolean'],
            'channels' => ['required', 'array', 'min:1'], 'channels.*' => [Rule::in(['database', 'mail'])],
            'lead_hours' => ['required', 'integer', 'min:0', 'max:8760'],
            'suppress_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'escalate_after_hours' => ['nullable', 'integer', 'min:1', 'max:2160'],
            'escalate_to_permissions' => ['nullable', 'array'], 'escalate_to_permissions.*' => [Rule::in(RoleCatalog::all())],
        ]);
        $values = collect($d)->except('id')->all() + ['company_id' => $this->tenantId($request)];

        if (! empty($d['id'])) {
            NotificationRule::query()->findOrFail($d['id'])->update(collect($values)->except(['event_type', 'factory_id', 'company_id'])->all());
        } else {
            if (NotificationRule::query()->where('event_type', $d['event_type'])->where('factory_id', $d['factory_id'] ?? null)->exists()) {
                return back()->with('error', 'That factory already has its own rule for this event; edit it instead.');
            }
            NotificationRule::create($values);
        }

        return back()->with('success', 'Alert rule saved.');
    }

    public function destroyRule(Request $request, int $id): RedirectResponse
    {
        $this->authorize('manage', NotificationRule::class);
        $rule = NotificationRule::query()->findOrFail($id);
        if ($rule->factory_id === null) {
            return back()->with('error', 'The company-wide rule stays; switch it off instead.');
        }
        $rule->delete();

        return back()->with('success', 'Factory rule removed; the company-wide rule applies again.');
    }

    public function log(Request $request): Response
    {
        $this->authorize('log', NotificationRule::class);
        $f = $request->validate(['event' => ['nullable', Rule::in(array_keys(Notifier::EVENTS))], 'state' => ['nullable', Rule::in(['open', 'all'])]]);
        $state = $f['state'] ?? 'open';

        return Inertia::render('Modules/Manufacturing/Notifications/Log', [
            'entries' => NotificationLog::query()->with('recipient.person')
                ->when($f['event'] ?? null, fn ($q, $e) => $q->where('event_type', $e))
                ->when($state === 'open', fn ($q) => $q->whereNull('resolved_at'))
                ->orderByDesc('last_seen_at')->limit(500)->get()->map(fn (NotificationLog $l) => [
                    'event_type' => $l->event_type, 'title' => $l->title, 'message' => $l->message, 'url' => $l->url, 'reference' => $l->reference,
                    'recipient' => $l->recipient?->person ? trim("{$l->recipient->person->first_name} {$l->recipient->person->surname}") : $l->recipient?->email,
                    'reason' => $l->reason, 'level' => $l->level, 'occurrences' => $l->occurrences, 'in_digest' => $l->in_digest,
                    'channels' => $l->channels, 'first_seen_at' => $l->first_seen_at?->toIso8601String(), 'last_seen_at' => $l->last_seen_at?->toIso8601String(),
                    'sent_at' => $l->sent_at?->toIso8601String(), 'resolved_at' => $l->resolved_at?->toIso8601String(),
                ])->values(),
            'events' => array_keys(Notifier::EVENTS),
            'filters' => ['event' => $f['event'] ?? null, 'state' => $state],
            'can' => ['run' => $request->user()->can('manage', NotificationRule::class)],
        ]);
    }

    public function runNow(Request $request): RedirectResponse
    {
        $this->authorize('manage', NotificationRule::class);
        $s = $this->notifier->run($this->tenantId($request));

        return back()->with('success', "{$s['sent']} sent, {$s['suppressed']} already notified, {$s['escalated']} escalated, {$s['resolved']} resolved.");
    }

    private function tenantId(Request $request): int
    {
        return (int) (app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id() ?? $request->user()->company_id);
    }
}
