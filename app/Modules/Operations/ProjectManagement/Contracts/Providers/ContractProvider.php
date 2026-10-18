<?php

namespace App\Modules\Operations\ProjectManagement\Contracts\Providers;

use App\Modules\Operations\ProjectManagement\Support\Providers\ProvidedRecord;

/** Contracts a project delivers against. Integrated: Contract Management. */
interface ContractProvider
{
    /** @return list<ProvidedRecord> */
    public function search(string $query, int $limit = 20): array;

    public function get(string $id): ?ProvidedRecord;
}
