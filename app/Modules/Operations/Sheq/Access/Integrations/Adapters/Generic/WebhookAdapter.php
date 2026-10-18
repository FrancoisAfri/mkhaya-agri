<?php

namespace App\Modules\Operations\Sheq\Access\Integrations\Adapters\Generic;

use App\Modules\Operations\Sheq\Access\AccessDecisionService;
use App\Modules\Operations\Sheq\Access\Integrations\Adapters\AbstractAccessAdapter;

/**
 * Inbound webhook: the vendor posts to us.
 *
 * For on-premise controllers behind a firewall with no route in. There is nothing to poll, so
 * `sync()` finds no events — the decisions happen when the vendor calls the webhook endpoint, and
 * the same {@see AccessDecisionService} runs either way.
 */
class WebhookAdapter extends AbstractAccessAdapter
{
    public function testConnection(): array
    {
        return ['ok' => true,
            'message' => 'Inbound webhook. Configure the device to post to the URL shown, '
                .'using the key as a bearer token.'];
    }

    protected function fetchEvents(): array
    {
        // Nothing to pull. Events arrive by push; this is not a failure.
        return [];
    }
}
