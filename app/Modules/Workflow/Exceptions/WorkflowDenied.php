<?php

namespace App\Modules\Workflow\Exceptions;

use Illuminate\Database\Eloquent\Model;

/**
 * A refusal that must be audited. Thrown inside a transaction, it is recorded by the caller after
 * the rollback — an audit row written inside the transaction would vanish with it.
 */
class WorkflowDenied extends WorkflowException
{
    public function __construct(string $message, public readonly string $action, public readonly Model|string $subject, public readonly string $reason, public readonly array $extra = [], public readonly string $outcome = 'denied')
    {
        parent::__construct($message);
    }

    public function audit(): void
    {
        app(\App\Modules\Workflow\Services\WorkflowAudit::class)->record($this->action, $this->subject, $this->extra + ['reason' => $this->reason], $this->outcome);
    }
}
