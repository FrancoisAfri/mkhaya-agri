<?php

namespace App\Modules\Agri\Models;

/** A document for the registry or the verifier (PDD, monitoring report, evidence), kept in the database. */
class CarbonDocument extends FarmOwnedModel
{
    protected $table = 'agri_carbon_documents';

    protected $hidden = ['data'];

    protected $casts = ['size' => 'integer'];
}
