<?php

namespace App\Modules\Finance\Tax\Http\Controllers;

use App\Modules\Finance\Tax\Models\TaxAuditEvent;
use App\Modules\Finance\Tax\Services\TaxAudit;
use App\Modules\Finance\Tax\Support\TaxPermissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** §17 Tax audit trail: search, verify the chain, export. Read-only by construction. */
class TaxAuditController extends TaxController
{
    public function index(Request $request): Response
    {
        $this->allow(TaxPermissions::VIEW_AUDIT, 'audit.view');
        $f = $this->filters($request);

        return Inertia::render('Modules/Finance/Tax/Audit', [
            'events' => $this->query($f)->orderByDesc('id')->paginate(50)->withQueryString()->through(fn (TaxAuditEvent $e) => [
                'id' => $e->id, 'at' => $e->created_at?->toDateTimeString(), 'actor' => $e->actor_name, 'actor_type' => $e->actor_type,
                'action' => $e->action, 'subject' => $e->subject_type . ($e->subject_id ? " #{$e->subject_id}" : ''),
                'tax_type' => $e->tax_type, 'outcome' => $e->outcome, 'reason' => $e->reason,
                'before' => $e->before, 'after' => $e->after, 'context' => $e->context, 'correlation_id' => $e->correlation_id,
            ]),
            'filters' => $f,
            'actions' => TaxAuditEvent::query()->distinct()->orderBy('action')->pluck('action'),
        ]);
    }

    public function verify(Request $request)
    {
        $this->allow(TaxPermissions::VIEW_AUDIT, 'audit.verify');
        $broken = app(TaxAudit::class)->verifyChain((int) $request->user()->company_id);

        return back()->with($broken ? 'error' : 'success', $broken
            ? "The audit chain does not verify from event #{$broken}. Report this to your administrator."
            : 'The audit chain verifies: no event has been altered or removed.');
    }

    public function export(Request $request): StreamedResponse
    {
        $this->allow(TaxPermissions::VIEW_AUDIT, 'audit.export');
        $f = $this->filters($request);
        $rows = (function () use ($f) {
            foreach ($this->query($f)->orderBy('id')->cursor() as $e) {
                yield [$e->id, $e->created_at?->toDateTimeString(), $e->actor_name, $e->action, $e->subject_type, $e->subject_id,
                    $e->tax_type, $e->outcome, $e->reason, json_encode($e->before), json_encode($e->after), $e->correlation_id, $e->hash];
            }
        })();

        return $this->csv('tax-audit-' . now()->format('Ymd-His') . '.csv',
            ['Event', 'When', 'Who', 'Action', 'Record', 'Record id', 'Tax', 'Outcome', 'Reason', 'Before', 'After', 'Correlation', 'Hash'],
            $rows, ['Report' => 'Tax audit report', 'Generated' => now()->toDateTimeString()]);
    }

    private function filters(Request $request): array
    {
        return array_filter($request->validate([
            'action' => ['nullable', 'string', 'max:60'], 'outcome' => ['nullable', 'in:success,denied,failed'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'search' => ['nullable', 'string', 'max:100'],
        ]), fn ($v) => $v !== null && $v !== '');
    }

    private function query(array $f): Builder
    {
        return TaxAuditEvent::query()
            ->when($f['action'] ?? null, fn ($q, $a) => $q->where('action', $a))
            ->when($f['outcome'] ?? null, fn ($q, $o) => $q->where('outcome', $o))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('actor_name', 'ilike', "%{$s}%")->orWhere('reason', 'ilike', "%{$s}%")));
    }
}
