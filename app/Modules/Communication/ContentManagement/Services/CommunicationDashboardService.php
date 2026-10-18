<?php

namespace App\Modules\Communication\ContentManagement\Services;
use App\Modules\Communication\ContentManagement\Models\Content;
use App\Modules\Communication\ContentManagement\Models\CeoMessage;
use App\Modules\Communication\ContentManagement\Models\EmployeeSpotlight;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CommunicationDashboardService
{
    private const CONTENT_TYPES = [
        1 => 'Announcement',
        2 => 'News',
        3 => 'Event',
        4 => 'Internal Notice',
    ];

    private const SPOTLIGHT_TYPES = [
        1 => 'Award',
        2 => 'Anniversary',
        3 => 'Competition',
    ];

    /*
    |--------------------------------------------------------------------------
    | MAIN ENTRY POINT
    |--------------------------------------------------------------------------
    */

    public function getDashboardData(): array
    {
        return [
            'contentStats'   => $this->contentStats(),
            'spotlightStats' => $this->spotlightStats(),
            'monthlyTrends'  => $this->monthlyTrends(),
            'topAuthors'     => $this->topAuthors(),
            'ceoMessageStat' => $this->ceoMessageStat(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | CONTENT STATS
    | PostgreSQL fixes:
    |   - is_active is boolean  → compare with TRUE/FALSE
    |   - dates must be quoted  → use bindings properly
    |--------------------------------------------------------------------------
    */

    private function contentStats(): array
    {
        $thisMonth = Carbon::now()->startOfMonth()->toDateTimeString();

        $rows = Content::withoutTrashed()
            ->selectRaw(
                '"type",
                COUNT(*) as total,
                SUM(CASE WHEN is_active = TRUE  THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN is_active = FALSE THEN 1 ELSE 0 END) as inactive,
                SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as this_month',
                [$thisMonth]
            )
            ->groupBy('type')
            ->get()
            ->keyBy('type');

        return collect(self::CONTENT_TYPES)
            ->map(function (string $label, int $type) use ($rows) {
                $row = $rows->get($type);
                return [
                    'type'       => $type,
                    'label'      => $label,
                    'total'      => (int) ($row?->total      ?? 0),
                    'active'     => (int) ($row?->active     ?? 0),
                    'inactive'   => (int) ($row?->inactive   ?? 0),
                    'this_month' => (int) ($row?->this_month ?? 0),
                ];
            })
            ->values()
            ->toArray();
    }

    /*
    |--------------------------------------------------------------------------
    | SPOTLIGHT STATS
    |--------------------------------------------------------------------------
    */

    private function spotlightStats(): array
    {
        $thisMonth = Carbon::now()->startOfMonth()->toDateTimeString();

        $rows = EmployeeSpotlight::withoutTrashed()
            ->selectRaw(
                '"type",
                COUNT(*) as total,
                SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as this_month',
                [$thisMonth]
            )
            ->groupBy('type')
            ->get()
            ->keyBy('type');

        return collect(self::SPOTLIGHT_TYPES)
            ->map(function (string $label, int $type) use ($rows) {
                $row = $rows->get($type);
                return [
                    'type'       => $type,
                    'label'      => $label,
                    'total'      => (int) ($row?->total      ?? 0),
                    'this_month' => (int) ($row?->this_month ?? 0),
                ];
            })
            ->values()
            ->toArray();
    }

    /*
    |--------------------------------------------------------------------------
    | MONTHLY TRENDS — 6 derniers mois
    | On fait une requête par mois (simple et compatible PG)
    |--------------------------------------------------------------------------
    */

    private function monthlyTrends(): array
    {
        return collect(range(5, 0))
            ->map(function (int $offset) {
                $date  = Carbon::now()->subMonths($offset);
                $start = $date->copy()->startOfMonth()->toDateTimeString();
                $end   = $date->copy()->endOfMonth()->toDateTimeString();

                // Content counts grouped by type for this month
                $content = Content::withoutTrashed()
                    ->whereBetween('created_at', [$start, $end])
                    ->selectRaw('"type", COUNT(*) as count')
                    ->groupBy('type')
                    ->pluck('count', 'type');

                $spotlights = EmployeeSpotlight::withoutTrashed()
                    ->whereBetween('created_at', [$start, $end])
                    ->count();

                return [
                    'month'             => $date->format('M Y'),
                    'news'              => (int) ($content->get(2) ?? 0),
                    'events'            => (int) ($content->get(3) ?? 0),
                    'announcements'     => (int) ($content->get(1) ?? 0),
                    'internal_notices'  => (int) ($content->get(4) ?? 0),
                    'spotlights'        => $spotlights,
                ];
            })
            ->toArray();
    }

    /*
    |--------------------------------------------------------------------------
    | TOP AUTHORS
    | PostgreSQL: CONCAT works, but we use COALESCE + || operator to be safe
    |--------------------------------------------------------------------------
    */

    private function topAuthors(): array
    {
        return Content::withoutTrashed()
            ->join('users', 'contents.user_id', '=', 'users.id')
            ->leftJoin('hr_people', 'users.id', '=', 'hr_people.user_id')
            ->selectRaw(
                'users.id,
                COALESCE(
                    NULLIF(TRIM(COALESCE(hr_people.first_name, \'\') || \' \' || COALESCE(hr_people.surname, \'\')), \' \'),
                    users.email
                ) as name,
                COUNT(contents.id) as count'
            )
            ->groupBy('users.id', 'hr_people.first_name', 'hr_people.surname', 'users.email')
            ->orderByDesc('count')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'name'  => trim($row->name),
                'count' => (int) $row->count,
            ])
            ->toArray();
    }

    /*
    |--------------------------------------------------------------------------
    | CEO MESSAGE STAT
    |--------------------------------------------------------------------------
    */

    private function ceoMessageStat(): array
    {
        $total  = CeoMessage::count();
        // $active = CeoMessage::where('is_active', true)->count();
        $last   = CeoMessage::latest('updated_at')->value('updated_at');

        return [
            'total'        => $total,
            // 'active'       => $active,
            'last_updated' => $last?->toDateTimeString(),
        ];
    }
}
