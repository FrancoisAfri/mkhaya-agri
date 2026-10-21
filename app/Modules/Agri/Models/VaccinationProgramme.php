<?php

namespace App\Modules\Agri\Models;

/** A species' vaccination schedule: first dose at an age, then every so many days. */
class VaccinationProgramme extends AgriModel
{
    protected $table = 'vaccination_programmes';

    protected $casts = ['is_active' => 'boolean'];
}
