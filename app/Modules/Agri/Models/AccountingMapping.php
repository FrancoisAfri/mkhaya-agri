<?php

namespace App\Modules\Agri\Models;

/** One of our accounts, tax codes, farm dimensions, contacts or items, and what it is in the connected accounting system. */
class AccountingMapping extends AgriModel
{
    public const KINDS = ['account', 'tax', 'tracking', 'contact', 'item'];

    protected $table = 'agri_accounting_mappings';

    protected $casts = ['extra' => 'array'];
}
