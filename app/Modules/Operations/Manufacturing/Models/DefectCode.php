<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** What was wrong, in the company's own vocabulary, so failed checks can be counted by cause (WMS-082). */
class DefectCode extends Model
{
    use BelongsToCompany;

    protected $table = 'mfg_defect_codes';

    protected $fillable = ['company_id', 'code', 'name', 'severity', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public const SEVERITIES = ['CRITICAL', 'MAJOR', 'MINOR'];
}
