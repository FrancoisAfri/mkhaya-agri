<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Lightweight location lookups for dependent dropdowns — returns the cities that
 * belong to a country so the City picker can filter by the selected Country.
 */
class CrmLocationController extends Controller
{
    public function cities(Request $request): JsonResponse
    {
        $countryId = (int) $request->query('country_id');
        if (! $countryId) {
            return response()->json([]);
        }

        $cities = DB::table('cities')
            ->where('country_id', $countryId)
            ->orderBy('name')
            ->pluck('name');

        return response()->json($cities);
    }
}
