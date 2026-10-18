<?php

namespace App\Modules\Operations\Sheq\Regulatory;

/**
 * What the Regulatory Pack Engine decided about one incident (SHEQ-REG-3).
 *
 * Carries the pack id and version alongside the verdict because SHEQ-REG-2 requires a record to
 * remain evaluated against the rules that were effective at `occurred_at` — the incident stores
 * these, and nothing reclassifies it later when the pack moves on.
 */
final class ClassificationResult
{
    /**
     * @param  list<string>  $obligations  obligation definition ids to instantiate
     * @param  list<string>  $forms  statutory forms this incident requires
     * @param  array<string,mixed>  $attributes  any further `then:` keys the pack set
     */
    public function __construct(
        public readonly string $packId,
        public readonly string $packVersion,
        public readonly ?string $ruleId,
        public readonly ?string $statutoryCategory,
        public readonly bool $reportable,
        public readonly array $obligations = [],
        public readonly array $forms = [],
        public readonly array $attributes = [],
    ) {}

    /** No rule matched: the incident is recorded, carries no statutory category, and is not reportable. */
    public static function unclassified(string $packId, string $packVersion): self
    {
        return new self($packId, $packVersion, null, null, false);
    }

    public function isClassified(): bool
    {
        return $this->ruleId !== null;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'pack_id' => $this->packId,
            'pack_version' => $this->packVersion,
            'rule_id' => $this->ruleId,
            'statutory_category' => $this->statutoryCategory,
            'reportable' => $this->reportable,
            'obligations' => $this->obligations,
            'forms' => $this->forms,
            'attributes' => $this->attributes,
        ];
    }
}
