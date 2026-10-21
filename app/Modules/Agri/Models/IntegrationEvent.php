<?php

namespace App\Modules\Agri\Models;

/** One message in or out of an integration, with its idempotency key, status, attempts and error (§14). */
class IntegrationEvent extends AgriModel
{
    public const MAX_ATTEMPTS = 5;

    protected $table = 'agri_integration_events';

    protected $casts = ['payload' => 'array', 'result' => 'array', 'processed_at' => \App\Modules\Agri\Casts\FarmDateTime::class, 'next_attempt_at' => \App\Modules\Agri\Casts\FarmDateTime::class];
}
