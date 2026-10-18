<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Services\ContractorService;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every version of every document a contractor has filed (CON-24).
 *
 * The chain was being recorded and could not be read. Each renewal wrote its version number and
 * a pointer back to what it replaced, and no screen in the module showed any of it — the
 * contractor profile lists the live document and nothing else, so "what did they have on file in
 * March" was a question with an answer sitting in the database and no way to ask it.
 *
 * Two things this page does that a list of documents cannot.
 *
 * It shows the chain: which version replaced which, when, and why. A certificate set aside for a
 * mistyped expiry and one revoked by its issuer look identical in a flat list and are completely
 * different facts.
 *
 * And it answers as at a date. Pick a day and the page shows what was in force THAT day, with
 * expiry judged as at that day too — because a certificate that expired last week was perfectly
 * valid in March, and a history that marks it expired makes the past look worse than it was.
 */
class DocumentVersionController extends Controller
{
    use ResolvesCompany;

    public function __construct(private readonly ContractorService $contractors) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        $contractors = $companyId === null ? [] : DB::table('sheq_contractors')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->orderBy('name')->get(['id', 'uuid', 'name'])->map(fn ($c) => (array) $c)->all();

        $selected = $this->selected($request, $contractors);

        $asAt = $this->asAt($request);

        return Inertia::render('Modules/Sheq/Registers/DocumentVersions', [
            'noCompany' => $companyId === null,
            'contractors' => array_map(
                fn (array $c) => ['uuid' => $c['uuid'], 'name' => $c['name']], $contractors),
            'selected' => $selected === null ? null
                : ['uuid' => $selected['uuid'], 'name' => $selected['name']],
            'versions' => $selected === null || $companyId === null ? []
                : $this->contractors->documentVersions($companyId, (int) $selected['id']),
            'asAt' => $asAt->toDateString(),
            'inForceThen' => $selected === null || $companyId === null ? []
                : $this->contractors->documentsAsAt($companyId, (int) $selected['id'], $asAt),
        ]);
    }

    private function asAt(Request $request): Carbon
    {
        $value = (string) $request->query('as_at', '');

        try {
            // End of the chosen day, because "what was on file on the 3rd" means at any point
            // during the 3rd, and midnight would exclude everything filed that morning.
            return $value === '' ? Carbon::now() : Carbon::parse($value)->endOfDay();
        } catch (\Throwable) {
            return Carbon::now();
        }
    }

    /** @param  list<array<string,mixed>>  $contractors */
    private function selected(Request $request, array $contractors): ?array
    {
        $uuid = $request->query('contractor');

        foreach ($contractors as $contractor) {
            if ($contractor['uuid'] === $uuid) {
                return $contractor;
            }
        }

        return $contractors[0] ?? null;
    }
}
