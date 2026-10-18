<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\WarehouseManagement\Concerns\RefusesPolitely;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseApprovals;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseAudit;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseOverrides;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseSettings;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use App\Modules\Operations\WarehouseManagement\Support\RolePresets;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** WMS-200..203, WMS-220: the audit ledger, retention, emergency overrides and the role templates. */
class GovernanceController extends Controller
{
    use RefusesPolitely;

    public function index(Request $request, WarehouseAudit $audit, WarehouseSettings $settings): Response
    {
        $u = $request->user();
        abort_unless($u->can(RoleCatalog::AUDIT) || $u->can(RoleCatalog::MANAGE_WAREHOUSES) || $u->can(RoleCatalog::EMERGENCY_OVERRIDE), 403);
        $cid = (int) app(CurrentCompany::class)->id();
        $f = $request->validate(['action' => ['nullable', 'string', 'max:60'], 'entity' => ['nullable', 'string', 'max:32'], 'ref' => ['nullable', 'string', 'max:80'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'verify' => ['nullable', 'boolean']]);
        $canAudit = $u->can(RoleCatalog::AUDIT);

        return Inertia::render('Modules/Warehouse/Governance/Index', [
            'filters' => $f,
            'events' => $canAudit ? $this->events($cid, $f)->paginate(100)->withQueryString()->through(fn ($e) => ['sequence' => (int) $e->sequence,
                'at' => Carbon::parse($e->occurred_at)->toIso8601String(), 'actor' => $e->actor_label, 'actor_type' => $e->actor_type, 'action' => $e->action,
                'entity' => $e->entity_type, 'ref' => $e->entity_ref, 'summary' => $e->summary, 'before' => json_decode((string) $e->before, true), 'after' => json_decode((string) $e->after, true)]) : null,
            'chain' => $canAudit && ($f['verify'] ?? false) ? $audit->verify($cid) : null,
            'settings' => $settings->get($cid),
            'retention' => collect(WarehouseSettings::RETENTION)->map(fn ($r, $k) => ['key' => $k, 'default' => $r[0], 'minimum' => $r[1], 'label' => $r[2]])->values(),
            'overrides' => DB::table('wms_overrides as o')->join('users as u', 'u.id', '=', 'o.user_id')->leftJoin('users as r', 'r.id', '=', 'o.reviewed_by')
                ->where('o.company_id', $cid)->when(! $u->can(RoleCatalog::MANAGE_WAREHOUSES) && ! $canAudit, fn ($q) => $q->where('o.user_id', $u->id))
                ->orderByDesc('o.id')->limit(50)->get(['o.*', 'u.email', 'r.email as reviewer'])
                ->map(fn ($o) => ['id' => $o->id, 'user' => $o->email, 'mine' => (int) $o->user_id === (int) $u->id, 'reason' => $o->reason, 'types' => json_decode($o->subject_types, true),
                    'starts_at' => Carbon::parse($o->starts_at)->toIso8601String(), 'expires_at' => Carbon::parse($o->expires_at)->toIso8601String(),
                    'active' => ! $o->ended_at && Carbon::parse($o->expires_at)->isFuture(), 'uses' => (int) $o->uses, 'reviewer' => $o->reviewer, 'review_note' => $o->review_note]),
            'presets' => collect(RolePresets::all())->map(fn ($perms, $name) => ['role' => RolePresets::PREFIX.$name, 'permissions' => $perms])->values(),
            'permissions' => RoleCatalog::all(),
            'subjects' => collect(WarehouseApprovals::SUBJECTS)->map(fn ($s, $k) => ['value' => $k, 'title' => $s[0]])->values(),
            'can' => ['audit' => $canAudit, 'manage' => $u->can(RoleCatalog::MANAGE_WAREHOUSES), 'override' => $u->can(RoleCatalog::EMERGENCY_OVERRIDE)],
        ]);
    }

    public function export(Request $request, WarehouseAudit $audit): StreamedResponse
    {
        abort_unless($request->user()->can(RoleCatalog::AUDIT), 403);
        $cid = (int) app(CurrentCompany::class)->id();
        $f = $request->only(['action', 'entity', 'ref', 'from', 'to']);
        $audit->record($cid, 'export.downloaded', 'AUDIT', null, 'Audit ledger exported', null, ['filters' => $f]);

        return response()->streamDownload(function () use ($cid, $f) {
            $h = fopen('php://output', 'w');
            fputcsv($h, ['Sequence', 'When', 'Actor', 'Type', 'Action', 'Entity', 'Reference', 'Summary', 'Before', 'After', 'Hash']);
            foreach ($this->events($cid, $f)->cursor() as $e) {
                fputcsv($h, array_map(fn ($c) => is_string($c) && $c !== '' && in_array($c[0], ['=', '+', '-', '@'], true) ? "'".$c : $c,
                    [$e->sequence, $e->occurred_at, $e->actor_label, $e->actor_type, $e->action, $e->entity_type, $e->entity_ref, $e->summary, $e->before, $e->after, $e->hash]));
            }
            fclose($h);
        }, 'warehouse-audit-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function settings(Request $request, WarehouseSettings $settings): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::MANAGE_WAREHOUSES), 403);
        $d = $request->validate(collect(WarehouseSettings::RETENTION)->mapWithKeys(fn ($r, $k) => [$k => ['required', 'integer', 'min:1', 'max:36500']])->all());

        return $this->attempt(fn () => $settings->save((int) app(CurrentCompany::class)->id(), $d, $request->user()), 'Retention saved.');
    }

    public function override(Request $request, WarehouseOverrides $overrides, ?string $id = null): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::EMERGENCY_OVERRIDE) || $request->user()->can(RoleCatalog::MANAGE_WAREHOUSES), 403);
        $cid = (int) app(CurrentCompany::class)->id();
        if ($id === null) {
            $d = $request->validate(['reason' => ['required', 'string', 'max:1000'], 'types' => ['required', 'array', 'min:1'],
                'types.*' => [Rule::in(array_keys(WarehouseApprovals::SUBJECTS))], 'minutes' => ['required', 'integer', 'between:5,'.WarehouseOverrides::MAX_MINUTES]]);
            abort_unless($request->user()->can(RoleCatalog::EMERGENCY_OVERRIDE), 403);

            return $this->attempt(fn () => $overrides->open($cid, $request->user(), $d['reason'], $d['types'], $d['minutes']), 'Emergency override open. Every use is recorded and will be reviewed.');
        }
        $o = DB::table('wms_overrides')->where('company_id', $cid)->where('id', (int) $id)->firstOrFail();
        $d = $request->validate(['action' => ['required', Rule::in(['end', 'review'])], 'note' => ['nullable', 'string', 'max:1000']]);

        return $this->attempt(fn () => $d['action'] === 'end' ? $overrides->end($o, $request->user()) : $overrides->review($o, $request->user(), (string) ($d['note'] ?? '')),
            $d['action'] === 'end' ? 'Override closed.' : 'Override reviewed.');
    }

    private function events(int $cid, array $f)
    {
        return DB::table('wms_audit_events')->where('company_id', $cid)
            ->when($f['action'] ?? null, fn ($q, $v) => $q->where('action', 'like', str_replace(['%', '_'], ['\%', '\_'], $v).'%'))
            ->when($f['entity'] ?? null, fn ($q, $v) => $q->where('entity_type', $v))->when($f['ref'] ?? null, fn ($q, $v) => $q->where('entity_ref', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('occurred_at', '>=', Carbon::parse($v)->startOfDay()))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('occurred_at', '<=', Carbon::parse($v)->endOfDay()))
            ->orderByDesc('sequence');
    }
}
