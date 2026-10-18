<?php

namespace App\Modules\HR\Holidays\Controller;

use App\Core\User\Models\Country;
use App\Core\User\Models\PublicHoliday;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PublicHolidatsController extends Controller
{
    private function normalizeDay($day): string
    {
        if (is_numeric($day)) {
            return Carbon::createFromTimestamp((int) $day)->format('m-d');
        }
        // Full date string like "2025-12-25"
        if (strlen((string) $day) > 5) {
            return Carbon::parse($day)->format('m-d');
        }
        return (string) $day; // Already MM-DD
    }

    public function index()
    {
        $currentYear = now()->year;

        $allHolidays = PublicHoliday::orderBy('day')->get()->map(function ($h) use ($currentYear) {
            $mmdd = $this->normalizeDay($h->day);
            $year = $h->year ?: null;
            $displayYear = $year ?? $currentYear;

            return [
                'id'         => $h->id,
                'name'       => $h->name,
                'day'        => $mmdd,
                'year'       => $year,
                'country_id' => $h->country_id,
                'start'      => $displayYear . '-' . $mmdd,
            ];
        });

        return Inertia::render('Core/Holidays/Index', [
            'allHolidays' => $allHolidays,
            'countries'   => Country::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'       => 'required|string|max:255',
            'day'        => ['required', 'string', 'regex:/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/'],
            'year'       => 'nullable|integer|min:2000|max:2100',
            'country_id' => 'required|integer|exists:countries,id',
        ]);

        $holiday = PublicHoliday::create([
            'name'       => $request->name,
            'day'        => $request->day,
            'year'       => $request->year,
            'country_id' => $request->country_id,
        ]);

        return redirect()->back()->with('success', "Holiday \"{$holiday->name}\" created.");
    }

    public function update(Request $request, $id)
    {
        $holiday = PublicHoliday::findOrFail($id);
        $this->guardSharedHoliday($holiday);

        $request->validate([
            'name'       => 'required|string|max:255',
            'day'        => ['required', 'string', 'regex:/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/'],
            'year'       => 'nullable|integer|min:2000|max:2100',
            'country_id' => 'required|integer|exists:countries,id',
        ]);

        $holiday->update([
            'name'       => $request->name,
            'day'        => $request->day,
            'year'       => $request->year,
            'country_id' => $request->country_id,
        ]);

        return redirect()->back()->with('success', "Holiday \"{$holiday->name}\" updated.");
    }

    public function destroy($id)
    {
        $holiday = PublicHoliday::findOrFail($id);
        $this->guardSharedHoliday($holiday);
        $name    = $holiday->name;
        $holiday->delete();

        return redirect()->back()->with('success', "Holiday \"{$name}\" deleted.");
    }

    /**
     * A tenant may only edit/delete its own holidays — never the shared global
     * defaults (company_id NULL). Platform admins (no active company) may edit them.
     */
    private function guardSharedHoliday(PublicHoliday $holiday): void
    {
        $current = app(\App\Modules\Tenancy\Services\CurrentCompany::class);

        abort_if(
            $current->isSet() && $holiday->company_id === null,
            403,
            'Shared public holidays cannot be modified by a tenant. Add your own holiday instead.'
        );
    }
}
