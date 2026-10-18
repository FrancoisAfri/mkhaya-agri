<?php


namespace App\Modules\Communication\Resources\Services;
use App\Modules\Communication\Resources\Models\Resource;
use App\Modules\Communication\Resources\Models\CorporateBranding;
use Carbon\Carbon;

class ResourceDashboardService
{
    private const TYPES = [
        1 => 'Forms',
        2 => 'Legal Documents',
        3 => 'Employee Handbook',
        4 => 'Health & Safety',
        5 => 'Templates',
        6 => 'Training Materials',
        7 => 'SOPs',
        8 => 'Downloads',
    ];

    public function getDashboardData(): array
    {
        return [
            'typeStats'      => $this->typeStats(),
            'monthlyTrends'  => $this->monthlyTrends(),
            'topUploaders'   => $this->topUploaders(),
            'brandingCount'  => CorporateBranding::count(),
            'totalResources' => Resource::withoutTrashed()->count(),
            'totalActive'    => Resource::withoutTrashed()->where('is_active', true)->count(),
        ];
    }

    private function typeStats(): array
    {
        $thisMonth = Carbon::now()->startOfMonth()->toDateTimeString();

        $rows = Resource::withoutTrashed()
            ->selectRaw(
                '"type",
                COUNT(*) as total,
                SUM(CASE WHEN is_active = TRUE  THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN created_at >= ?    THEN 1 ELSE 0 END) as this_month',
                [$thisMonth]
            )
            ->groupBy('type')
            ->get()
            ->keyBy('type');

        return collect(self::TYPES)
            ->map(function (string $label, int $type) use ($rows) {
                $row = $rows->get($type);
                return [
                    'type'       => $type,
                    'label'      => $label,
                    'total'      => (int) ($row?->total      ?? 0),
                    'active'     => (int) ($row?->active     ?? 0),
                    'this_month' => (int) ($row?->this_month ?? 0),
                ];
            })
            ->values()
            ->toArray();
    }

    private function monthlyTrends(): array
    {
        return collect(range(5, 0))
            ->map(function (int $offset) {
                $date  = Carbon::now()->subMonths($offset);
                $start = $date->copy()->startOfMonth()->toDateTimeString();
                $end   = $date->copy()->endOfMonth()->toDateTimeString();

                $counts = Resource::withoutTrashed()
                    ->whereBetween('created_at', [$start, $end])
                    ->selectRaw('"type", COUNT(*) as count')
                    ->groupBy('type')
                    ->pluck('count', 'type');

                $internal = Resource::withoutTrashed()
                    ->whereBetween('created_at', [$start, $end])
                    ->where('internal_only', true)
                    ->count();

                $total = $counts->sum();

                return [
                    'month'    => $date->format('M Y'),
                    'total'    => $total,
                    'internal' => $internal,
                    'public'   => $total - $internal,
                ];
            })
            ->toArray();
    }

    private function topUploaders(): array
    {
        return Resource::withoutTrashed()
            ->join('users', 'resources.user_id', '=', 'users.id')
            ->leftJoin('hr_people', 'users.id', '=', 'hr_people.user_id')
            ->selectRaw(
                "users.id,
                COALESCE(
                    NULLIF(TRIM(COALESCE(hr_people.first_name,'') || ' ' || COALESCE(hr_people.surname,'')), ' '),
                    users.email
                ) as name,
                COUNT(resources.id) as count"
            )
            ->groupBy('users.id', 'hr_people.first_name', 'hr_people.surname', 'users.email')
            ->orderByDesc('count')
            ->limit(5)
            ->get()
            ->map(fn($r) => ['name' => trim($r->name), 'count' => (int) $r->count])
            ->toArray();
    }
}
