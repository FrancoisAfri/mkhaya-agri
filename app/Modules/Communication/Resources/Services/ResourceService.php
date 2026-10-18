<?php

namespace App\Modules\Communication\Resources\Services;

use App\Modules\Communication\Resources\Models\Resource;
use App\Modules\Communication\ContentManagement\Services\AssetFileService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;


class ResourceService
{
    public function __construct(
        protected AssetFileService $assetService
    ) {}
    private const RESOURCE_TYPES = [
        'form'         => 1,
        'legal'        => 2,
        'handbook'     => 3,
        'healthSafety' => 4,
        'template'     => 5,
        'training'     => 6,
        'sop'          => 7,
        'download'     => 8,
    ];

    private const DEFAULT_RESOURCE_WITH = ['asset', 'author', 'companyUnit'];
    /*
    |--------------------------------------------------------------------------
    | CREATE
    |--------------------------------------------------------------------------
    */

    public function create(array $data, $file = null): Resource
    {
        $asset = null;

        if ($file) {
            $asset = $this->assetService->saveDocument($file);
        }

        return Resource::create([
            ...$data,
            'assets_file_id' => $asset?->id,
            'user_id' => Auth::id(),
        ]);
    }



    public function update(int $id, array $data, $file = null): Resource
    {
        $resource = Resource::findOrFail($id);
        $asset = $resource->asset;

        if ($file) {

            $asset = $this->assetService->saveDocument($file, $asset);

            $data['assets_file_id'] = $asset->id;
        }

        $resource->update($data);

        return $resource;
    }

    /**
     * Base query for any resource type.
     */
    private function baseResourceQuery(string $type): Builder
    {
        if (!isset(self::RESOURCE_TYPES[$type])) {
            throw new \InvalidArgumentException("Invalid resource type: {$type}");
        }

        return Resource::where('type', self::RESOURCE_TYPES[$type])
            ->with(self::DEFAULT_RESOURCE_WITH);
    }

    /**
     * Generic getter — used internally and for paginated calls.
     */
    public function getResourcesByType(string $type)
    {
        return $this->baseResourceQuery($type)->latest()->get();
    }

    /**
     * Paginated resources with optional filters.
     */
    public function getResourcesPaginated(string $type, int $perPage = 15, array $filters = [])
    {
        $query = $this->baseResourceQuery($type);
        $this->applyResourceFilters($query, $filters);
        return $query->latest()->paginate($perPage);
    }

    private function applyResourceFilters(Builder $query, array $filters): void
    {
        if (!empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('title', 'like', "%{$filters['search']}%")
                    ->orWhere('description', 'like', "%{$filters['search']}%");
            });
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        if (isset($filters['internal_only'])) {
            $query->where('internal_only', $filters['internal_only']);
        }

        if (!empty($filters['company_unit_id'])) {
            $query->where('company_unit_id', $filters['company_unit_id']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('published_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('published_at', '<=', $filters['date_to']);
        }
    }


    /** type = 1 */
    public function getForms()
    {
        return $this->getResourcesByType('form');
    }

    /** type = 2 */
    public function getLegalDocuments()
    {
        return $this->getResourcesByType('legal');
    }

    /** type = 3 */
    public function getHandbooks()
    {
        return $this->getResourcesByType('handbook');
    }

    /** type = 4 */
    public function getHealthSafety()
    {
        return $this->getResourcesByType('healthSafety');
    }

    /** type = 5 */
    public function getTemplates()
    {
        return $this->getResourcesByType('template');
    }

    /** type = 6 */
    public function getTrainingMaterials()
    {
        return $this->getResourcesByType('training');
    }

    /** type = 7 */
    public function getSOPs()
    {
        return $this->getResourcesByType('sop');
    }

    /** type = 8 */
    public function getDownloads()
    {
        return $this->getResourcesByType('download');
    }
}
