<?php

namespace App\Modules\Agri\Http\Middleware;

use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Support\FarmTime;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * A date and time typed on a farm screen ("2026-10-21T06:00") is the farm's clock. Before the
 * request reaches a controller, every such value — a date with a time and no zone — becomes the
 * UTC moment it is, with its offset written in, so validation, services and the database all
 * read it the same way. Dates alone, and times that already carry a zone (a phone's clock), are
 * left as they are.
 *
 * The farm is the one the request names (farm_id), else the one its record belongs to, else the
 * zone most of the organisation's farms share.
 */
class FarmTimeInput
{
    private const NAIVE = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?$/';

    public function handle(Request $request, Closure $next): Response
    {
        $zone = $this->zoneFor($request);
        FarmTime::useZone($zone);
        if ($zone !== 'UTC' && $request->isMethod('GET') === false) {
            $request->merge($this->convert($request->all(), $zone));
        }

        try {
            return $next($request);
        } finally {
            FarmTime::useZone(null);
        }
    }

    private function zoneFor(Request $request): string
    {
        $farm = $request->input('farm_id') ?? $request->query('farm');
        if (is_string($farm) && $farm !== '' && ($tz = Farm::withoutGlobalScopes()->where('uuid', $farm)->value('timezone'))) {
            return FarmTime::zone(Farm::withoutGlobalScopes()->where('uuid', $farm)->value('id'));
        }
        foreach ($request->route()?->parameters() ?? [] as $p) {
            if ($p instanceof Farm) {
                return FarmTime::zone($p);
            }
            if ($p instanceof Model && $p->getAttribute('farm_id')) {
                return FarmTime::zone((int) $p->getAttribute('farm_id'));
            }
        }

        return FarmTime::companyZone();
    }

    private function convert(array $input, string $zone): array
    {
        foreach ($input as $k => $v) {
            if (is_array($v)) {
                $input[$k] = $this->convert($v, $zone);
            } elseif (is_string($v) && preg_match(self::NAIVE, $v)) {
                $input[$k] = Carbon::parse($v, $zone)->utc()->toIso8601String();
            }
        }

        return $input;
    }
}
