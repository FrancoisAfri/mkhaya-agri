<?php
namespace App\Modules\Policies\DTOs;
use App\Modules\Policies\Models\PolicyRule;
readonly class EvaluationResultDTO {
    public function __construct(
        public PolicyRule     $rule,
        public PolicyEventDTO $event,
        public bool           $passed,
        public string         $severity,
        public string         $outcome,      // pass|block|warn|escalate|log_only
        public ?string        $explanation = null,
    ) {}
}
