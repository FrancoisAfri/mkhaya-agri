<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

/**
 * Platform-admin system monitoring: queue health, failed jobs, AI usage,
 * notification delivery, database/storage size and server health. Read-only.
 * Mirrors mkhaya-prop's SystemMonitorController, adapted to hub's tables.
 */
class SystemController extends Controller
{
    public function index()
    {
        return Inertia::render('Tenancy/System', [
            'queue' => $this->queue(),
            'ai' => $this->aiUsage(),
            'notifications' => $this->notifications(),
            'database' => $this->database(),
            'tenancy' => $this->tenancy(),
            'system' => $this->system(),
            'generated_at' => now()->format('Y-m-d H:i:s'),
        ]);
    }

    public function retryFailed()
    {
        Artisan::call('queue:retry', ['id' => ['all']]);

        return back()->with('success', 'Queued all failed jobs for retry.');
    }

    public function flushFailed()
    {
        Artisan::call('queue:flush');

        return back()->with('success', 'Cleared the failed jobs table.');
    }

    private function queue(): array
    {
        $pending = Schema::hasTable('jobs') ? (int) DB::table('jobs')->count() : 0;
        $failed = (int) DB::table('failed_jobs')->count();

        $oldest = Schema::hasTable('jobs') ? DB::table('jobs')->min('available_at') : null;
        $oldestAge = $oldest ? Carbon::createFromTimestamp($oldest)->diffForHumans() : null;

        $failed24 = (int) DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();

        $recent = DB::table('failed_jobs')->latest('failed_at')->limit(15)->get()
            ->map(function ($j) {
                $payload = json_decode($j->payload, true);
                $name = $payload['displayName'] ?? ($payload['data']['commandName'] ?? 'job');
                $firstLine = strtok(trim((string) $j->exception), "\n");

                return [
                    'id' => $j->id, 'queue' => $j->queue, 'name' => $name,
                    'error' => mb_strimwidth((string) $firstLine, 0, 160, '…'),
                    'failed_at' => Carbon::parse($j->failed_at)->format('Y-m-d H:i'),
                ];
            });

        return compact('pending', 'failed', 'failed24', 'oldestAge') + ['recent' => $recent];
    }

    private function aiUsage(): array
    {
        $hasUsage = Schema::hasTable('ai_usage_log');
        // audit_ai_calls is the central audit every Core AI driver writes (via
        // AIAuditService); ai_usage_log is written by a couple of features. Combine
        // both so the monitor reflects ALL AI activity (billing reads ai_usage_log
        // separately and is unaffected).
        $hasAudit = Schema::hasTable('audit_ai_calls');
        if (! $hasUsage && ! $hasAudit) {
            return ['available' => false];
        }

        $since = now()->subDays(30);
        $today = $calls30 = $tokens30 = $okCount = 0;
        $cost30 = 0.0;
        $latSum = 0.0;
        $latN = 0;
        $features = [];

        if ($hasUsage) {
            $u = DB::table('ai_usage_log');
            $today += (int) (clone $u)->whereDate('created_at', today())->count();
            $u30 = (clone $u)->where('created_at', '>=', $since);
            $calls30 += (int) (clone $u30)->count();
            $tokens30 += (int) (clone $u30)->sum('total_tokens');
            $cost30 += (float) (clone $u30)->sum('cost_usd');
            $okCount += (int) (clone $u30)->where('success', true)->count();
            $latSum += (float) (clone $u30)->sum('latency_ms');
            $latN += (int) (clone $u30)->whereNotNull('latency_ms')->count();
            foreach ((clone $u30)->select('feature', DB::raw('count(*) as n'), DB::raw('sum(total_tokens) as toks'))->groupBy('feature')->get() as $r) {
                $k = $r->feature ?: 'unknown';
                $features[$k] = ['calls' => ($features[$k]['calls'] ?? 0) + (int) $r->n, 'tokens' => ($features[$k]['tokens'] ?? 0) + (int) $r->toks];
            }
        }

        if ($hasAudit) {
            $a = DB::table('audit_ai_calls');
            $today += (int) (clone $a)->whereDate('created_at', today())->count();
            $a30 = (clone $a)->where('created_at', '>=', $since);
            $auditCalls = (int) (clone $a30)->count();
            $calls30 += $auditCalls;
            $tokens30 += (int) (clone $a30)->sum('tokens_used');
            $okCount += $auditCalls; // audit rows are recorded post-call; treated as delivered
            $latSum += (float) (clone $a30)->sum('duration_ms');
            $latN += (int) (clone $a30)->whereNotNull('duration_ms')->count();
            foreach ((clone $a30)->select('module', DB::raw('count(*) as n'), DB::raw('sum(tokens_used) as toks'))->groupBy('module')->get() as $r) {
                $k = $r->module ?: 'core';
                $features[$k] = ['calls' => ($features[$k]['calls'] ?? 0) + (int) $r->n, 'tokens' => ($features[$k]['tokens'] ?? 0) + (int) $r->toks];
            }
        }

        uasort($features, fn ($a, $b) => $b['calls'] <=> $a['calls']);
        $byFeature = collect($features)->take(8)->map(fn ($v, $k) => ['feature' => $k, 'calls' => $v['calls'], 'tokens' => $v['tokens']])->values();

        return [
            'available' => true,
            'today' => $today, 'calls30' => $calls30, 'tokens30' => $tokens30,
            'cost30' => round($cost30, 2),
            'success_rate' => $calls30 ? round($okCount / $calls30 * 100, 1) : 100.0,
            'avg_latency_ms' => $latN ? (int) round($latSum / $latN) : 0,
            'by_feature' => $byFeature,
        ];
    }

