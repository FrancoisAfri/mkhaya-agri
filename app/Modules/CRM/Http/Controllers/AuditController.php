<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRM audit trail (spec section 9) — surfaces the Spatie activity log for every
 * CRM record (who changed what, when, old → new), with filters and CSV export
 * for compliance.
 */
class AuditController extends Controller
{
    /** Friendly entity labels keyed by model basename. */
    private const ENTITIES = [
        'CrmLead' => 'Lead', 'CrmContact' => 'Contact', 'CrmAccount' => 'Account', 'CrmDeal' => 'Deal',
        'CrmWorkflow' => 'Workflow', 'CrmApproval' => 'Approval', 'CrmPipeline' => 'Pipeline',
        'CrmWebhook' => 'Webhook', 'CrmAiSystem' => 'AI System',
    ];

    public function index(Request $request): Response
    {
        $activities = $this->query($request)->latest()->limit(300)->get()
            ->map(fn ($a) => $this->present($a));

        return Inertia::render('Modules/CRM/AuditTrail', [
            'activities' => $activities,
            'entities'   => array_values(self::ENTITIES),
            'actions'    => ['created', 'updated', 'deleted'],
            'filters'    => $request->only('entity', 'action', 'from', 'to'),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $rows = $this->query($request)->latest()->limit(5000)->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['when', 'entity', 'entity_id', 'action', 'by', 'changes']);
            foreach ($rows as $a) {
                $p = $this->present($a);
                fputcsv($out, [
                    $a->created_at?->toDateTimeString(),
                    $p['entity'], $p['entity_id'], $p['action'], $p['by'],
                    collect($p['changes'])->map(fn ($c) => "{$c['field']}: {$c['old']} -> {$c['new']}")->implode('; '),
                ]);
            }
            fclose($out);
        }, 'crm-audit-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv']);
    }

    // ── Internals ──────────────────────────────────────────────────────────────

    private function query(Request $request)
    {
        $q = Activity::with('causer.person')->where('log_name', 'CRM');

        if ($request->filled('entity')) {
            $class = array_search($request->entity, self::ENTITIES, true);
            if ($class) $q->where('subject_type', 'like', '%\\' . $class);
        }
        if ($request->filled('action')) $q->where('event', $request->action);
        if ($request->filled('from'))   $q->whereDate('created_at', '>=', $request->from);
        if ($request->filled('to'))     $q->whereDate('created_at', '<=', $request->to);

        return $q;
    }

    private function present(Activity $a): array
    {
        $basename = class_basename((string) $a->subject_type);
        $props    = $a->properties ?? collect();
        $new      = (array) ($props['attributes'] ?? []);
        $old      = (array) ($props['old'] ?? []);

        $changes = [];
        foreach ($new as $field => $value) {
            if (in_array($field, ['updated_at', 'created_at'])) continue;
            $before = $old[$field] ?? null;
            if ($a->event === 'updated' && $before === $value) continue;
            $changes[] = [
                'field' => $field,
                'old'   => $this->scalar($before),
                'new'   => $this->scalar($value),
            ];
        }

        return [
            'id'        => $a->id,
            'entity'    => self::ENTITIES[$basename] ?? ($basename ?: 'Record'),
            'entity_id' => $a->subject_id,
            'action'    => $a->event ?? $a->description,
            'by'        => $a->causer?->person?->first_name . ' ' . $a->causer?->person?->surname,
            'changes'   => $changes,
            'at'        => $a->created_at?->diffForHumans(),
            'at_full'   => $a->created_at?->toDateTimeString(),
        ];
    }

    private function scalar($v): string
    {
        if (is_null($v)) return '—';
        if (is_bool($v)) return $v ? 'true' : 'false';
        if (is_array($v)) return Str::limit(json_encode($v), 60);
        return Str::limit((string) $v, 60);
    }
}
