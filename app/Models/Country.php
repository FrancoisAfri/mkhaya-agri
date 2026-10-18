<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
    use HasFactory;

    // Mass assignable fields
    protected $fillable = [
        'name',
        'a2_code',
        'a3_code',
        'numcode',
        'phonecode',
        'abbreviation',
    ];

    /**
     * Relationship: A country has many provinces
     */
    public function provinces()
    {
        return $this->hasMany(Province::class);
    }

    /**
     * Helper to add a province
     */
    public function addProvince(Province $province)
    {
        return $this->provinces()->save($province);
    }
}
