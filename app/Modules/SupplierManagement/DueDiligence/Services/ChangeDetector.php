<?php

namespace App\Modules\SupplierManagement\DueDiligence\Services;

use App\Modules\SupplierManagement\DueDiligence\Support\CheckType;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierChangeEvent;
use App\Modules\SupplierManagement\Models\SupplierCreditReport;
use App\Modules\SupplierManagement\Models\SupplierVerification;
use Illuminate\Support\Str;

/**
 * Notices that something moved.
 *
 * Comparing the newest observation with the one before it, rather than against the supplier's own
 * record — because the supplier's record is what somebody types, and the question monitoring exists
 * to answer is whether the SOURCE now says something different. A registry that listed two directors
 * in March and three today is the finding, whether or not anybody updated the form in between.
 *
 * Three things this is careful about, each of which would otherwise make the feature unusable:
 *
 *  - Formatting is not change. "2019/123456/07" becoming "201912345607", or a name gaining a
 *    "(Pty) Ltd", is the same fact written differently, and a monitor that reports it teaches people
 *    to ignore it.
 *  - The first observation is not a change. There is nothing to compare it to, and reporting every
 *    initial check as "changed" would bury the real ones on day one.
 *  - A change to a field nobody cares about is not worth waking somebody for. The severity comes
 *    from the category, so a new director is not the same alert as a new postal code.
 */
class ChangeDetector
{
    public function __construct(private readonly FieldComparator $comparator) {}

    /**
     * Which field belongs to which category of change, and therefore how loudly to say it.
     *
     * @return array<string, string> field fragment → category
     */
    private const FIELD_CATEGORIES = [
        'director' => 'directors',
        'company_status' => 'company_status',
        'status' => 'company_status',
        'company_name' => 'company_name',
        'registered_name' => 'company_name',
        'address' => 'address',
        'account_holder' => 'banking',
        'account_number' => 'banking',
        'bank' => 'banking',
        'compliance_status' => 'tax',
        'tax' => 'tax',
        'vat' => 'tax',
        'owner' => 'ownership',
        'shareholder' => 'ownership',
        'bbbee' => 'bbbee',
    ];

    /**
     * Compare a new observation with the previous one of the same kind.
     *
     * @return array<int, SupplierChangeEvent>
     */
    public function forVerification(SupplierVerification $observation): array
    {
        $previous = SupplierVerification::query()
            ->where('supplier_id', $observation->supplier_id)
            ->where('check_type', $observation->check_type)
            ->where('subject_type', $observation->subject_type)
            ->where('id', '!=', $observation->id)
            ->where('checked_at', '<=', $observation->checked_at)
            ->orderByDesc('checked_at')->orderByDesc('id')
            ->first();

        // Nothing to compare against. A first check is a baseline, not a change.
        if (! $previous) {
            return [];
        }

        $events = [];

        // A check that used to pass and now does not is itself the change, whatever the fields say.
        if ($previous->passed() && ! $observation->passed()) {
            $events[] = $this->record($observation, [
                'category' => $this->categoryFor($observation->check_type, null),
                'field' => null,
                'previous' => $previous->status,
                'current' => $observation->status,
                'summary' => CheckType::label($observation->check_type).' no longer passes: '
                    .($observation->summary ?: $observation->status),
                'severity' => 'high',
            ]);
        }

        foreach ($this->fieldChanges($previous->observed ?? [], $observation->observed ?? []) as $change) {
            $events[] = $this->record($observation, $change);
        }

        return array_values(array_filter($events));
    }

    /**
     * Compare two credit reports.
     *
     * Deterioration is the point, so a score that improves is recorded at low severity rather than
     * not at all — a supplier recovering is worth knowing, and a monitor that only ever reports bad
     * news reads as a charge sheet.
     */
    public function forCreditReport(SupplierCreditReport $report): ?SupplierChangeEvent
    {
        $previous = SupplierCreditReport::query()
            ->where('supplier_id', $report->supplier_id)
            ->where('id', '!=', $report->id)
            ->where('report_date', '<=', $report->report_date)
            ->orderByDesc('report_date')->orderByDesc('id')
            ->first();

        if (! $previous) {
            return null;
        }

        $before = $previous->riskScore();
        $after = $report->riskScore();

        $newJudgments = $report->judgments_count - $previous->judgments_count;
        $newInsolvency = $report->insolvency && ! $previous->insolvency;

        if ($newInsolvency) {
            return $this->write($report->supplier_id, [
                'category' => 'credit',
                'field' => 'insolvency',
                'previous' => 'no',
                'current' => 'yes',
                'summary' => 'Insolvency is now recorded against this supplier.',
                'severity' => 'critical',
            ]);
        }

        if ($newJudgments > 0) {
            return $this->write($report->supplier_id, [
                'category' => 'credit',
                'field' => 'judgments',
                'previous' => (string) $previous->judgments_count,
                'current' => (string) $report->judgments_count,
                'summary' => "{$newJudgments} new judgment(s) on the credit record.",
                'severity' => 'high',
            ]);
        }

        if ($before === null || $after === null || abs($after - $before) < 5) {
            return null;   // Noise. Bureau scores wobble.
        }

        $worse = $after > $before;

        return $this->write($report->supplier_id, [
            'category' => 'credit',
            'field' => 'score',
            'previous' => (string) round($before),
            'current' => (string) round($after),
            'summary' => $worse
                ? sprintf('Credit risk has worsened: %d → %d.', round($before), round($after))
                : sprintf('Credit risk has improved: %d → %d.', round($before), round($after)),
            'severity' => $worse ? ($after - $before >= 20 ? 'high' : 'medium') : 'low',
        ]);
    }

