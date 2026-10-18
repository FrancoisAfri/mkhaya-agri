<?php

namespace App\Modules\Finance\Tax\Support;

use App\Modules\Finance\Tax\Events\TaxEventOccurred;
use Illuminate\Support\Facades\DB;

/**
 * The tax event outbox (§19): publish inside the business transaction, deliver afterwards.
 */
class TaxEvents
{
    public const MAX_ATTEMPTS = 5;

    /** Record an event. The same name and payload published again is the same event and is ignored. */
    public function publish(string $name, array $payload): void
    {
        ksort($payload);
        $companyId = $payload['company_id'] ?? null;
        DB::table('tax_events')->insertOrIgnore([
            'company_id' => $companyId, 'name' => $name, 'payload' => json_encode($payload),
            'dedupe_key' => hash('sha256', $name . '|' . json_encode($payload)),
            'status' => 'pending', 'attempts' => 0, 'available_at' => now(), 'created_at' => now(),
        ]);
    }

    /**
     * Deliver what is due. At-least-once: an event is marked delivered only after every listener
     * returned; a failure is retried with backoff and given up on after MAX_ATTEMPTS, staying
     * visible as failed.
     *
     * @return array{delivered:int, failed:int}
     */
    public function deliver(int $limit = 500): array
    {
        $stats = ['delivered' => 0, 'failed' => 0];
        $due = DB::table('tax_events')->where('status', 'pending')->where('available_at', '<=', now())->orderBy('id')->limit($limit)->get();

        foreach ($due as $e) {
            // Claim it: a second runner that read the same row skips it.
            $claimed = DB::table('tax_events')->where('id', $e->id)->where('status', 'pending')->where('attempts', $e->attempts)
                ->update(['attempts' => $e->attempts + 1]);
            if (! $claimed) {
                continue;
            }
            try {
                event(new TaxEventOccurred((int) $e->id, $e->name, json_decode($e->payload, true) ?: [], $e->company_id));
                DB::table('tax_events')->where('id', $e->id)->update(['status' => 'delivered', 'delivered_at' => now(), 'last_error' => null]);
                $stats['delivered']++;
            } catch (\Throwable $ex) {
                report($ex);
                $attempts = $e->attempts + 1;
                DB::table('tax_events')->where('id', $e->id)->update([
                    'status' => $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending',
                    'last_error' => mb_substr(class_basename($ex) . ': ' . $ex->getMessage(), 0, 1000),
                    'available_at' => now()->addMinutes(2 ** $attempts),
                ]);
                $stats['failed']++;
            }
        }

        return $stats;
    }

    /** Put a failed event back in the queue (after its listener was fixed). */
    public function retry(int $id): void
    {
        DB::table('tax_events')->where('id', $id)->where('status', 'failed')->update(['status' => 'pending', 'attempts' => 0, 'available_at' => now()]);
    }
}
