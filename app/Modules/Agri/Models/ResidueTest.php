<?php

namespace App\Modules\Agri\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;

/** A laboratory residue analysis of a sample from a lot or a field/block, with its certificate (base64) and a result per active. */
class ResidueTest extends FarmOwnedModel
{
    public const SAMPLE_TYPES = ['lot', 'field'];

    protected $table = 'agri_residue_tests';

    protected $hidden = ['certificate_data'];

    protected $casts = ['sampled_on' => 'date', 'reported_on' => 'date'];

    public function results(): HasMany
    {
        return $this->hasMany(ResidueResult::class, 'test_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return parent::getActivitylogOptions()->logExcept(['updated_at', 'created_at', 'certificate_data']);
    }
}
