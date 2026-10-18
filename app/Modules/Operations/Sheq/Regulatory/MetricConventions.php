<?php

namespace App\Modules\Operations\Sheq\Regulatory;

use InvalidArgumentException;

/**
 * Injury frequency rates, computed from the pack's conventions (SHEQ-REG-7).
 *
 * Multipliers are jurisdictional, not universal: LTIFR is per 1 000 000 hours in most of the world,
 * while South African mining reports DIFR per 200 000. Hard-coding either would silently misreport
 * one of them by a factor of five, which is the kind of error that reaches a board pack and a
 * regulator before anyone notices. So the numbers come from the pack.
 *
 * Packs declare `numerator`, `denominator` and `multiplier` rather than a free-text formula. The
 * arithmetic is what the specification's formulas describe; expressing it as data keeps untrusted
 * pack content away from any expression evaluator (see PackLoader for the same reasoning).
 */
class MetricConventions
{
    public function __construct(private readonly PackManifest $pack) {}

    /** @return list<array<string,mixed>> */
    public function definitions(): array
    {
        return array_values((array) ($this->pack->metrics()['definitions'] ?? []));
    }

    public function definition(string $metricId): ?array
    {
        foreach ($this->definitions() as $definition) {
            if (strcasecmp((string) ($definition['id'] ?? ''), $metricId) === 0) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * @param  array<string,float|int>  $counters  e.g. ['lost_time_injuries' => 3, 'hours_worked' => 450000]
     * @return float|null null when the denominator is zero or absent — a period with no exposure
     *                    hours has no rate, and reporting 0.0 would read as "perfect safety"
     */
    public function compute(string $metricId, array $counters): ?float
    {
        $definition = $this->definition($metricId);

        if ($definition === null) {
            throw new InvalidArgumentException(
                "Metric `{$metricId}` is not defined by pack {$this->pack->packId}@{$this->pack->version}."
            );
        }

        $numerator = (float) ($counters[$definition['numerator'] ?? ''] ?? 0);
        $denominator = (float) ($counters[$definition['denominator'] ?? ''] ?? 0);
        $multiplier = (float) ($definition['multiplier'] ?? 1);

        if ($denominator <= 0.0) {
            return null;
        }

        return ($numerator * $multiplier) / $denominator;
    }

    /**
     * Whether a treatment level counts as recordable under this jurisdiction (SHEQ-REG-7).
     * Drives TRIR and the recordable case count, and differs by jurisdiction — commute incidents
     * are recordable in some regimes and not in South Africa.
     */
    public function isRecordable(string $treatmentLevel, bool $isCommute = false): bool
    {
        $rules = (array) ($this->pack->metrics()['recordability'] ?? []);

        if ($isCommute) {
            return (bool) ($rules['commute_incidents'] ?? false);
        }

        return match (strtoupper($treatmentLevel)) {
            'FIRST_AID' => (bool) ($rules['first_aid_only'] ?? false),
            'RESTRICTED_WORK' => (bool) ($rules['restricted_work'] ?? true),
            'MEDICAL_TREATMENT' => (bool) ($rules['medical_treatment_beyond_first_aid'] ?? true),
            'LOST_TIME', 'PERMANENT_DISABILITY', 'FATALITY' => true,
            default => false,
        };
    }

    public function exposureBase(): string
    {
        return (string) ($this->pack->metrics()['exposure_base'] ?? 'HOURS_WORKED');
    }

    /**
     * How long a class of record must be kept, in years (SHEQ-DATA-3, SEC-7).
     *
     * This read was wrong, and wrong in the direction that loses records. It looked for
     * `by_record_class` and `default_years`, and the packs that ship declare neither — they
     * declare `incident_records`, `medical_surveillance` and three more, as ISO-8601 durations.
     * So every lookup missed, fell through to the three-year default, and every incident ever
     * reported was stamped with a `retention_until` three years out while the active pack said
     * forty. `DataSubjectService` then honours that date, so a fatality record became erasable
     * thirty-seven years early and nothing anywhere would have said so.
     *
     * Three changes, and the order of precedence is deliberate: the longest applicable period
     * wins, always.
     *
     *   1. The manifest's real shape is read, ISO-8601 durations included, with the record
     *      classes the code asks about aliased onto the keys packs actually use.
     *   2. `STATUTORY_FLOORS` is applied underneath it. SEC-7 says a retention period cannot be
     *      shortened below the active regulatory requirement, and a pack that simply omits a
     *      class was shortening it to three years. A floor in code cannot be omitted.
     *   3. The old `by_record_class`/`default_years` shape still works, so a pack written to it
     *      is not broken by this.
     */
    public function retentionYears(string $recordClass): int
    {
        $retention = $this->pack->retention();
        $class = strtolower($recordClass);

        $declared = $retention['by_record_class'][$recordClass]
            ?? $retention[self::MANIFEST_KEYS[$class] ?? $class]
            ?? $retention[$class]
            ?? $retention['default_years']
            ?? null;

        // The longest applicable period wins. A pack may exceed the statutory minimum — a group
        // policy of fifty years for exposure records is lawful and somebody chose it — and it may
        // never go under one.
        return max($this->years($declared), $this->floorFor($class));
    }

    /**
     * The record classes the code asks about, mapped onto the keys packs declare.
     *
     * Kept as data rather than resolved at the call sites, because the call sites are asking a
     * question about their own domain — "how long do I keep a section 24 incident" — and should
     * not have to know what a pack author called it.
     */
    private const MANIFEST_KEYS = [
        'incident' => 'incident_records',
        'incident_s24' => 'incident_records',
        'investigation' => 'incident_records',
        'statutory_form' => 'statutory_forms',
        'medical' => 'medical_surveillance',
        'medical_record' => 'medical_surveillance',
        'exposure' => 'exposure_monitoring',
        'exposure_result' => 'exposure_monitoring',
        'competency' => 'training_records',
        'training' => 'training_records',
        'induction' => 'training_records',
    ];

    /**
     * South African statutory minimums, in years (SEC-7).
     *
     * These are floors, not defaults: a pack that omits a class, or declares less, cannot take a
     * record below them. The forty-year periods are the ones that matter most and are the easiest
     * to lose, because the harm they document appears decades after the exposure that caused it —
     * an asbestos or silica claim is brought against the monitoring record, and a record destroyed
     * at three years is a claim that cannot be answered either way.
     *
     *   - Medical surveillance and exposure monitoring: 40 years. Asbestos Regulations 2020
     *     reg 11(4) and the Hazardous Chemical Agents Regulations require records to be kept for
     *     40 years; the MHSA keeps medical records for the same reason.
     *   - Incident records and statutory forms: 40 years where the record documents an injury
     *     whose consequences are lifelong. The OHS Act General Administrative Regulations set
     *     three years as the floor for the register itself, which is why a pack may declare less
     *     for a minor-injury class — but the shipped packs declare forty and that is what is
     *     honoured.
     *   - Training records: 10 years, which is the period a competency dispute is realistically
     *     raised over.
     *
     * Three years remains the floor for anything unrecognised: it is the General Administrative
     * Regulations' own period and the right answer for a record class nobody has classified.
     */
    public const STATUTORY_FLOORS = [
        'medical_surveillance' => 40,
        'exposure_monitoring' => 40,
        'statutory_forms' => 40,
        'incident_records' => 3,
        'training_records' => 10,
    ];

    /** The floor for a record class, after aliasing. Three years for anything unrecognised. */
    private function floorFor(string $class): int
    {
        $key = self::MANIFEST_KEYS[$class] ?? $class;

        return self::STATUTORY_FLOORS[$key] ?? 3;
    }

    /**
     * A declared retention period as a whole number of years.
     *
     * Packs declare ISO-8601 durations (`P40Y`), and a pack written to the older shape declares a
     * plain integer. Months and days are rounded UP to a year rather than truncated: rounding a
     * retention period down is the one direction that destroys a record early, and the arithmetic
     * is not worth being clever about when the cost of being wrong is one-sided.
     */
    private function years(mixed $declared): int
    {
        if ($declared === null) {
            return 0;
        }

        if (is_int($declared) || is_float($declared)) {
            return (int) ceil((float) $declared);
        }

        $value = trim((string) $declared);

        if ($value === '') {
            return 0;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        if (preg_match('/^P(?:(\d+)Y)?(?:(\d+)M)?(?:(\d+)D)?$/i', $value, $m)) {
            $years = (int) ($m[1] ?? 0);
            $months = (int) ($m[2] ?? 0);
            $days = (int) ($m[3] ?? 0);

            return $years + (int) ceil(($months * 30 + $days) / 365);
        }

        /*
         * Unparseable. Returning zero is right, because the caller takes the maximum of this and
         * the statutory floor — so a malformed pack value falls back to the law rather than to
         * whatever the string happened to cast to. `(int) 'P40Y'` is 0 in PHP anyway, which is
         * how a silent three-year retention would otherwise have survived this rewrite.
         */
        return 0;
    }
}
