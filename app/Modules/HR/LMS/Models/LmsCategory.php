<?php

namespace App\Modules\HR\LMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Shared\Traits\KeepsDatabaseDefaults;

class LmsCategory extends Model
{
    use SoftDeletes, KeepsDatabaseDefaults;

    protected $table = 'lms_categories';

    protected $fillable = [
        'name',
        'description',
        'icon',
        'color',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function courses(): HasMany
    {
        return $this->hasMany(LmsCourse::class, 'category_id');
    }
}
