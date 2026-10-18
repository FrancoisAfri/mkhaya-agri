<?php

namespace App\Modules\Fleet\Integrations\Adapters\Fines;

use App\Modules\Fleet\Integrations\Adapters\AbstractFleetAdapter;

/**
 * Manual / CSV fine import adapter.
 *
 * This adapter is a no-op for automated sync — fines are entered manually
 * via the Fleet Fines UI or CSV upload endpoint.  testConnection() always
 * returns success; sync() returns a helpful message directing users to the UI.
 */
class ManualFineAdapter extends AbstractFleetAdapter
{
    public function testConnection(): array
    {
        return ['success' => true, 'message' => 'Manual import does not require a connection.'];
    }

    public function sync(): array
    {
        $this->recordSyncResult('success', 'Manual import — no automated sync. Use the Fleet Fines page to add fines.');
        return [
            'provider' => 'manual',
            'synced'   => 0,
            'errors'   => 0,
            'message'  => 'Manual import active. Add fines via Fleet → Fines or CSV upload.',
        ];
    }
}
