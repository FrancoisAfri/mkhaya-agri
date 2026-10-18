<?php

namespace App\Modules\Operations\Sheq\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A short code somebody types to identify a template, a competency or a document type.
 *
 * The format was enforced seven times across this module as a bare `regex:/^[A-Z0-9-]+$/`, which
 * Laravel reports as "the template code field format is invalid". That sentence says a rule exists
 * and nothing about what it is: somebody typing `coerer` is told they are wrong and not that the
 * code wants capitals. The only way to discover the format was to guess, or to read the controller.
 *
 * So the rule carries its own explanation, and gives an example, because a format described in
 * words is still slower to act on than one shown.
 *
 * Capitals rather than free text because these codes are matched, sorted and printed on statutory
 * paperwork beside the shipped library's own — CON-SCAFFOLD, FLT-VEHICLE — and a register holding
 * both `con-scaffold` and `CON-SCAFFOLD` reads as two different checklists.
 */
class ReferenceCode implements ValidationRule
{
    public function __construct(private readonly string $example = 'CON-SCAFFOLD') {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $code = (string) $value;

        if ($code === '') {
            return;   // `required` has its own message; this rule is only about shape.
        }

        if (preg_match('/^[A-Z0-9-]+$/', $code) === 1) {
            return;
        }

        /*
         * Name what is actually wrong where it can be named.
         *
         * Lowercase is overwhelmingly the common case and has an obvious fix, so it gets its own
         * sentence rather than being folded into the general one.
         */
        if (preg_match('/^[A-Za-z0-9-]+$/', $code) === 1) {
            $fail(__('Use capital letters: :suggested rather than :typed.', [
                'suggested' => strtoupper($code),
                'typed' => $code,
            ]));

            return;
        }

        $fail(__('Use capital letters, numbers and hyphens only — for example :example.', [
            'example' => $this->example,
        ]));
    }
}
