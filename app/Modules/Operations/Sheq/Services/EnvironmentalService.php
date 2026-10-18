<?php

namespace App\Modules\Operations\Sheq\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Environmental monitoring — the "E" in SHEQ (§10 of the scope).
 *
 * Water, electricity, diesel, waste, recycling and emissions, recorded as meter readings.
 *
 * **Readings, not totals.** A carbon figure whose inputs cannot be produced is not auditable, and
 * the entire reason a company records this is that somebody — a customer's procurement team, a
 * B-BBEE verification agency, an environmental authorisation — will eventually ask them to prove
 * it. Storing "142 tonnes CO2e" and nothing else fails that question.
 *
 * **The emission factor is stored with the reading, not looked up at report time.** Grid intensity
 * changes as the generation mix changes; diesel factors get revised. If the factor were applied at
 * report time, last year's published total would silently move every time the constant was updated,
 * and a restated figure nobody intended to restate is worse than a stale one.
 */
class EnvironmentalService
{
    public const ASPECTS = ['WATER', 'ELECTRICITY', 'DIESEL', 'PETROL', 'GAS', 'WASTE',
        'RECYCLING', 'EMISSIONS'];

    /** Record a reading for a meter on a date. */
    public function record(int $companyId, array $data, ?int $actorId = null): array
    {
        $meterId = (int) ($data['meter_id'] ?? 0);

        $meter = DB::table('sheq_environmental_meters')->where('company_id', $companyId)
            ->where('id', $meterId)->whereNull('deleted_at')
            ->first(['id', 'name', 'unit', 'carbon_factor', 'aspect', 'site_id',
                'threshold_value', 'warning_value', 'threshold_source', 'capa_on_breach',
                'investigate_on_breach']);

        if ($meter === null) {
            throw new RuntimeException('That meter does not exist in this company.');
        }

        $value = $data['value'] ?? null;

        if (! is_numeric($value) || (float) $value < 0) {
            throw new InvalidArgumentException(
                'A reading must be a non-negative number. A negative consumption is a data-entry '
                .'error, and letting one through would understate the period it lands in.'
            );
        }

        $date = Carbon::parse($data['reading_date'] ?? now())->toDateString();

        $factor = $meter->carbon_factor === null ? null : (float) $meter->carbon_factor;

        $uuid = (string) Str::uuid();

        try {
            $id = DB::table('sheq_environmental_readings')->insertGetId([
                'uuid' => $uuid,
                'company_id' => $companyId,
                'meter_id' => $meterId,
                'reading_date' => $date,
                'value' => (float) $value,
                // Stamped with the reading, so a recomputation next year reproduces this number.
                'carbon_factor_applied' => $factor,
                'co2e_kg' => $factor === null ? null : round((float) $value * $factor, 4),
                'source' => $data['source'] ?? 'MANUAL',
                'file_id' => $data['file_id'] ?? null,
                'note' => $data['note'] ?? null,
                'recorded_by' => $actorId,
                /*
                 * Whether this reading breached its limit, decided now and stamped (ENV-5).
                 *
                 * Stamped rather than recomputed because a threshold changes — a licence is
                 * renewed on different terms — and a reading judged against today's limit would
                 * rewrite whether last year was compliant.
                 */
                'breached_threshold' => $meter->threshold_value !== null
                    && (float) $value > (float) $meter->threshold_value,
                'threshold_at_reading' => $meter->threshold_value,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new RuntimeException(sprintf(
                'A reading for %s on %s already exists. Two readings for one meter on one day '
                .'would double that day’s consumption — edit the existing reading instead.',
                $meter->name, $date,
            ));
        }

        $breach = $meter->threshold_value !== null
            && (float) $value > (float) $meter->threshold_value;

        $warning = ! $breach && $meter->warning_value !== null
            && (float) $value > (float) $meter->warning_value;

        $action = $breach ? $this->raiseBreachAction($companyId, $meter, $id, (float) $value,
            $actorId) : null;

        return ['id' => $id, 'uuid' => $uuid,
            'co2e_kg' => $factor === null ? null : round((float) $value * $factor, 4),
            'breached' => $breach,
            'warning' => $warning,
            'threshold' => $meter->threshold_value === null
                ? null : (float) $meter->threshold_value,
            'action_reference' => $action['reference'] ?? null];
    }

    /**
     * What a breach produces (ENV-5).
     *
     * An alert always, a corrective action where the meter says so, and an investigation where
     * the limit warrants one. The configuration is per METER rather than global because the
     * limits are not comparable: exceeding a dust limit for an hour is a housekeeping failure,
     * and exceeding an effluent licence condition is a reportable contravention.
     *
     * Never fails the reading. A meter reading refused because the alerting failed is a reading
     * that does not get recorded at all, and the record matters more than the alert.
     *
     * @return array<string,mixed>|null
     */
    private function raiseBreachAction(int $companyId, object $meter, int $readingId,
        float $value, ?int $actorId): ?array
    {
        /*
         * Each step inside its own savepoint, and this is not belt-and-braces.
         *
         * On Postgres a statement that errors inside a transaction aborts the WHOLE transaction:
         * every later statement is refused until somebody rolls back. So catching the exception
         * and carrying on means the alert failing takes the corrective action down with it, the
         * reading's own insert is already committed but unusable, and the caller gets a
         * "transaction is aborted" error from a service that was built to swallow failures.
         * Rolling back to a savepoint is the only recovery Postgres offers, and it is permitted
         * even in an aborted transaction.
         */
        $alert = $this->safely(fn () => DB::table('sheq_notification_dispatches')->insertOrIgnore([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'event_type' => 'sheq.environmental.threshold_exceeded',
            'subject_type' => 'SheqEnvironmentalReading',
            'subject_id' => $readingId,
            'subject_reference' => $meter->name,
            'channel' => 'EMAIL',
            // Required columns this table has and the contractor notifier also fills: a queue
            // row with no recipient kind and no send time is a row the drain cannot act on.
            'recipient_kind' => 'ROLE',
            // varchar(40), so this stays short. The longer sentence failed silently.
            'trigger_reason' => 'Monitoring limit exceeded',
            'severity' => 'HIGH',
            'send_after' => now(),
            'status' => 'PENDING',
            'attempts' => 0,
            'dedupe_key' => 'env-breach-'.$readingId,
            'payload' => json_encode([
                'title' => sprintf('%s exceeded its limit: %s against %s',
                    $meter->name, $value, $meter->threshold_value),
                'body' => sprintf(
                    'A reading of %s was recorded against a limit of %s%s.',
                    $value, $meter->threshold_value,
                    $meter->threshold_source ? ' ('.$meter->threshold_source.')' : ''),
            ]),
            'created_at' => now(), 'updated_at' => now(),
        ]));

        if (! $alert['ok']) {
            report($alert['error']);
        }

        if (! $meter->capa_on_breach) {
            return null;
        }

        $raise = $this->safely(fn () => app(ActionService::class)->create($companyId, [
                'action_type' => 'CORRECTIVE',
                'title' => sprintf('%s exceeded its limit (%s against %s)',
                    $meter->name, $value, $meter->threshold_value),
                'description' => sprintf(
                    'A reading of %s was recorded against a limit of %s%s. Investigate the cause '
                    .'and bring it back within the limit.',
                    $value, $meter->threshold_value,
                    $meter->threshold_source ? ' ('.$meter->threshold_source.')' : ''),
                /*
                 * `ENV_READING`, not `ENVIRONMENTAL_READING`. `sheq_actions.source_type` is
                 * varchar(20) and the longer name is twenty-one characters, so it was silently
                 * failing the insert — which the savepoint isolation above is the only reason
                 * anybody saw, because the catch had been swallowing it.
                 *
                 * The same short form is used for the investigation subject so there is one name
                 * for one thing.
                 */
                'source_type' => 'ENV_READING',
                'source_id' => $readingId,
                'site_id' => $meter->site_id,
            'priority' => 'HIGH',
        ], $actorId));

        if (! $raise['ok']) {
            report($raise['error']);

            return null;
        }

        $action = $raise['value'];

        DB::table('sheq_environmental_readings')->where('id', $readingId)
            ->update(['action_id' => $action['id'], 'updated_at' => now()]);

        if ($meter->investigate_on_breach) {
            /*
             * The same root-cause engine incidents and non-conformances use. A limit exceedance
             * that warrants an investigation warrants a real one, with a method and a minimum
             * depth — not a free-text box called "cause".
             */
            $opened = $this->safely(fn () => app(InvestigationService::class)
                ->openForSubject($companyId, 'ENV_READING', $readingId, 3, null,
                    $actorId));

            if (! $opened['ok']) {
                report($opened['error']);
            }
        }

        return $action;
    }

    /**
     * Run one side effect so that its failure does not take the transaction with it.
     *
     * A plain try/catch is not isolation on Postgres. A statement that errors inside a
     * transaction aborts the WHOLE transaction — every later statement refused until somebody
     * rolls back — so swallowing the exception means the alert failing takes the corrective
     * action down with it and the caller gets "transaction is aborted" from a service built to
     * swallow failures. Rolling back to a savepoint is the only recovery Postgres offers, and it
     * is permitted even in an aborted transaction.
     *
     * Outside a transaction this costs one function call and takes no savepoint.
     *
     * @return array{ok:bool, value:mixed, error:?Throwable}
     */
    private function safely(callable $run): array
    {
        $nested = DB::transactionLevel() > 0;

        if ($nested) {
            DB::statement('SAVEPOINT sheq_env_side_effect');
        }

        try {
            $value = $run();

            if ($nested) {
                DB::statement('RELEASE SAVEPOINT sheq_env_side_effect');
            }

            return ['ok' => true, 'value' => $value, 'error' => null];
        } catch (Throwable $e) {
            if ($nested) {
                DB::statement('ROLLBACK TO SAVEPOINT sheq_env_side_effect');
            }

            return ['ok' => false, 'value' => null, 'error' => $e];
        }
    }

    /**
     * Consumption and carbon for a period, by aspect.
     *
     * @return array{period:string, aspects:list<array<string,mixed>>, total_co2e_kg:float,
     *               meters_without_factor:list<string>}
     */
    public function summary(int $companyId, string $from, string $to, ?int $siteId = null): array
    {
        $rows = DB::table('sheq_environmental_readings as r')
            ->join('sheq_environmental_meters as m', 'm.id', '=', 'r.meter_id')
            ->where('r.company_id', $companyId)
            ->whereBetween('r.reading_date', [$from, $to])
            ->when($siteId !== null, fn ($q) => $q->where('m.site_id', $siteId))
            ->groupBy('m.aspect', 'm.unit')
            ->selectRaw('m.aspect, m.unit, sum(r.value) as total, sum(coalesce(r.co2e_kg, 0)) as co2e')
            ->get();

        // Named explicitly rather than quietly counted as zero. A meter with no emission factor
        // contributes nothing to the carbon total, and a total that silently excludes a site's
        // diesel is worse than one that says which meters are missing a factor.
        $unfactored = DB::table('sheq_environmental_meters')
            ->where('company_id', $companyId)->whereNull('carbon_factor')
            ->whereNull('deleted_at')->where('is_active', true)
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->pluck('name')->all();

        return [
            'period' => "{$from} to {$to}",
            'aspects' => $rows->map(fn ($r) => [
                'aspect' => $r->aspect,
                'unit' => $r->unit,
                'total' => (float) $r->total,
                'co2e_kg' => (float) $r->co2e,
            ])->values()->all(),
            'total_co2e_kg' => round((float) $rows->sum('co2e'), 2),
            'meters_without_factor' => $unfactored,
        ];
    }

    /**
     * Month-on-month movement per aspect, which is what a management review actually discusses.
     *
     * @return list<array<string,mixed>>
     */
    public function trend(int $companyId, int $months = 12, ?int $siteId = null): array
    {
        $since = Carbon::today()->subMonths($months)->startOfMonth();

        return DB::table('sheq_environmental_readings as r')
            ->join('sheq_environmental_meters as m', 'm.id', '=', 'r.meter_id')
            ->where('r.company_id', $companyId)
            ->where('r.reading_date', '>=', $since->toDateString())
            ->when($siteId !== null, fn ($q) => $q->where('m.site_id', $siteId))
            ->groupBy('m.aspect', DB::raw("to_char(r.reading_date, 'YYYY-MM')"))
            ->selectRaw("m.aspect, to_char(r.reading_date, 'YYYY-MM') as period, "
                .'sum(r.value) as total, sum(coalesce(r.co2e_kg, 0)) as co2e')
            ->orderBy('period')
            ->get()
            ->map(fn ($r) => [
                'aspect' => $r->aspect,
                'period' => $r->period,
                'total' => (float) $r->total,
                'co2e_kg' => (float) $r->co2e,
            ])->values()->all();
    }

    /**
     * Meters that have stopped being read.
     *
     * The most common failure of an environmental register is not a wrong number — it is a meter
     * nobody has read since the person who used to do it left, producing a total that looks fine
     * and is missing a building.
     */
    public function staleMeters(int $companyId, int $days = 45): array
    {
        $cutoff = Carbon::today()->subDays($days)->toDateString();

        return DB::table('sheq_environmental_meters as m')
            ->leftJoin(DB::raw('(select meter_id, max(reading_date) as last_read
                from sheq_environmental_readings group by meter_id) r'),
                'r.meter_id', '=', 'm.id')
            ->where('m.company_id', $companyId)->where('m.is_active', true)
            ->whereNull('m.deleted_at')
            ->where(fn ($q) => $q->whereNull('r.last_read')->orWhere('r.last_read', '<', $cutoff))
            ->get(['m.uuid', 'm.name', 'm.aspect', 'm.unit', 'r.last_read'])
            ->map(fn ($r) => [
                'uuid' => $r->uuid,
                'name' => $r->name,
                'aspect' => $r->aspect,
                'last_read' => $r->last_read,
                'never_read' => $r->last_read === null,
            ])->values()->all();
    }
}
