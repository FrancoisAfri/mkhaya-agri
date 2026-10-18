<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every QR scan at every gate (CON-23).
 *
 * A screen of its own because of what it shows that nothing else could: the scans that matched
 * nobody. Those left no trace at all before this — `verify()` returned "not found" before
 * reaching anything that writes — and they are the ones worth looking at. A code that resolves to
 * nothing is a card replaced after a loss, a card from a different site, a photograph of somebody
 * else's pass forwarded on WhatsApp, or somebody at a boom trying codes to see what opens.
 *
 * So unknown codes are the first thing on the page, grouped by the code rather than listed
 * individually: eleven scans of one unknown card is a lost card, and eleven scans of eleven
 * different ones is somebody testing the gate. Listed flat, those look identical.
 */
class PassScanController extends Controller
{
    use ResolvesCompany;

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        $days = max(1, min(90, (int) $request->query('days', 7)));
        $since = Carbon::now()->subDays($days);

        return Inertia::render('Modules/Sheq/Registers/PassScans', [
            'noCompany' => $companyId === null,
            'days' => $days,
            'scans' => $companyId === null ? [] : $this->scans($companyId, $since),
            'counts' => $companyId === null ? [] : $this->counts($companyId, $since),
            'unknownCodes' => $companyId === null ? [] : $this->unknownCodes($companyId, $since),
            'sites' => $companyId === null ? [] : DB::table('sheq_sites')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->orderBy('name')->get(['id', 'name'])->map(fn ($s) => (array) $s)->all(),
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function scans(int $companyId, Carbon $since, int $limit = 400): array
    {
        return DB::table('sheq_pass_scans as s')
            ->leftJoin('sheq_sites as site', 'site.id', '=', 's.site_id')
            ->leftJoin('sheq_contractors as c', 'c.id', '=', 's.contractor_id')
            ->leftJoin('users as u', 'u.id', '=', 's.scanned_by')
            ->where('s.company_id', $companyId)->where('s.scanned_at', '>=', $since)
            ->orderByDesc('s.scanned_at')->limit($limit)
            ->get(['s.uuid', 's.outcome', 's.person_name', 's.subject_type', 's.activity',
                's.device_ref', 's.ip_address', 's.scanned_at', 's.reasons', 's.codes',
                's.token_hash', 'site.name as site_name', 'c.name as contractor_name',
                'u.email as scanned_by'])
            ->map(fn ($row) => array_merge((array) $row, [
                'reasons' => json_decode((string) $row->reasons, true) ?: [],
                'codes' => json_decode((string) $row->codes, true) ?: [],
                // The first eight characters are enough to recognise the same code twice and
                // useless for opening anything.
                'token_ref' => substr((string) $row->token_hash, 0, 8),
                'token_hash' => null,
            ]))->all();
    }

    /** @return array<string,int> */
    private function counts(int $companyId, Carbon $since): array
    {
        $rows = DB::table('sheq_pass_scans')
            ->where('company_id', $companyId)->where('scanned_at', '>=', $since)
            ->selectRaw('outcome, count(*) as n')->groupBy('outcome')->pluck('n', 'outcome');

        return [
            'total' => (int) $rows->sum(),
            'allowed' => (int) ($rows['ALLOWED'] ?? 0),
            'denied' => (int) ($rows['DENIED'] ?? 0),
            'unknown_pass' => (int) ($rows['UNKNOWN_PASS'] ?? 0),
            'review' => (int) ($rows['REVIEW_REQUIRED'] ?? 0) + (int) ($rows['UNKNOWN'] ?? 0),
        ];
    }

    /**
     * Codes that matched nobody, grouped by the code.
     *
     * The grouping is the whole point. One unknown code scanned eleven times is a card somebody
     * replaced and nobody collected; eleven different unknown codes is somebody standing at the
     * boom trying things. A flat list cannot tell those apart, and they call for entirely
     * different responses.
     *
     * @return list<array<string,mixed>>
     */
    private function unknownCodes(int $companyId, Carbon $since, int $limit = 50): array
    {
        return DB::table('sheq_pass_scans as s')
            ->leftJoin('sheq_sites as site', 'site.id', '=', 's.site_id')
            ->where('s.company_id', $companyId)->where('s.outcome', 'UNKNOWN_PASS')
            ->where('s.scanned_at', '>=', $since)
            ->groupBy('s.token_hash', 'site.name')
            ->orderByRaw('count(*) desc')
            ->limit($limit)
            ->selectRaw('s.token_hash, site.name as site_name, count(*) as attempts,
                min(s.scanned_at) as first_seen, max(s.scanned_at) as last_seen')
            ->get()
            ->map(fn ($row) => [
                'token_ref' => substr((string) $row->token_hash, 0, 8),
                'site_name' => $row->site_name,
                'attempts' => (int) $row->attempts,
                'first_seen' => (string) $row->first_seen,
                'last_seen' => (string) $row->last_seen,
            ])->all();
    }
}
