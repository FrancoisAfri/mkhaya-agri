<?php

namespace App\Modules\Operations\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Manufacturing\Services\FactoryAccess;
use App\Modules\Operations\Manufacturing\Support\ManufacturingReport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The manufacturing audit trail (MFG-023): who or what changed which record, when, and the values
 * before and after. Read from the platform's activity log, always filtered to this company by
 * column (the log has no row-level security), and to the factories the reader may see.
 */
class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('audit', ManufacturingReport::class);
        $f = $request->validate(['type' => ['nullable', 'string', 'max:60'], 'user_id' => ['nullable', 'integer'], 'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'], 'q' => ['nullable', 'string', 'max:80'], 'event' => ['nullable', 'in:created,updated,deleted']]);
        $companyId = (int) (app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id() ?? $request->user()->company_id);
        $restriction = app(FactoryAccess::class)->restrictionFor($request->user());
        $from = Carbon::parse($f['from'] ?? now()->subDays(30)->toDateString())->startOfDay();
        $to = Carbon::parse($f['to'] ?? now()->toDateString())->endOfDay();

        $base = DB::table('activity_log')->where('log_name', 'manufacturing')->where('company_id', $companyId)
            ->whereBetween('created_at', [$from, $to])
            ->when($restriction !== null, fn ($q) => $q->where(fn ($w) => $w->whereRaw("(properties->>'factory_id') IS NULL")
                ->orWhereIn(DB::raw("(properties->>'factory_id')::bigint"), $restriction['factories'] ?: [0])));
        $types = (clone $base)->distinct()->pluck('subject_type')->filter()->map(fn ($t) => ['value' => $t, 'title' => class_basename($t)])->sortBy('title')->values();

        $rows = (clone $base)
            ->when($f['type'] ?? null, fn ($q, $t) => $q->where('subject_type', $t))
            ->when($f['user_id'] ?? null, fn ($q, $u) => $q->where('causer_id', $u))
            ->when($f['event'] ?? null, fn ($q, $e) => $q->where('event', $e))
            ->when($f['q'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('subject_id', ctype_digit($s) ? (int) $s : -1)
                ->orWhereRaw("properties->>'label' ILIKE ?", ['%'.$s.'%'])))
            ->orderByDesc('id')->paginate(50)->withQueryString();
        $people = DB::table('users')->leftJoin('hr_people', 'hr_people.user_id', '=', 'users.id')
            ->whereIn('users.id', collect($rows->items())->pluck('causer_id')->filter()->unique())
            ->selectRaw("users.id, COALESCE(NULLIF(TRIM(CONCAT(hr_people.first_name, ' ', hr_people.surname)), ''), users.email) AS display_name")->pluck('display_name', 'id');

        return Inertia::render('Modules/Manufacturing/Audit/Trail', [
            'entries' => collect($rows->items())->map(function ($a) use ($people) {
                $p = json_decode($a->properties ?? '{}', true) ?: [];
                $after = $p['attributes'] ?? [];
                $before = $p['old'] ?? [];
                $changes = collect(array_unique([...array_keys($after), ...array_keys($before)]))
                    ->map(fn ($k) => ['field' => $k, 'before' => $this->show($before[$k] ?? null), 'after' => $this->show($after[$k] ?? null)])->values();

                return ['id' => $a->id, 'at' => Carbon::parse($a->created_at)->toIso8601String(), 'event' => $a->event ?? $a->description,
                    'record_type' => class_basename((string) $a->subject_type), 'record_id' => $a->subject_id, 'label' => $p['label'] ?? null,
                    'actor' => $a->causer_id ? ($people[$a->causer_id] ?? "User {$a->causer_id}") : ($p['actor'] ?? 'system'),
                    'factory_id' => $p['factory_id'] ?? null, 'changes' => $changes];
            })->values(),
            'pagination' => ['page' => $rows->currentPage(), 'pages' => $rows->lastPage(), 'total' => $rows->total()],
            'types' => $types,
            'filters' => ['type' => $f['type'] ?? null, 'user_id' => $f['user_id'] ?? null, 'from' => $from->toDateString(), 'to' => $to->toDateString(),
                'q' => $f['q'] ?? null, 'event' => $f['event'] ?? null],
        ]);
    }

    private function show(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }

        return is_scalar($v) ? (string) $v : mb_strimwidth(json_encode($v, JSON_UNESCAPED_UNICODE), 0, 200, '…');
    }
}
