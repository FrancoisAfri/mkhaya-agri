<?php

namespace App\Modules\Policies\Services;

use App\Modules\Policies\Models\PolicyCategory;

class PolicyCategoryService
{
    public function getAll()
    {
        return PolicyCategory::latest()->get();
    }

    public function findById(int $id): ?PolicyCategory
    {
        return PolicyCategory::find($id);
    }

    public function create(array $data): PolicyCategory
    {
        return PolicyCategory::create($data);
    }

    public function update(int $id, array $data): bool
    {
        $category = $this->findById($id);
        if (!$category) return false;
        return $category->update($data);
    }

    public function delete(int $id): bool
    {
        $category = $this->findById($id);
        if (!$category) return false;
        // Optionally prevent deletion if policies exist
        if ($category->policies()->count() > 0) {
            throw new \Exception('Cannot delete category with associated policies.');
        }
        return $category->delete();
    }
}
