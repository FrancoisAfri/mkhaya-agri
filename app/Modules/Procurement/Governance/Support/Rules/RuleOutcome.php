<?php

namespace App\Modules\Procurement\Governance\Support\Rules;

/**
 * One rule's verdict on one subject.
 *
 * A handler returns `pass()`, `fail()` or `skip()`; whether a failure is reported as FAIL or
 * WARNING is the *rule's* configured severity, not the handler's decision — the same missing
 * document is a blocker for one organisation and an advisory for another, which is exactly the
 * configurability §5.3 asks for. `applySeverity()` is where that is resolved.
 */
final class RuleOutcome
{
    public const PASS = 'PASS';

    public const WARNING = 'WARNING';

    public const FAIL = 'FAIL';

    public const SKIPPED = 'SKIPPED';

    private function __construct(
        public readonly string $outcome,
        public readonly ?string $message = null,
        public readonly array $detail = [],
    ) {}

    public static function pass(?string $message = null, array $detail = []): self
    {
        return new self(self::PASS, $message, $detail);
    }

    /** The rule did not hold. Severity is applied afterwards. */
    public static function fail(?string $message = null, array $detail = []): self
    {
        return new self(self::FAIL, $message, $detail);
    }

    /**
     * The rule could not be evaluated — usually because the module that owns the answer is not
     * installed. Reported as SKIPPED rather than PASS, because "we could not check" and "we checked
     * and it was fine" are different things and only one of them should reassure anybody.
     */
    public static function skip(string $reason, array $detail = []): self
    {
        return new self(self::SKIPPED, $reason, $detail);
    }

    public function applySeverity(string $severity): self
    {
        if ($this->outcome !== self::FAIL) {
            return $this;
        }

        return new self(
            in_array($severity, [self::WARNING, 'INFO'], true) ? self::WARNING : self::FAIL,
            $this->message,
            $this->detail,
        );
    }

    public function withMessage(?string $message): self
    {
        return $message ? new self($this->outcome, $message, $this->detail) : $this;
    }
}
