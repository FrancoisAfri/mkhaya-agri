<?php

namespace App\Modules\Communication\Resources\Services;
use App\Core\CompanyUnits\Models\CompanyUnit;
use App\Modules\Communication\Resources\Models\Resource;
use App\Modules\Communication\Resources\Models\CorporateBranding;

class ResourceReportService
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

    /*
    |--------------------------------------------------------------------------
    | MAIN
    |--------------------------------------------------------------------------
    */

    public function getReportsData(): array
    {
        return [
            'resourceReport'  => $this->resourceReport(),
            'brandingReport'  => $this->brandingReport(),
            'divisions'       => $this->getUnits('division'),
            'departments'     => $this->getUnits('department'),
            'sections'        => $this->getUnits('section'),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | RESOURCE REPORT — all 8 types
    |--------------------------------------------------------------------------
    */

    private function resourceReport(): array
    {
        return Resource::withoutTrashed()
            ->with(['author.person', 'companyUnit'])
            ->orderByDesc('created_at')
            ->get()
            ->map(function (Resource $r) {
                $author = $r->author;
                $person = $author?->person;
                $name   = $person
                    ? trim(($person->first_name ?? '') . ' ' . ($person->surname ?? ''))
                    : ($author?->email ?? '—');

                return [
                    'title'        => $r->title          ?? '—',
                    'type'         => self::TYPES[$r->type] ?? 'Unknown',
                    'author'       => $name ?: ($author?->email ?? '—'),
                    'unit'         => $r->companyUnit?->name ?? '—',
                    'access'       => $r->internal_only ? 'Internal' : 'Public',
                    'status'       => $r->is_active ? 'Active' : 'Inactive',
                    'published_at' => $r->published_at?->toDateTimeString() ?? '—',
                    'expired_at'   => $r->expired_at?->toDateTimeString()   ?? '—',
                    'created_at'   => $r->created_at->toDateTimeString(),
                ];
            })
            ->toArray();
    }

    /*
    |--------------------------------------------------------------------------
    | BRANDING REPORT
    |--------------------------------------------------------------------------
    */

    private function brandingReport(): array
    {
        return CorporateBranding::withoutTrashed()
            ->with(['author.person', 'companyUnit'])
            ->orderByDesc('updated_at')
            ->get()
            ->map(function (CorporateBranding $b) {
                $author = $b->author;
                $person = $author?->person;
                $name   = $person
                    ? trim(($person->first_name ?? '') . ' ' . ($person->surname ?? ''))
                    : ($author?->email ?? '—');

                return [
                    'name'          => $b->name          ?? '—',
                    'unit'          => $b->companyUnit?->name ?? '—',
                    'author'        => $name ?: ($author?->email ?? '—'),
                    'primary_color' => $b->primary_color  ?? '—',
                    'font_family'   => $b->font_family     ?? '—',
                    'logo_light'    => $b->logo_light_id   ? 'Set' : 'Missing',
                    'logo_dark'     => $b->logo_dark_id    ? 'Set' : 'Missing',
                    'favicon'       => $b->favicon_id      ? 'Set' : 'Missing',
                    'status'        => $b->is_active ? 'Active' : 'Inactive',
                    'updated_at'    => $b->updated_at->toDateTimeString(),
                    'created_at'    => $b->created_at->toDateTimeString(),
                ];
            })
            ->toArray();
    }

    /*
    |--------------------------------------------------------------------------
    | ORG UNITS
    |--------------------------------------------------------------------------
    */

    private function getUnits(string $type): array
    {
        return CompanyUnit::where('type', $type)
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id'])
            ->map(fn ($u) => [
                'id'        => $u->id,
                'name'      => $u->name,
                'parent_id' => $u->parent_id,
            ])
            ->toArray();
    }
}
