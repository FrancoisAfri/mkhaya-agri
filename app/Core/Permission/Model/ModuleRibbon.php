<?php

namespace App\Core\Permission\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModuleRibbon extends Model
{

    protected $table = 'module_ribbons';
    protected $fillable = [
        'active', 'sort_order', 'ribbon_name', 'description',
        'ribbon_path', 'font_awesome', 'access_level', 'module_id'
    ];

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'module_id');
    }

}
