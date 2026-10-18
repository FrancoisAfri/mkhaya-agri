<?php

namespace App\Modules\Communication\ContentManagement\Services;

use App\Core\CompanyUnits\Models\CompanyUnit;
use App\Core\User\Models\User;
use App\Modules\Communication\ContentManagement\Models\AssetsFile;
use App\Modules\Communication\ContentManagement\Models\Content;
use App\Modules\Communication\ContentManagement\Notifications\ContentCreatedNotification;
use App\Modules\Communication\ContentManagement\Services\AssetFileService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;


class ContentsService
{
    public function __construct(
        protected AssetFileService $assetFileService
    ) {}

      private const CONTENT_TYPES = [
        'news'           => 2,
        'announcement'   => 1,
        'event'          => 3,
        'internalNotice' => 4,
    ];

    private const DEFAULT_WITH = ['author.person', 'asset', 'companyUnit'];

   /*
    |=========================================================================
    | 📰 CONTENT MANAGEMENT
    |=========================================================================
    */

    private function baseContentQuery(string $type): Builder
    {
        if (!isset(self::CONTENT_TYPES[$type])) {
            throw new \InvalidArgumentException("Invalid content type: {$type}");
        }

        return Content::where('type', self::CONTENT_TYPES[$type])
                      ->with(self::DEFAULT_WITH);
    }

    public function getContentsByType(string $type)
    {
        return $this->baseContentQuery($type)->get();
    }

    public function getFirstContentByType(string $type)
    {
        return $this->baseContentQuery($type)->first();
    }

    public function getAllContents(): array
    {
        $contents = [];
        foreach (self::CONTENT_TYPES as $type => $typeId) {
            $contents[$type] = $this->getContentsByType($type);
        }
        return $contents;
    }

    public function getContentsPaginated(string $type, int $perPage = 15, array $filters = [])
    {
        $query = $this->baseContentQuery($type);
        $this->applyContentFilters($query, $filters);
        return $query->latest()->paginate($perPage);
    }

    private function applyContentFilters(Builder $query, array $filters): void
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

        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if (!empty($filters['company_unit_id'])) {
            $query->where('company_unit_id', $filters['company_unit_id']);
        }
    }

    // ── Content shortcuts ──────────────────────────────────────────────────────



    public function news()
    {
        return $this->getContentsByType('news');
    }

    public function announcement()
    {
        return $this->getContentsByType('announcement');
    }

    public function event()
    {
        return $this->getContentsByType('event');
    }
   public function getEventbyDate()
{
    $events=$this->baseContentQuery('event')
        ->whereDate('expired_at', '>=', now()->toDateString())
        ->latest()
        ->get();
    return $events;
}
   public function getNewsbyDate()
{
    $events=$this->baseContentQuery('news')
        ->whereDate('expired_at', '>=', now()->toDateString())
        ->latest()
        ->get();
    return $events;
}
   public function getAnnouncementbyDate()
{
    $events=$this->baseContentQuery('announcement')
        ->whereDate('expired_at', '>=', now()->toDateString())
        ->latest()
        ->get();
    return $events;
}
   public function getInternalNoticebyDate()
{
    $events=$this->baseContentQuery('internalNotice')
        ->whereDate('expired_at', '>=', now()->toDateString())
        ->latest()
        ->get();
    return $events;
}


    public function internalNotice()
    {
        return $this->getContentsByType('internalNotice');
    }

    public function store(array $data)
    {
        $asset = null;

        if (!empty($data['video'])) {
            $asset = $this->assetFileService->saveVideo($data['video']);
        } elseif (!empty($data['image'])) {
            $asset = $this->assetFileService->saveImage($data['image']);
        } elseif (!empty($data['document'])) {
            $asset = $this->assetFileService->saveDocument($data['document']);
        }

        $content = Content::create([
            'title'           => $data['title'],
            'description'     => $data['description'] ?? null,
            'summary'         => $data['summary'] ?? null,
            'icon'            => $data['icon'] ?? null,
            'event_start'     => $data['event_start'] ?? null,
            'event_end'       => $data['event_end'] ?? null,
            'event_date'      => $data['event_date'] ?? null,
            'type'            => $data['type'],
            'user_id'         => Auth::id(),
            'assets_file_id'  => $asset?->id,
            'company_unit_id' => $data['company_unit_id'] ?? null,
            'is_active'       => $data['is_active'] ?? false,
            'expired_at'      => $data['expired_at'] ?? null,
        ]);

        // Load author relationship
        $content = $content->load('author');

        // Send notification to users in the company unit or all users if not specified
        if ($data['company_unit_id'] ?? null) {
            // Notify users in the specified company unit
            $companyUnit = CompanyUnit::findOrFail($data['company_unit_id']);
            $users = User::whereHas('person', function ($query) use ($companyUnit) {
                $query->where('company_unit_id', $companyUnit->id);
            })->where('id', '!=', Auth::id())->get();
        } else {
            // Notify all active users (excluding the creator)
            $users = User::where('id', '!=', Auth::id())
                ->whereHas('person', function ($query) {
                    $query->where('status', 1); // active employees
                })->get();
        }

        // Dispatch notifications
        foreach ($users as $user) {
            $user->notifyNow(new ContentCreatedNotification($content));
        }

        return $content;
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    */

    public function update(array $data, int $id)
    {
        $content = Content::with('asset')->findOrFail($id);
        $asset = $content->asset;

        $hasNewMedia =
            !empty($data['video']) ||
            !empty($data['image']) ||
            !empty($data['document']);

        if ($hasNewMedia) {

            // ✅ Toujours utiliser le BON modèle
            if (!$asset) {
                $asset = new AssetsFile();
            }

            // reset
            $asset->video = null;
            $asset->image = null;
            $asset->document = null;

            if (!empty($data['video'])) {
                $asset = $this->assetFileService->saveVideo($data['video'], $asset);
            } elseif (!empty($data['image'])) {
                $asset = $this->assetFileService->saveImage($data['image'], $asset);
            } elseif (!empty($data['document'])) {
                $asset = $this->assetFileService->saveDocument($data['document'], $asset);
            }

            // ⚠️ inutile si déjà update dans service, mais safe
            if (!$asset->exists) {
                $asset->save();
            }
        }

      $content->update([
    'title'           => $data['title'] ?? $content->title,
    'description'     => $data['description'] ?? $content->description,
    'summary'         => $data['summary'] ?? $content->summary,
    'icon'            => $data['icon'] ?? $content->icon,

    'event_start'     => $data['event_start'] ?? $content->event_start,
    'event_end'       => $data['event_end'] ?? $content->event_end,
    'event_date'      => $data['event_date'] ?? $content->event_date,
    'expired_at'      => $data['expired_at'] ?? $content->expired_at,

    'company_unit_id' => $data['company_unit_id'] ?? $content->company_unit_id,
    'is_active'       => $data['is_active'] ?? $content->is_active,
    'meta_data'       => $data['meta_data'] ?? $content->meta_data,

    'assets_file_id'  => $hasNewMedia
        ? $asset->id
        : $content->assets_file_id,
]);

        return $content;
    }
}
