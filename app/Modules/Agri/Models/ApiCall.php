<?php

namespace App\Modules\Agri\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** One call to the public farm API: who (client and service user), what (method, path, route), the answer and how long it took. */
class ApiCall extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $table = 'agri_api_calls';

    protected $guarded = ['id'];

    protected $casts = ['replayed' => 'boolean', 'created_at' => 'datetime'];
}
