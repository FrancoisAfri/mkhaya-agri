<?php

namespace App\Core\HowTo\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Core\Module\Models\Modules;
use App\Core\Ribbon\Models\Ribbon;

class HowTo extends Model
{
    use HasFactory;

    protected $table = 'module_how_tos';

    protected $fillable = [
        'module_id',
        'ribbon_id',
        'question',
        'answer',
        'active',
        'source',
    ];

    public function module()
    {
        return $this->belongsTo(Modules::class, 'module_id');
    }

    public function ribbon()
    {
        return $this->belongsTo(Ribbon::class, 'ribbon_id');
    }
}
