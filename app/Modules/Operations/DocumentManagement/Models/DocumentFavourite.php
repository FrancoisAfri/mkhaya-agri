<?php

namespace App\Modules\Operations\DocumentManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * One person marking one document to find again.
 *
 * Private to the person who starred it. One person favouriting the leave policy does not put it on
 * anybody else's list, which is the difference between a favourite and a pin.
 */
class DocumentFavourite extends Model
{
    use BelongsToCompany;

    protected $table = 'document_favourites';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = ['created_at' => 'datetime'];
}
