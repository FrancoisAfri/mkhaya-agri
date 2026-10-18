<?php

namespace App\Modules\Procurement\Governance\Exceptions;

use Illuminate\Http\Request;
use RuntimeException;

/**
 * A business rule the user broke — "that person is already on this committee", "the criteria were
 * frozen when evaluation opened", "you raised this request, so you cannot also approve it".
 *
 * These were plain RuntimeExceptions, which meant every caller had to remember a try/catch and the
 * one that forgot produced a stack trace on screen for what is really a form validation message.
 * That is the wrong failure: a 500 says the system is broken, when in fact it worked correctly and
 * the answer is simply no.
 *
 * Laravel calls `render()` on an exception that defines one, so handling is automatic and no
 * controller can forget it. It still extends RuntimeException, so existing catch blocks and tests
 * that assert on RuntimeException keep working unchanged.
 *
 * The field name matters: attaching the message to the input that caused it puts the error next to
 * the control the user has to fix, rather than in a banner at the top of a long form.
 */
class DomainRuleException extends RuntimeException
{
    public function __construct(
        string $message,
        /** The form field this belongs against, when there is an obvious one. */
        public readonly string $field = 'error',
    ) {
        parent::__construct($message);
    }

    /** Name the field explicitly: `DomainRuleException::forField('secretary_id', '…')`. */
    public static function forField(string $field, string $message): self
    {
        return new self($message, $field);
    }

    public function render(Request $request)
    {
        // 422 rather than 500: the request was understood and refused, which is what a client
        // needs to distinguish so it can show the message instead of a generic failure.
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'message' => $this->getMessage(),
                'errors' => [$this->field => [$this->getMessage()]],
            ], 422);
        }

        return back()
            ->withInput()
            ->withErrors([$this->field => $this->getMessage()]);
    }
}
