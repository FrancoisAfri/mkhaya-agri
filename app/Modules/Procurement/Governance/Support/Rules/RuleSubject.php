<?php

namespace App\Modules\Procurement\Governance\Support\Rules;

/**
 * A normalised view of whatever is being checked — a deviation, a procurement plan, a tender
 * review, a 360° record.
 *
 * The rules engine never sees an Eloquent model. That is the whole point of §5.3: the same
 * configured rule ("a required tax clearance certificate above R500 000") has to apply to a
 * deviation and to a tender review without the rule knowing which it is looking at. So each caller
 * flattens its own subject into this shape, and every handler works against these fields alone.
 *
 * `fields` carries the subject's own attributes for REQUIRED_FIELD and MANDATORY_JUSTIFICATION to
 * inspect by name; the named properties are the ones rule *conditions* select on, and hoisting them
 * out means condition matching is one comparison rather than a lookup that might miss.
 */
final class RuleSubject
{
    /**
     * @param  array<string,mixed>  $fields  the subject's attributes, by name
     * @param  array<string,mixed>  $meta  anything a specific handler needs, e.g. bidder counts
     */
    public function __construct(
        public readonly int $companyId,
        public readonly string $type,
        public readonly int $id,
        public readonly ?string $reference = null,
        public readonly array $fields = [],
        public readonly ?float $amount = null,
        public readonly ?string $category = null,
        public readonly ?int $companyUnitId = null,
        public readonly ?string $method = null,
        /** Deviation type code, tender methodology — the subject's own sub-classification. */
        public readonly ?string $subtype = null,
        public readonly ?string $riskLevel = null,
        public readonly ?string $status = null,
        public readonly ?int $recordId = null,
        public readonly ?int $supplierId = null,
        public readonly ?int $budgetId = null,
        public readonly array $meta = [],
    ) {}

    public function field(string $name): mixed
    {
        return $this->fields[$name] ?? null;
    }

    /** True when the field is absent, null, an empty string, or an empty array. */
    public function fieldIsBlank(string $name): bool
    {
        $value = $this->field($name);

        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }

    /** The context snapshot stored on the check, so an outcome can be explained months later. */
    public function toContext(): array
    {
        return [
            'type' => $this->type,
            'id' => $this->id,
            'reference' => $this->reference,
            'amount' => $this->amount,
            'category' => $this->category,
            'company_unit_id' => $this->companyUnitId,
            'method' => $this->method,
            'subtype' => $this->subtype,
            'risk_level' => $this->riskLevel,
            'status' => $this->status,
            'record_id' => $this->recordId,
            'supplier_id' => $this->supplierId,
            'budget_id' => $this->budgetId,
            'fields' => $this->fields,
        ];
    }
}
