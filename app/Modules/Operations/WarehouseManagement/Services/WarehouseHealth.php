<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Is the warehouse machinery running? (WMS-212). Each check is green, amber or red with a sentence
 * saying why, so the page and the monitoring command tell the same story.
 */
class WarehouseHealth
{
    /** Scheduled commands and how often each is expected to finish, in minutes. */
    public const SCHEDULE = [
        'wms:outbox' => 1, 'wms:replenish' => 30, 'wms:fulfilment' => 10, 'wms:notify' => 15, 'wms:counts' => 1440, 'wms:expiry' => 1440,
        'wms:reconcile' => 1440, 'wms:protect' => 1440, 'wms:retention' => 1440, 'wms:verify-audit' => 1440, 'wms:verify-ledger' => 1440, 'wms:kpi-snapshot' => 44640,
    ];

    public static function started(string $command): void
    {
        DB::table('wms_heartbeats')->upsert([['command' => $command, 'started_at' => now()]], ['command'], ['started_at']);
    }

    public static function finished(string $command, bool $ok, array $details = []): void
    {
        DB::table('wms_heartbeats')->upsert([['command' => $command, 'finished_at' => now(), 'ok' => $ok, 'details' => json_encode($details)]], ['command'], ['finished_at', 'ok', 'details']);
        DB::table('wms_heartbeats')->where('command', $command)->update(['runs' => DB::raw('runs + 1'), 'failures' => DB::raw($ok ? 'failures' : 'failures + 1')]);
    }

    /** @return list<array{check: string, state: string, detail: string}> */
    public function checks(?int $companyId = null): array
    {
        $out = [];
        $beats = DB::table('wms_heartbeats')->get()->keyBy('command');
        foreach (self::SCHEDULE as $cmd => $every) {
            $b = $beats[$cmd] ?? null;
            $age = $b?->finished_at ? Carbon::parse($b->finished_at)->diffInMinutes(now()) : null;
            [$state, $detail] = match (true) {
                $b === null || $b->finished_at === null => ['amber', 'Has not run on this server yet.'],
                ! $b->ok => ['red', 'The last run failed at '.Carbon::parse($b->finished_at)->toDayDateTimeString().'.'],
                $age > $every * 3 + 5 => ['red', "Last finished {$age} minutes ago; expected every {$every}. The scheduler may have stopped."],
                default => ['green', "Last finished {$age} minute(s) ago."],
            };
            $out[] = ['check' => $cmd, 'state' => $state, 'detail' => $detail];
        }

        $oldest = DB::table('jobs')->min('available_at');
        $wait = $oldest ? (int) round((now()->timestamp - (int) $oldest) / 60) : 0;
        $out[] = ['check' => 'queue', 'state' => $wait > 30 ? 'red' : ($wait > 5 ? 'amber' : 'green'),
            'detail' => $oldest ? "The oldest waiting job has waited {$wait} minute(s)." : 'No jobs are waiting.'];
        $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
        $out[] = ['check' => 'failed_jobs', 'state' => $failed > 0 ? 'amber' : 'green', 'detail' => "{$failed} background job(s) failed in the last day."];

        $scope = fn ($q) => $companyId ? $q->where('company_id', $companyId) : $q;
        $backlog = $scope(DB::table('wms_outbox'))->whereNull('published_at')->whereNull('failed_at')->where('created_at', '<', now()->subMinutes(10))->count();
        $dead = $scope(DB::table('wms_outbox'))->whereNotNull('failed_at')->count();
        $out[] = ['check' => 'events', 'state' => $dead > 0 ? 'red' : ($backlog > 0 ? 'amber' : 'green'), 'detail' => "{$backlog} event(s) waiting over 10 minutes; {$dead} dead letter(s)."];
        $msgs = $scope(DB::table('mfg_integration_events'))->where('event_type', 'like', 'WMS_%')->where('status', 'DEAD')->count();
        $out[] = ['check' => 'integrations', 'state' => $msgs > 0 ? 'red' : 'green', 'detail' => "{$msgs} message(s) to or from connected systems gave up."];
        $conflicts = $scope(DB::table('wms_offline_actions'))->where('status', 'CONFLICT')->count();
        $out[] = ['check' => 'offline', 'state' => $conflicts > 0 ? 'amber' : 'green', 'detail' => "{$conflicts} offline scan(s) wait for a decision."];

        $ledger = json_decode((string) ($beats['wms:verify-ledger']->details ?? ''), true);
        if ($companyId && isset($ledger[$companyId])) {
            $n = (int) $ledger[$companyId];
            $out[] = ['check' => 'ledger', 'state' => $n > 0 ? 'red' : 'green', 'detail' => $n > 0 ? "{$n} difference(s) between projections and the ledger at the last check." : 'Balances agreed with the ledger at the last check.'];
        }

        return $out;
    }
}