    /** A change made to the supplier's own record — banking edited, a director added. */
    public function forEdit(Supplier $supplier, string $category, string $field, ?string $previous, ?string $current, ?string $summary = null): ?SupplierChangeEvent
    {
        if ($this->sameValue($field, $previous, $current)) {
            return null;
        }

        return $this->write($supplier->id, [
            'category' => $category,
            'field' => $field,
            'previous' => $previous,
            'current' => $current,
            'summary' => $summary ?: (Str::headline($field).' changed from "'.($previous ?: '—').'" to "'.($current ?: '—').'".'),
            'severity' => SupplierChangeEvent::CATEGORIES[$category]['severity'] ?? 'medium',
        ], 'edit');
    }

    /**
     * Field-by-field difference between two observations.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fieldChanges(array $before, array $after): array
    {
        $changes = [];

        foreach ($after as $field => $value) {
            if (! array_key_exists($field, $before)) {
                // A field the source did not report last time is new information, not a change to
                // something — reported quietly so a provider widening its payload does not look like
                // fifty suppliers all changing at once.
                continue;
            }

            if ($this->sameValue($field, $this->flatten($before[$field]), $this->flatten($value))) {
                continue;
            }

            $category = $this->categoryFor(null, $field);

            $changes[] = [
                'category' => $category,
                'field' => $field,
                'previous' => $this->flatten($before[$field]),
                'current' => $this->flatten($value),
                'summary' => sprintf(
                    '%s changed from "%s" to "%s".',
                    Str::headline($field),
                    Str::limit((string) $this->flatten($before[$field]), 120),
                    Str::limit((string) $this->flatten($value), 120),
                ),
                'severity' => SupplierChangeEvent::CATEGORIES[$category]['severity'] ?? 'medium',
            ];
        }

        return $changes;
    }

    /**
     * Are these two values the same fact written differently?
     *
     * The whole usefulness of the monitor rests on this being generous in the right places. A
     * registration number reformatted, a company name gaining its entity form, whitespace — none of
     * those are change, and a monitor that says they are gets switched off.
     */
    private function sameValue(string $field, mixed $before, mixed $after): bool
    {
        $a = trim((string) $before);
        $b = trim((string) $after);

        if ($a === $b) {
            return true;
        }

        if ($a === '' || $b === '') {
            return false;
        }

        if (preg_match('/(registration|number|vat|tax|account)/i', $field)) {
            return preg_replace('/\D+/', '', $a) === preg_replace('/\D+/', '', $b);
        }

        if (preg_match('/(name|holder)/i', $field)) {
            return $this->comparator->normaliseEntityName($a) === $this->comparator->normaliseEntityName($b)
                || $this->comparator->normalisePersonName($a) === $this->comparator->normalisePersonName($b);
        }

        return Str::lower($a) === Str::lower($b);
    }

    /** Lists — directors, owners — compared as a sorted, normalised string. */
    private function flatten(mixed $value): string
    {
        if (is_array($value)) {
            $items = array_map(fn ($item) => is_array($item) ? json_encode($item) : (string) $item, $value);
            sort($items);

            return implode(', ', $items);
        }

        return (string) $value;
    }

    private function categoryFor(?string $checkType, ?string $field): string
    {
        if ($field) {
            foreach (self::FIELD_CATEGORIES as $fragment => $category) {
                if (Str::contains(Str::lower($field), $fragment)) {
                    return $category;
                }
            }
        }

        return match ($checkType) {
            CheckType::COMPANY_DIRECTORS => 'directors',
            CheckType::OWNERSHIP_BENEFICIAL => 'ownership',
            CheckType::TAX_COMPLIANCE, CheckType::TAX_VAT_VENDOR => 'tax',
            CheckType::BANK_ACCOUNT => 'banking',
            CheckType::SCREENING_SANCTIONS, CheckType::SCREENING_PEP => 'screening',
            CheckType::BBBEE_CERTIFICATE => 'bbbee',
            CheckType::COMPANY_ADDRESS => 'address',
            default => 'company_status',
        };
    }

    private function record(SupplierVerification $observation, array $change): SupplierChangeEvent
    {
        return $this->write($observation->supplier_id, $change, 'monitoring', $observation);
    }

    private function write(int $supplierId, array $change, string $by = 'monitoring', ?SupplierVerification $observation = null): SupplierChangeEvent
    {
        return SupplierChangeEvent::create([
            'supplier_id' => $supplierId,
            'category' => $change['category'],
            'check_type' => $observation?->check_type,
            'field' => $change['field'] ?? null,
            'previous_value' => $change['previous'] ?? null,
            'current_value' => $change['current'] ?? null,
            'severity' => $change['severity'] ?? 'medium',
            'summary' => Str::limit($change['summary'], 900, ''),
            'detected_by' => $by,
            'verification_id' => $observation?->id,
            'status' => SupplierChangeEvent::STATUS_OPEN,
        ]);
    }
}
