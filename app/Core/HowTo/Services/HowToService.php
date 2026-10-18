<?php

namespace App\Core\HowTo\Services;

use App\Core\HowTo\Models\HowTo;
use Illuminate\Database\Eloquent\Collection;

class HowToService
{
    public function getByModule(int $moduleId): Collection
    {
        return HowTo::where('module_id', $moduleId)
            ->orderBy('id', 'asc')
            ->get();
    }

    public function getByRibbon(int $ribbonId): Collection
    {
        return HowTo::where('ribbon_id', $ribbonId)
            ->orderBy('id', 'asc')
            ->get();
    }

    public function create(array $data): HowTo
    {
        return HowTo::create($data);
    }

    public function update(HowTo $howto, array $data): HowTo
    {
        $howto->update($data);
        return $howto;
    }

    public function delete(HowTo $howto): bool
    {
        return (bool) $howto->delete();
    }

    public function toggle(HowTo $howto): HowTo
    {
        $howto->active = !$howto->active;
        $howto->save();
        return $howto;
    }
}
