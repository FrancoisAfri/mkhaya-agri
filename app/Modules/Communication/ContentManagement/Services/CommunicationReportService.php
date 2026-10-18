<?php
namespace App\Modules\Communication\ContentManagement\Services;


use App\Core\CompanyUnits\Models\CompanyUnit;
use App\Modules\Communication\ContentManagement\Models\Content;
use App\Modules\Communication\ContentManagement\Models\CeoMessage;
use App\Modules\Communication\ContentManagement\Models\EmployeeSpotlight;
use Illuminate\Support\Facades\DB;

class CommunicationReportService
{
    /*
    |--------------------------------------------------------------------------
    | MAIN — appelé par le controller
    |--------------------------------------------------------------------------
    */

    public function getReportsData(): array
    {
        return [
            'contentReport'    => $this->contentReport(),
            'spotlightReport'  => $this->spotlightReport(),
            'ceoMessageReport' => $this->ceoMessageReport(),
            'divisions'        => $this->getUnits('division'),
            'departments'      => $this->getUnits('department'),
            'sections'         => $this->getUnits('section'),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | CONTENT REPORT — News, Events, Announcements, Internal Notices
    |--------------------------------------------------------------------------
    */

    private function contentReport(): array
    {
        return Content::withoutTrashed()
            ->with(['author.person', 'companyUnit'])
            ->orderByDesc('created_at')
            ->get()
            ->map(function (Content $c) {
                $typeLabel = match ($c->type) {
                    1 => 'Announcement',
                    2 => 'News',
                    3 => 'Event',
                    4 => 'Internal Notice',
                    default => 'Unknown',
                };

                $author = $c->author;
                $person = $author?->person;
                $name   = $person
                    ? trim(($person->first_name ?? '') . ' ' . ($person->surname ?? ''))
                    : ($author?->email ?? '—');

                return [
                    'title'        => $c->title         ?? '—',
                    'type'         => $typeLabel,
                    'author'       => $name              ?: ($author?->email ?? '—'),
                    'unit'         => $c->companyUnit?->name ?? '—',
                    'status'       => $c->is_active ? 'Active' : 'Inactive',
                    'event_date'   => $c->event_date?->toDateString()    ?? '—',
                    'expired_at'   => $c->expired_at?->toDateTimeString() ?? '—',
                    'created_at'   => $c->created_at->toDateTimeString(),
                ];
            })
            ->toArray();
    }

    /*
    |--------------------------------------------------------------------------
    | SPOTLIGHT REPORT
    |--------------------------------------------------------------------------
    */

    private function spotlightReport(): array
    {
        return EmployeeSpotlight::withoutTrashed()
            ->with(['employee.person', 'companyUnit'])
            ->orderByDesc('created_at')
            ->get()
            ->map(function (EmployeeSpotlight $s) {
                $typeLabel = match ($s->type) {
                    1 => 'Award',
                    2 => 'Anniversary',
                    3 => 'Competition',
                    default => 'Unknown',
                };

                $emp    = $s->employee;
                $person = $emp?->person;
                $name   = $person
                    ? trim(($person->first_name ?? '') . ' ' . ($person->surname ?? ''))
                    : ($emp?->email ?? '—');

                return [
                    'title'       => $s->title           ?? '—',
                    'type'        => $typeLabel,
                    'employee'    => $name                ?: ($emp?->email ?? '—'),
                    'unit'        => $s->companyUnit?->name ?? '—',
                    'month'       => $s->month            ?? '—',
                    'description' => $s->description      ?? '—',
                    'created_at'  => $s->created_at->toDateTimeString(),
                ];
            })
            ->toArray();
    }

    /*
    |--------------------------------------------------------------------------
    | CEO MESSAGE REPORT
    |--------------------------------------------------------------------------
    */

    private function ceoMessageReport(): array
    {
        return CeoMessage::orderByDesc('updated_at')
            ->get()
            ->map(function (CeoMessage $m) {
                return [
                    'title'      => $m->title       ?? '—',
                    'status'     => $m->is_active ? 'Active' : 'Inactive',
                    'created_at' => $m->created_at->toDateTimeString(),
                    'updated_at' => $m->updated_at->toDateTimeString(),
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
            ->map(fn($u) => [
                'id'        => $u->id,
                'name'      => $u->name,
                'parent_id' => $u->parent_id,
            ])
            ->toArray();
    }
}
