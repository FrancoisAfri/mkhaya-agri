<?php

namespace App\Modules\Agri\Models;

/**
 * A record we pushed to (or a payment we pulled from) a connected accounting system, with the id it
 * has there. Its existence is what makes a retry safe: a record with an external id is never sent
 * as new again. The message that carries it is in the integration log, where it can be retried.
 */
class AccountingLink extends AgriModel
{
    protected $table = 'agri_accounting_links';

    protected $casts = ['synced_at' => 'datetime'];

    public function connection()
    {
        return $this->belongsTo(AccountingConnection::class, 'connection_id');
    }
}
