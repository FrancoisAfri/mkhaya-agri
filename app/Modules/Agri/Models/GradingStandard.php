<?php

namespace App\Modules\Agri\Models;

/** A configurable grading standard: the grades a product is sorted into and the quality checks that go with them (§8.2). */
class GradingStandard extends AgriModel
{
    protected $table = 'grading_standards';

    protected $casts = ['grades' => 'array', 'checks' => 'array', 'is_active' => 'boolean'];

    /** @return list<string> */
    public function gradeCodes(): array
    {
        return array_values(array_map(fn ($g) => (string) $g['code'], $this->grades ?? []));
    }
}
