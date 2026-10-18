<?php

namespace App\Modules\HR\Recruitment\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ApprovalRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Model $entity,
        public readonly string $entityType,
    ) {}
}
