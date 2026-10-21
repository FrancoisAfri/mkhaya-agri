<?php

namespace App\Modules\Agri\Models;

/** A regulated agricultural chemical: registration, label, SDS, how it is stored, and who may use it. */
class ChemicalProduct extends AgriModel
{
    protected $table = 'chemical_products';

    public const STORAGE_CLASSES = ['flammable', 'oxidiser', 'corrosive', 'toxic', 'fertiliser_oxidising', 'general'];

    protected $hidden = ['label_data', 'sds_data'];

    protected $casts = ['incompatible_with' => 'array', 'authorised_user_ids' => 'array', 'restricted' => 'boolean',
        'registration_expires_on' => 'date', 'sds_revised_on' => 'date'];
}
