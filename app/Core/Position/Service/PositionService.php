<?php

namespace App\Core\Position\Service;

use App\Core\Position\Models\HrPosition;
use App\Core\Position\Models\JobCategories;

class PositionService
{
    // ── Categories ──
    public function getJobCategories()
    {
        return JobCategories::with(['positions' => fn($q) => $q->orderBy('name')])
            ->orderBy('name')
            ->get();
    }

    public function createCategory(array $data)
    {
        return JobCategories::create([
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'status'      => $data['status']      ?? 1,
        ]);
    }

    public function updateCategory(int $id, array $data)
    {
        $cat = JobCategories::findOrFail($id);
        $cat->update([
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'status'      => $data['status']      ?? $cat->status,
        ]);
        return $cat;
    }

    public function deleteCategory(int $id): void
    {
        JobCategories::findOrFail($id)->delete();
    }

    // ── Positions ──
    public function createPosition(array $data)
    {
        return HrPosition::create([
            'name'            => $data['name'],
            'description'     => $data['description']     ?? null,
            'status'          => $data['status']          ?? 1,
            'job_category_id' => $data['job_category_id'],
        ]);
    }

    public function updatePosition(int $id, array $data)
    {
        $pos = HrPosition::findOrFail($id);
        $pos->update([
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'status'      => $data['status']      ?? $pos->status,
        ]);
        return $pos;
    }

    public function deletePosition(int $id)
    {
        HrPosition::findOrFail($id)->delete();
    }
}
