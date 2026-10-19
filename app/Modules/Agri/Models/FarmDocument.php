<?php

namespace App\Modules\Agri\Models;

use App\Core\User\Trait\FileStorage;

class FarmDocument extends FarmOwnedModel
{
    use FileStorage;

    public const TYPES = ['title_deed', 'lease', 'water_use_licence', 'pesticide_registration', 'operator_certificate',
        'organic_certificate', 'globalgap', 'brand_registration', 'movement_permit', 'vet_certificate',
        'insurance', 'fire_permit', 'other'];

    public const SUBJECTS = ['field', 'crop_plan', 'livestock_group', 'animal', 'machine'];

    protected $table = 'farm_documents';

    protected $hidden = ['data'];

    protected $casts = ['issued_on' => 'date', 'expires_on' => 'date'];

    public function getActivitylogOptions(): \Spatie\Activitylog\LogOptions
    {
        // The file itself is not copied into the audit log.
        return parent::getActivitylogOptions()->logExcept(['data', 'updated_at', 'created_at']);
    }

    /** expired | expiring | valid | none */
    public function expiryState(?int $horizon = null): string
    {
        if (! $this->expires_on) {
            return 'none';
        }
        if ($this->expires_on->lt(today())) {
            return 'expired';
        }

        return $this->expires_on->lte(today()->addDays($horizon ?? (int) $this->remind_days)) ? 'expiring' : 'valid';
    }
}
