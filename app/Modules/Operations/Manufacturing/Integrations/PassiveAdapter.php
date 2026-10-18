<?php

namespace App\Modules\Operations\Manufacturing\Integrations;

use App\Modules\Operations\Manufacturing\Models\Integration;
use App\Modules\Operations\Manufacturing\Models\IntegrationEvent;

/**
 * Systems that come to us -- devices and gateways posting signed webhooks -- and file exchange,
 * where imports arrive as uploads and outgoing events are collected into a download. Nothing is
 * pulled and nothing is pushed over the network.
 */
class PassiveAdapter implements IntegrationAdapter
{
    public function ping(Integration $i): array
    {
        return ['ok' => true, 'detail' => $i->method === 'FILE' ? 'File exchange: nothing to reach.' : 'Webhooks: the device calls us; check its first delivery.'];
    }

    public function fetch(Integration $i): array
    {
        return [];
    }

    public function send(Integration $i, IntegrationEvent $e): array
    {
        // Collected into the next export file; the download marks it delivered.
        return ['ok' => false, 'reference' => null, 'detail' => 'Waiting for the next export file.'];
    }
}
