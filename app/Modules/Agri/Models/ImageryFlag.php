<?php

namespace App\Modules\Agri\Models;

/** An area the imagery says to inspect; it stays an indicator until someone checks it in the field (§16B). */
class ImageryFlag extends FarmOwnedModel
{
    protected $table = 'agri_imagery_flags';

    protected $casts = ['cells' => 'array', 'validated_at' => \App\Modules\Agri\Casts\FarmDateTime::class];
}