    private function notifications(): array
    {
        if (! Schema::hasTable('notification_logs')) {
            return ['available' => false];
        }
        $base = DB::table('notification_logs')->where('created_at', '>=', now()->subDay());

        $sent = (int) (clone $base)->where('status', 'sent')->count();
        $failed = (int) (clone $base)->where('status', 'failed')->count();
        $byChannel = (clone $base)->select('channel', DB::raw('count(*) as n'))
            ->groupBy('channel')->get()->map(fn ($r) => ['channel' => $r->channel ?: 'mail', 'count' => (int) $r->n]);

        return ['available' => true, 'sent24' => $sent, 'failed24' => $failed, 'by_channel' => $byChannel];
    }

    private function database(): array
    {
        try {
            if (DB::getDriverName() !== 'pgsql') {
                return ['available' => false, 'error' => 'Database stats are only available on PostgreSQL.'];
            }
            $size = DB::selectOne('SELECT pg_database_size(current_database()) AS bytes');
            $bytes = (int) ($size->bytes ?? 0);

            $tables = DB::select(<<<'SQL'
                SELECT relname AS name, pg_total_relation_size(c.oid) AS bytes
                FROM pg_class c
                JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = 'public' AND c.relkind = 'r'
                ORDER BY pg_total_relation_size(c.oid) DESC
                LIMIT 10
            SQL);

            $top = array_map(fn ($t) => [
                'name' => $t->name,
                'size' => $this->humanBytes((int) $t->bytes),
                'bytes' => (int) $t->bytes,
            ], $tables);

            return ['available' => true, 'size' => $this->humanBytes($bytes), 'bytes' => $bytes, 'top_tables' => $top];
        } catch (\Throwable $e) {
            return ['available' => false, 'error' => $e->getMessage()];
        }
    }

    private function tenancy(): array
    {
        $byStatus = Company::query()
            ->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status');

        return [
            'companies' => (int) Company::query()->count(),
            'active' => (int) ($byStatus['active'] ?? 0),
            'trial' => (int) ($byStatus['trial'] ?? 0),
            'suspended' => (int) ($byStatus['suspended'] ?? 0),
            'users' => (int) DB::table('users')->count(),
        ];
    }

    private function system(): array
    {
        $logFile = storage_path('logs/laravel.log');
        $logSize = is_file($logFile) ? filesize($logFile) : 0;
        $errors24 = $this->countRecentLogErrors($logFile);

        $storagePath = storage_path();
        $diskFree = @disk_free_space($storagePath) ?: 0;
        $diskTotal = @disk_total_space($storagePath) ?: 0;

        return [
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'environment' => app()->environment(),
            'debug' => (bool) config('app.debug'),
            'edition' => config('edition.edition', 'saas'),
            'queue_connection' => config('queue.default'),
            'cache_driver' => config('cache.default'),
            'log_size' => $this->humanBytes($logSize),
            'recent_errors' => $errors24,
            'disk_free' => $this->humanBytes((int) $diskFree),
            'disk_total' => $this->humanBytes((int) $diskTotal),
            'disk_used_pct' => $diskTotal > 0 ? round(($diskTotal - $diskFree) / $diskTotal * 100, 1) : 0,
            'server_time' => now()->format('Y-m-d H:i:s'),
        ];
    }

    private function countRecentLogErrors(string $file): int
    {
        if (! is_file($file)) {
            return 0;
        }
        $size = filesize($file);
        $read = min($size, 512 * 1024);
        $fh = fopen($file, 'r');
        if ($read < $size) {
            fseek($fh, -$read, SEEK_END);
        }
        $chunk = (string) fread($fh, $read);
        fclose($fh);

        return preg_match_all('/\.(ERROR|CRITICAL|EMERGENCY|ALERT):/', $chunk);
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $n = (float) $bytes;
        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }

        return round($n, $i === 0 ? 0 : 1).' '.$units[$i];
    }
}
