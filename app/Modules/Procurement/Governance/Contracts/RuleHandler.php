<?php

namespace App\Modules\Procurement\Governance\Contracts;

use App\Modules\Procurement\Governance\Support\Rules\RuleOutcome;
use App\Modules\Procurement\Governance\Support\Rules\RuleSubject;

/**
 * One kind of configurable check from §5.3. Adding a kind is a new class plus one line in the
 * engine's map — never a change to any caller.
 *
 * A handler decides only whether the rule *holds*. It never decides how loudly to complain: the
 * rule's configured severity turns a failure into FAIL or WARNING, because the same missing
 * document blocks submission at one organisation and merely annotates it at another.
 *
 * A handler that cannot reach the data it needs — because the module owning it is not installed —
 * returns `RuleOutcome::skip()`, never `pass()`.
 */
interface RuleHandler
{
    /**
     * @param  array<string,mixed>  $parameters  the rule's `parameters` JSON, decoded
     */
    public function evaluate(RuleSubject $subject, array $parameters): RuleOutcome;
}
