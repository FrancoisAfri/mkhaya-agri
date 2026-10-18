<?php

namespace App\Modules\Fleet\Integrations\Contracts;

interface FleetIntegrationContract
{
    /**
     * Test API connectivity and credential validity.
     * Returns ['success' => bool, 'message' => string].
     */
    public function testConnection(): array;

    /**
     * Fetch and store data from the provider into local fleet tables.
     * Returns ['synced' => int, 'errors' => int, 'message' => string].
     */
    public function sync(): array;
}
