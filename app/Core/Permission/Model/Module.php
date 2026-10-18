<?php

namespace App\Core\Permission\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Module extends Model
{
    use HasFactory;

    protected $table = 'modules';

    protected $fillable = [
        'name',
        'display_name',
        'description',
    ];

    public function userAccesses(): HasMany
    {
        return $this->hasMany(UserModuleAccess::class);
    }
}
