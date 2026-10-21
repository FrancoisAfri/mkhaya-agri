<?php

namespace App\Modules\Agri\Models;

/** A change to a contract, proposed and approved; the contract's history. */
class ContractAmendment extends AgriModel
{
    protected $table = 'agri_contract_amendments';

    protected $casts = ['changes' => 'array', 'decided_at' => \App\Modules\Agri\Casts\FarmDateTime::class];
}
