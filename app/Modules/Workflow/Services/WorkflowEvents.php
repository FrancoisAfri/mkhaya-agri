<?php

namespace App\Modules\Workflow\Services;

use App\Modules\Workflow\Events\WorkflowEventOccurred;
use Illuminate\Support\Facades\DB;

/**
 * The workflow event outbox (PRS §19, §21): publish inside the change's transaction, deliver from
 * workflow:tick. Events carry identifiers and a correlation id, not confidential payloads.
 */
class WorkflowEvents
{
    public const MAX_ATTEMPTS = 5;

    public const NAMES = [
        'workflow.instance.started', 'workflow.task.assigned', 'workflow.task.completed',
        'workflow.approval.approved', 'workflow.approval.rejected', 'workflow.sla.warning', 'workflow.sla.breached',
        'workflow.action.failed', 'workflow.instance.completed', 'workflow.instance.rejected',
        'workflow.instance.cancelled', 'workflow.instance.failed',
    ];

    public function __construct(private readonly WorkflowAudit $audit)
    {
    }

    /** Record an event. The same name and payload published again is the same event and is ignored. */
    public function publish(string $name, array $payload): void
    {
        ksort($payload);
        DB::table('workflow_events')->insertOrIgnore([
            'company_id' => $payload['company_id'] ?? null, 'name' => $name, 'payload' => json_encode($payload),
            'dedupe_key' => hash('sha256', $name . '|' . json_encode($payload)),
            'correlation_id' => $this->audit->correlationId(),
            'status' => 'pending', 'attempts' => 0, 'available_at' => now(), 'created_at' => now(),
        ]);
    }

    /** @return array{delivered:int, failed:int} */
    public function deliver(int $limit = 500): array
    {
        $stats = ['delivered' => 0, 'failed' => 0];
        $due = DB::table('workflow_events')->where('status', 'pending')->where('available_at', '<=', now())->orderBy('id')->limit($limit)->get();

        foreach ($due as $e) {
            $claimed = DB::table('workflow_events')->where('id', $e->id)->where('status', 'pending')->where('attempts', $e->attempts)
                ->update(['attempts' => $e->attempts + 1]);
            if (! $claimed) {
                continue;
            }
            try {
                event(new WorkflowEventOccurred((int) $e->id, $e->name, json_decode($e->payload, true) ?: [], $e->company_id, $e->correlation_id));
                DB::table('workflow_events')->where('id', $e->id)->update(['status' => 'delivered', 'delivered_at' => now(), 'last_error' => null]);
                $stats['delivered']++;
            } catch (\Throwable $ex) {
                report($ex);
                $attempts = $e->attempts + 1;
                DB::table('workflow_events')->where('id', $e->id)->update([
                    'status' => $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending',
                    'last_error' => mb_substr(class_basename($ex) . ': ' . $ex->getMessage(), 0, 1000),
                    'available_at' => now()->addMinutes(2 ** $attempts),
                ]);
                $stats['failed']++;
            }
        }

        return $stats;
    }

    public function retry(int $id): bool
    {
        return (bool) DB::table('workflow_events')->where('id', $id)->where('status', 'failed')->update(['status' => 'pending', 'attempts' => 0, 'available_at' => now()]);
    }
}
